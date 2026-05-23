# PERF-02 — N+1 HTTP KLASSCI → Memoization + Cache user-token + Batch helper

> Issue GitHub : [#137 PERF-02 — N+1 HTTP KLASSCI → batch + caching](https://github.com/ouedraogoissouf2012/lms_backend/issues/137) *(à créer)*
>
> Source : `REFACTORING_ROADMAP.md` TIER 1 — PERF-02. Suit l'ordre officiel après PERF-01 (LMSDataController split, terminé via PRs A→J).

## Contexte

`KlassciProxyService` est la **seule** porte d'entrée vers l'API KLASSCI. Deux modes de communication coexistent :

1. **`get()` / `post()` / `put()` / `delete()`** — passent par `Cache::remember()` (TTL 5-60min)
2. **`requestWithUserToken()`** — utilisé quand l'appel doit porter le **token KLASSCI personnel** de l'utilisateur connecté (auth/me, evaluations, matieres enrichies). **Aucun cache**, aucune memoization.

### Le problème — 7 sites N+1 HTTP confirmés

```
PATTERN A : foreach matieres → matieres/{id}   (3 sites)
PATTERN B : map evaluations → matieres/{id} + classes/{id}   (1 site)
PATTERN C : foreach classe_ids → classes/{id}   (1 site)
PATTERN D : foreach evaluations → getClasseEtudiants   (1 site)
PATTERN E : foreach matieres → matieres/{id}  (jobs async, moins critique)   (2 sites)
```

| # | Site | Pattern | Multiplicateur typique |
|---|------|---------|------------------------|
| 1 | `LMSMatieresController.php:535-572` | A | × N matières (10-30) |
| 2 | `LMSSeancesController.php:1301-1309` | A | × N matières |
| 3 | `SeanceQueryService.php:269-280` | A | × N matières |
| 4 | `EvaluationController.php:540-572` | B | × 2N évaluations LMS pures |
| 5 | `LessonController.php:328-329` | C | × N classes par matière |
| 6 | `NotifyUpcomingEvaluations.php:48` | D | × N évaluations à notifier |
| 7 | `ClasseSyncService.php:148-169` + `SyncKlassciSeances.php:55-63` | E (jobs async) | × N matières |

### Cause racine architecturale

```
KlassciProxyService::get()                    → Cache::remember() ✓
KlassciProxyService::requestWithUserToken()   → Cache::remember() ✗ (BYPASS)
```

Les 7 callsites N+1 utilisent **TOUS** `requestWithUserToken()` parce que chaque utilisateur a son **propre token KLASSCI** (auth/me retourne un dashboard personnalisé). Le cache global `get()` ne peut pas servir ces requêtes (clé tenant-only — pollution cross-user).

À grande échelle (200k+ users actifs concurrents, 10-50 appels HTTP par requête HTTP serveur) :
- Wall-clock time gonflé par les requêtes séquentielles vers KLASSCI
- Charge inutile sur le backend KLASSCI (mêmes ressources demandées N fois dans une seule requête)
- Latence client × N pour les pages enrichies (LMSMatieres, LMSSeances)

## Solution architecturale unique (§6 — meilleure solution, pas la plus rapide)

**4 couches indépendantes, complémentaires, chacune testable seule.**

### Couche 1 — Memoization intra-request (résout duplicates dans 1 requête HTTP serveur)

Tableau privé `array $requestMemo` dans `KlassciProxyService`, indexé par hash de `(method, endpoint, params, tokenHash)`. Vidé en fin de cycle requête (le service est en scope `singleton` par défaut Laravel — réinitialisé à chaque requête HTTP).

**Effet** : si la même requête HTTP serveur appelle 30× `matieres/{id}` avec le même token, seuls les IDs distincts touchent le réseau. Si certains IDs apparaissent plusieurs fois dans la requête, ils sont memoizés.

### Couche 2 — Cache distribué user-token-aware sur `requestWithUserToken` (GET seulement)

Étendre `Cache::remember()` aux GET avec user-token. Clé : `klassci_{tenant}_{tokenHash}_{endpoint}_{paramsHash}_{invalidatedAt}`.

**TTL** : configurable par appel (`?int $customTTL`), défaut 300s. Logique d'invalidation alignée sur le mécanisme `invalidateCache()` existant.

**Sécurité** : `tokenHash` empêche la fuite cross-user. Le `invalidatedAt` timestamp permet l'invalidation soft sur POST/PUT/DELETE (pattern déjà utilisé pour les méthodes globales).

**Effet** : entre requêtes HTTP serveur dans la fenêtre TTL, les mêmes ressources ne retouchent pas KLASSCI.

### Couche 3 — Helper batch parallélisé `fetchManyByEndpoint(array $ids, string $endpointPattern, string $token, ?int $ttl)`

Utilise `Illuminate\Support\Facades\Http::pool()` pour paralléliser N requêtes en pools de **4 requêtes concurrentes** (configurable via `services.klassci.pool_size`).

**Effet** : N requêtes séquentielles de 100ms chacune → N/4 batches de 100ms wall-clock. Pour N=20 matières : 2000ms → 500ms (× 4).

**Intégration** : `fetchManyByEndpoint` invoque les couches 1+2 avant de toucher le réseau (cache-hit court-circuite le pool).

### Couche 4 — Refactor des 7 callsites pour utiliser le batch helper

Remplacer chaque `foreach { ... requestWithUserToken(...) }` par un appel unique à `fetchManyByEndpoint()`. Préserver la sémantique de réponse (la structure retournée par chaque caller reste inchangée pour les consommateurs en aval).

## Découpage en PRs

3 PRs séquentielles indépendantes :

| PR | Scope | Tests | Risque |
|----|-------|-------|--------|
| **PR 1** | Couches 1+2 (memoization + cache user-token-aware) dans `KlassciProxyService` | `KlassciProxyServiceTest` unitaire avec `Http::fake()` (memoization + cache TTL + invalidation) | FAIBLE — pas de changement caller, pur ajout interne |
| **PR 2** | Couche 3 helper `fetchManyByEndpoint()` + `fetchManyMatieresDetails()` + `fetchManyClassesDetails()` | `KlassciProxyServiceBatchTest` unitaire avec `Http::fake()` (pool + cache integration + error retry) | MOYEN — `Http::pool()` peu utilisé dans le projet, retry à valider |
| **PR 3** | Refactor des 7 callsites (1 commit par caller, batch helper) | Tests Feature actualisés pour chaque controller touché | MOYEN — chaque caller a sa structure de réponse propre |

## Requirements (EARS)

### REQ-0 — Split architectural préalable (intégré PR 1 suite audit `spec-architect`)

WHERE `KlassciProxyService` est refactoré,
THE service SHALL être éclaté en **4 collaborateurs DIP-friendly** + 1 orchestrateur fin :

| Classe | Fichier | Responsabilité |
|--------|---------|----------------|
| `KlassciConfigResolver` | `app/Services/Klassci/KlassciConfigResolver.php` | Résolution 3-tiers baseUrl + token système (preserve fix #75) |
| `KlassciHttpClient` | `app/Services/Klassci/KlassciHttpClient.php` | Méthode unique `executeHttp(method, endpoint, data, ?overrideToken)` |
| `KlassciRequestMemo` | `app/Services/Klassci/KlassciRequestMemo.php` | État memoization intra-request (`get`/`put`/`clear`/`size`) |
| `KlassciCacheKeyStrategy` | `app/Services/Klassci/KlassciCacheKeyStrategy.php` | Génération clés + soft-invalidation tenant |
| `KlassciProxyService` (orchestrateur) | `app/Services/KlassciProxyService.php` | Orchestrateur fin, délégation pure aux 4 collaborateurs |

THE orchestrateur SHALL injecter les 4 collaborateurs via **constructor DI pur** (pas de `app(...)` Service Locator, pas de Facade dans le code métier).

THE refactor SHALL préserver **exactement** la sémantique runtime de l'ancien `KlassciProxyService` (les 39 callers existants ne voient aucun changement de comportement observable).

### REQ-1 — Memoization intra-request

WHERE `KlassciRequestMemo` est instancié,
THE collaborateur SHALL maintenir un tableau privé `array $memo` initialisé vide.

WHEN une méthode `get()` ou `requestWithUserToken()` GET est appelée avec `(method, endpoint, params, tokenHash)`,
THE service SHALL :
1. Calculer `memoKey = hash('xxh3', json_encode([$method, $endpoint, $params, $tokenHash]))`
2. Si `isset($this->requestMemo[$memoKey])`, retourner la valeur memoizée **sans aucun appel HTTP ni accès au cache distribué**
3. Sinon, exécuter la logique normale (cache distribué puis HTTP) et stocker le résultat dans `$this->requestMemo[$memoKey]` avant de le retourner

WHEN une méthode `post()`, `put()`, `delete()` ou `requestWithUserToken()` POST/PUT/DELETE est appelée,
THE service SHALL **vider entièrement `$this->requestMemo` après l'invalidation du cache distribué** (raison : un POST peut invalider plusieurs endpoints dont la liste exacte est inconnue ; reset complet pour éviter de servir des valeurs obsolètes dans la même requête HTTP).

### REQ-2 — Cache distribué user-token-aware

WHEN `requestWithUserToken()` est appelée avec `$method = 'GET'`,
THE service SHALL :
1. Calculer `tokenHash = hash('xxh3', $userToken)` (16 chars — empêche la fuite token brut dans les clés cache)
2. Calculer `cacheKey = "klassci_{$tenantKey}_{$tokenHash}_{$endpoint}_{$paramsHash}_{$invalidatedAt}"`
3. Utiliser `Cache::remember($cacheKey, $ttl, fn () => $rawHttpCall())` (où `$ttl` est paramétrable, défaut 300s)
4. Le `tenantKey` réutilise la méthode privée `resolveTenantCacheKey()` existante (slug institution)

WHEN `requestWithUserToken()` est appelée avec `$method` ∈ {`POST`, `PUT`, `DELETE`},
THE service SHALL :
1. **Ne PAS lire le cache** (toujours hit le réseau pour les writes)
2. **Invalider le cache du tenant** via le mécanisme `invalidateCache($endpoint)` existant (timestamp `invalidatedAt`)

THE method `requestWithUserToken()` SHALL gagner un nouveau paramètre optionnel `?int $customTTL = null` pour permettre aux callers de spécifier un TTL par appel (analog à `get()`).

### REQ-3 — Helper batch `fetchManyByEndpoint`

WHERE la couche 3 est implémentée,
THE method publique SHALL avoir la signature :
```php
public function fetchManyByEndpoint(
    array $ids,
    string $endpointPattern,  // ex: "matieres/{id}"
    string $userToken,
    ?int $customTTL = null,
): array  // map [id => responseData], les IDs sans réponse OK sont absents du map
```

WHEN `fetchManyByEndpoint([1, 2, 3], "matieres/{id}", $token)` est appelée,
THE method SHALL :
1. **Filtrer** les IDs déjà présents dans `$this->requestMemo` ou dans le cache distribué → collecter dans `$resolved` immédiatement
2. **Préparer un pool** des IDs restants via `Http::pool(fn ($pool) => array_map(...))` avec un pool size configurable (`config('services.klassci.pool_size', 4)`)
3. Pour chaque réponse OK : stocker dans memo + cache distribué + ajouter à `$resolved`
4. Pour chaque réponse échouée : logger l'erreur (sans `throw`) et OMETTRE l'ID du map de résultat
5. Retourner `$resolved` (map `[id => responseData]`)

WHEN un appel du pool échoue (timeout, 5xx),
THE method SHALL **logger l'erreur via `Log::error()` avec contexte `{id, endpoint, error}`** sans propager l'exception (les autres requêtes du pool continuent).

### REQ-4 — Helpers spécialisés au-dessus du batch

WHERE la couche 3 est exposée aux callers,
THE service SHALL fournir 2 helpers de convenance qui appellent `fetchManyByEndpoint` avec le bon `endpointPattern` :

```php
public function fetchManyMatieresDetails(array $matiereIds, string $userToken, ?int $ttl = 600): array
public function fetchManyClassesDetails(array $classeIds, string $userToken, ?int $ttl = 600): array
```

**Raison du séparé** : sémantique de domaine claire pour les callers + TTL par défaut sensé par ressource (matières/classes sont stables — 10min).

### REQ-5 — Refactor callsite #1 : LMSMatieresController

WHEN `LMSMatieresController.php:535-572` (méthode `getMatieresEnrichies`) est refactoré,
THE controller SHALL :
1. Appeler `matieres` (1 fois) pour récupérer la liste des matières
2. Extraire les IDs : `$ids = array_column($matieres, 'id')`
3. Appeler `$this->klassciService->fetchManyMatieresDetails($ids, $klassciToken)` (1 fois)
4. Itérer sur les matières en récupérant les détails depuis le map retourné

WHEN les tests Feature existants pour `getMatieresEnrichies` sont rejoués,
THE tests SHALL passer sans modification (la structure JSON de la réponse est préservée).

### REQ-6 — Refactor callsite #2 : LMSSeancesController

WHEN `LMSSeancesController.php:1297-1334` (méthode `getSeanceDataFromKlassci`) est refactoré,
THE method SHALL utiliser `fetchManyMatieresDetails()` pour le batch enrichi (au lieu du foreach séquentiel).

Note : la sémantique de "search-and-stop" (`if ($seance['id'] == $seanceId) return ...`) doit être préservée — on charge toutes les matières en parallèle puis on cherche dans les résultats. Le coût d'un batch légèrement plus large est acceptable (≤ 30 matières) face au gain de parallélisation.

### REQ-7 — Refactor callsite #3 : SeanceQueryService

WHEN `SeanceQueryService.php:269-280` (méthode `resolveSeanceForCoordinator`) est refactoré,
THE method SHALL utiliser `fetchManyMatieresDetails()` pour batch + search côté PHP.

### REQ-8 — Refactor callsite #4 : EvaluationController studentEvaluations

WHEN `EvaluationController.php:521-588` (la closure `$evaluationsLMS->map(...)`) est refactorée,
THE controller SHALL :
1. **Avant** la closure `map()`, identifier les IDs uniques de matières/classes nécessaires (parcours léger en PHP, pas d'appel HTTP)
2. Appeler `fetchManyMatieresDetails($matiereIds, $token)` + `fetchManyClassesDetails($classeIds, $token)` une seule fois chacun
3. Dans la closure `map()`, lire depuis les 2 maps précalculés au lieu d'appeler `requestWithUserToken` individuellement

### REQ-9 — Refactor callsite #5 : LessonController

WHEN `LessonController.php:323-330` est refactoré,
THE method SHALL utiliser `fetchManyClassesDetails($classeIds, $klassciToken)` en remplacement du foreach séquentiel.

### REQ-10 — Refactor callsite #6 : NotifyUpcomingEvaluations command

WHEN `NotifyUpcomingEvaluations.php:44-48` est refactoré,
THE command SHALL :
1. Extraire les `classe_ids` des évaluations à notifier
2. Faire un seul appel batch `fetchManyByEndpoint($classeIds, "classes/{id}/etudiants", null, 300)` — note : token = null car ce command s'exécute en CLI avec le token système
3. Itérer sur le map retourné pour traiter les étudiants par classe

**Note** : ce command n'a pas de user-token (CLI). Il utilise `getClasseEtudiants()` (méthode globale, déjà cachée). Le refactor doit préserver ce contexte — possible variante : exposer un helper batch pour le cas "no user token" (utilisant `get()` interne).

### REQ-11 — Refactor callsite #7 : Jobs async (ClasseSyncService + SyncKlassciSeances)

WHEN les jobs async (`ClasseSyncService.php:148-169` + `SyncKlassciSeances.php:55-63`) sont refactorés,
THE jobs SHALL utiliser `fetchManyMatieresDetails()`.

**Priorité plus faible** : ces jobs s'exécutent en background — le gain wall-clock est moins visible pour l'utilisateur. Refactor inclus pour cohérence architecturale et bénéfice cumulé sur la queue.

### REQ-12 — Tests unitaires pour la couche `KlassciProxyService`

WHEN les couches 1, 2, 3 sont implémentées,
THE fichier `tests/Unit/Services/KlassciProxyServiceMemoTest.php` SHALL être créé avec au minimum :

| # | Test | Scénario | Assertion clé |
|---|------|----------|---------------|
| 1 | `test_memoizes_identical_get_within_request` | 3× `get('matieres')` avec mêmes params | `Http::fake()` reçoit 1 seul appel |
| 2 | `test_memoizes_identical_user_token_get_within_request` | 3× `requestWithUserToken($t, 'matieres/1')` | 1 seul appel HTTP |
| 3 | `test_post_clears_request_memo` | `get` → memoize → `post` → `get` → réseau retouche | 2 appels HTTP |
| 4 | `test_user_token_cache_uses_token_hash_in_key` | 2 tokens distincts, même endpoint | 2 entrées de cache distinctes |
| 5 | `test_user_token_post_does_not_read_cache` | Cache pre-rempli → `post` → vérifier réseau touché | 1 appel HTTP |
| 6 | `test_user_token_post_invalidates_tenant_cache` | `get` cache → `post` → next `get` retouche réseau | 2 appels HTTP |

WHEN la couche 3 batch helper est implémentée,
THE fichier `tests/Unit/Services/KlassciProxyServiceBatchTest.php` SHALL être créé avec au minimum :

| # | Test | Scénario | Assertion clé |
|---|------|----------|---------------|
| 1 | `test_fetch_many_returns_map_indexed_by_id` | `fetchManyByEndpoint([1,2,3], "matieres/{id}", $t)` | Retourne `[1 => ..., 2 => ..., 3 => ...]` |
| 2 | `test_fetch_many_skips_failed_ids` | 1 ID retourne 500 | Map sans cet ID, log erreur émis |
| 3 | `test_fetch_many_uses_pool_concurrency` | 8 IDs, pool_size=4 | Assertion `Http::pool` invoqué avec 4 batches max |
| 4 | `test_fetch_many_cache_hit_short_circuits_network` | Pre-remplir cache pour 2/3 IDs | Pool fait 1 seul appel HTTP (l'ID restant) |
| 5 | `test_fetch_many_memo_hit_short_circuits_cache_and_network` | Pre-remplir memo pour 2/3 IDs | 1 seul appel cache + 1 réseau |
| 6 | `test_fetch_many_empty_array_returns_empty_map` | `fetchManyByEndpoint([], ...)` | Retourne `[]`, 0 appels HTTP |

### REQ-13 — Tests Feature pour les 7 callsites refactorés

WHEN les callers sont refactorés (PR 3 par caller),
THE tests Feature correspondants SHALL être ajoutés ou actualisés :

| Caller | Test file | Test ciblé |
|---|---|---|
| `LMSMatieresController::getMatieresEnrichies` | `tests/Feature/LMSMatieresControllerTest.php` | `test_get_matieres_enrichies_uses_single_batch_call` (assertion : `Http::fake()` reçoit 1 + 1 batch au lieu de 1 + N) |
| `LMSSeancesController::getSeanceDataFromKlassci` | `tests/Feature/LMSSeancesControllerTest.php` | `test_get_seance_data_uses_batch_helper` |
| `SeanceQueryService::resolveSeanceForCoordinator` | `tests/Unit/Services/SeanceQueryServiceTest.php` | `test_resolve_seance_for_coordinator_uses_batch_helper` |
| `EvaluationController::studentEvaluations` | `tests/Feature/EvaluationControllerTest.php` | `test_student_evaluations_uses_batch_matieres_classes` |
| `LessonController::?` | `tests/Feature/LessonControllerTest.php` | `test_classe_enrichment_uses_batch_helper` |
| `NotifyUpcomingEvaluations` | `tests/Feature/Console/NotifyUpcomingEvaluationsTest.php` | `test_notify_uses_batch_classe_etudiants` |
| Jobs async | `tests/Feature/Jobs/SyncKlassciSeancesTest.php` | `test_sync_uses_batch_matieres_details` |

### REQ-14 — Aucune régression fonctionnelle

WHEN toute la suite `vendor/bin/phpunit` est exécutée après chacune des 3 PRs,
THE suite SHALL passer à **100 %** (les structures JSON retournées par les controllers sont préservées byte-à-byte sauf changement explicitement testé).

WHEN `vendor/bin/phpstan analyse` est exécuté après chacune des 3 PRs,
THE analyse SHALL passer à **[OK] No errors** (level 9 + Larastan).

### REQ-15 — Configuration

WHERE la config est exposée,
THE fichier `config/services.php` SHALL être étendu avec :

```php
'klassci' => [
    // ... existant ...
    'pool_size' => env('KLASSCI_POOL_SIZE', 4),
    'user_token_cache_default_ttl' => env('KLASSCI_USER_TOKEN_TTL', 300),  // 5 min
],
```

WHERE le `.env.example` documente les nouvelles vars,
THE file SHALL inclure :

```
KLASSCI_POOL_SIZE=4
KLASSCI_USER_TOKEN_TTL=300
```

## Hors scope (volontairement)

| Item | Pourquoi hors scope |
|------|----------------------|
| Endpoint batch côté KLASSCI (`POST /matieres/batch` avec liste d'IDs) | On ne contrôle pas le backend KLASSCI. Une coordination cross-projet est un follow-up séparé (issue dédiée à créer si l'équipe KLASSCI accepte). |
| Cache GraphQL-style avec sélection de champs | YAGNI. Le cas d'usage actuel est uniformément "fetch entité par ID". |
| Cache prefetch global (warm cache au login) | Risque de gaspiller des appels HTTP pour des ressources jamais lues. Reactive caching reste préférable. |
| Migration vers Redis (vs file cache actuel) | Décision ops orthogonale au refactor algorithmique. Le code fonctionne sur n'importe quel driver Cache Laravel. |
| Refactor de `KlassciProxyService` en collaborateurs séparés (`KlassciHttpClient`, `KlassciRequestMemo`, `KlassciCacheKeyStrategy`, `KlassciConfigResolver`) | ~~Initialement "hors scope"~~ — révisé suite audit `spec-architect` BLOCKED. Le split est **INTÉGRÉ dans PR 1** car le manifeste §1.1 dit « Exceptions : Aucune » sur la limite Services ≤ 300 lignes. Le fichier était déjà à 501 lignes pré-PR (dette pré-existante). Split en 4 collaborateurs DIP-friendly. Cf. `design.md` §2.0. |
| Cache invalidation fine par entité (au lieu du `invalidatedAt` tenant-wide) | Le mécanisme actuel est volontairement large (sécurité > granularité). Migrer vers une invalidation fine demande un mapping endpoint→clés précis et augmente la surface de bug. Hors scope sans data de production démontrant le besoin. |
| Métrique Prometheus / Statsd pour mesurer cache hit ratio | Ops follow-up. Les `Log::info()` existants suffisent en attendant. |

## Critère d'acceptation global (par PR)

**PR 1 (Couches 1+2) mergeable WHEN :**
1. ✓ REQ-1, REQ-2, REQ-15 implémentés
2. ✓ Tests unitaires REQ-12 (suite Memo) passent 100%
3. ✓ `vendor/bin/phpstan analyse` reste `[OK] No errors`
4. ✓ Toute la suite Feature existante passe sans modification (preuve qu'aucun caller ne casse)
5. ✓ `spec-security` audit retourne 0 finding HIGH/CRITICAL (focus : pas de fuite token dans les clés cache, pas de hit cross-user)
6. ✓ `spec-architect` audit retourne 0 finding HIGH/CRITICAL
7. ✓ `spec-reviewer` audit retourne MERGE-READY

**PR 2 (Couche 3 batch helper) mergeable WHEN :**
1. ✓ REQ-3, REQ-4 implémentés
2. ✓ Tests unitaires REQ-12 (suite Batch) passent 100%
3. ✓ PR 1 mergée préalablement
4. ✓ `phpstan` reste vert
5. ✓ Aucun caller existant ne change encore (le helper est inutilisé en PR 2)
6. ✓ Les 3 audits → MERGE-READY

**PR 3 (Refactor des 7 callsites) mergeable WHEN :**
1. ✓ REQ-5 à REQ-11 implémentés
2. ✓ REQ-13 tests Feature passent
3. ✓ REQ-14 régression zero
4. ✓ PR 2 mergée préalablement
5. ✓ Bénéfice mesurable documenté dans la description PR : nombre d'appels HTTP KLASSCI avant/après pour chaque controller touché (via `Http::fake()->assertSentCount(...)`)
6. ✓ Les 3 audits → MERGE-READY

## Critère d'invalidation (Q15 — manifeste §4)

Cette solution est **à invalider et reconcevoir** SI :

1. **KLASSCI expose un endpoint batch natif** (`POST /matieres/batch` avec liste d'IDs en body) → Couche 3 (`Http::pool`) devient inutile, remplacée par 1 seul appel HTTP. Couches 1+2 restent valables (memoization + cache).
2. **Le pattern N+1 devient ≥ 50 sites** → considérer un proxy middleware générique HTTP qui memoize/cache de façon transparente toutes les requêtes Laravel `Http::*`. Aujourd'hui à 7 sites — over-engineering.
3. **La latence wall-clock dominante n'est PAS le HTTP KLASSCI** mais le SQL ou le rendu Blade → re-prioriser sur PERF-03 (N+1 SQL) ou PERF-04 (logique métier modèles).
4. **Le cache distribué devient un goulot d'étranglement** (Redis CPU > 60%) → migrer vers un cache à plusieurs niveaux (in-memory L1 + Redis L2) ou diminuer le TTL.
5. **Le token KLASSCI utilisateur n'a plus la sémantique "1 user = 1 token"** (par exemple si KLASSCI introduit un token-per-session multi-device) → la stratégie de hash `tokenHash` doit être révisée pour éviter la fragmentation du cache.

Aucune de ces 5 conditions n'est connue aujourd'hui.
