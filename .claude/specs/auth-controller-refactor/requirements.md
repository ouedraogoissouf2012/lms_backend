# AuthController refactor — Extraire les services d'authentification multi-tenant

> Issue GitHub : [#120 \[refactor\] AuthController.php 520 lignes — extraire UserSynchronizationService (suite de PR #118)](https://github.com/ouedraogoissouf2012/lms_backend/issues/120)
>
> Identifié par l'audit `spec-architect` de PR #118 (CRITICAL-05) — MEDIUM-2. Étendu suite au diagnostic du **bug `successful()` sur `ConnectionException`** révélé lors du test de connexion `coord.losseni.coulibaly` le 2026-05-25 (4/5 tenants KLASSCI inaccessibles → crash login complet).

## Contexte

`app/Http/Controllers/API/AuthController.php` mesure **528 lignes** (mai 2026), violant §1.1 (300 lignes max) et §5 (Controllers max 200 lignes) de `PRODUCTION_STANDARDS.md`. Cette violation est documentée dans l'audit `spec-architect` de PR #118 (MEDIUM-2) et dans le rapport d'analyse projet du 2026-05-23 (top 3 god-controllers).

### Anti-patterns mesurés

| Item | Valeur | Limite | Violation |
|------|--------|--------|-----------|
| `AuthController.php` taille | 528 lignes | 300 (§1.1) / 200 (§5 Controllers) | ❌ +228 lignes |
| `login()` méthode | 154 lignes | 40 (§5) | ❌ +114 lignes |
| `syncUserFromKlassci()` méthode | 77 lignes | 40 (§5) | ❌ +37 lignes |
| Responsabilités distinctes | **5** (discovery, local auth, KLASSCI auth, sync, sessions) | 1 (§1.6 S) | ❌ SRP violé |
| Facades `Http::`/`Hash::`/`Log::`/`DB::` en code métier | 4 facades inline | 0 (§1.6 D) | ❌ DIP violé |

### Bug critique pré-existant — `successful()` sur `ConnectionException`

**Origine** : commit `a6066be2` (2026-03-21, `ouedraogo7`) — 2 mois avant le refactor PERF-02.

**Lignes affectées** :
- `app/Http/Controllers/API/AuthController.php:73` dans `findTenantsForUser()` :
  ```php
  if ($response && $response->successful()) { ... }   // ❌ ne check pas si $response est ConnectionException
  ```
- `app/Http/Controllers/API/AuthController.php:172` dans `login()` :
  ```php
  if (!$loginResponse->successful()) { ... }          // ❌ idem
  ```

**Effet** : quand un tenant KLASSCI est injoignable (DNS down, SSL invalide, timeout réseau), `Http::pool()` retourne une `ConnectionException` au lieu d'une `Response`. Le code appelle alors `->successful()` sur l'exception → **crash 500 du login**.

**Reproductibilité** : test du 2026-05-25 avec username `coord.losseni.coulibaly` / password `Bonjour@2026` → erreur immédiate « Call to undefined method Illuminate\Http\Client\ConnectionException::successful() ».

**Cohérence interne au projet** : mon refactor PERF-02 PR #137 (`KlassciBatchFetcher`) a déjà appliqué le pattern correct `instanceof Response` (cf. `app/Services/Klassci/KlassciBatchFetcher.php:175`). Le refactor d'AuthController doit reprendre exactement ce pattern.

## Solution

Suivre le **pattern PERF-02** déjà éprouvé (PR #136/#137) : extraire `AuthController` en orchestrateur fin + **4 collaborateurs DIP-friendly**.

### Architecture cible

```
app/Services/Klassci/Auth/                     (NEW sous-dossier)
├── KlassciTenantDiscovery.php       (~120 l.)  — findTenantsForUser via Http::pool, fix bug
├── KlassciAuthClient.php            (~100 l.)  — POST /auth/login sur un tenant, fix bug
└── KlassciUserSynchronizer.php      (~150 l.)  — syncUserFromKlassci DB transaction

app/Services/Auth/                             (NEW sous-dossier)
└── LocalLmsAuthenticator.php        (~80 l.)   — Hash::check + Sanctum token (auth locale supradmin)

app/Http/Controllers/API/AuthController.php    (~150 l.)  — orchestrateur fin
```

Chaque collaborateur :
- **DI constructor pur** (pas de Facade en code métier, pas de `app()` Service Locator)
- **Méthodes ≤ 40 lignes**
- **≤ 300 lignes** par fichier
- **Tests unitaires** dédiés via `Mockery::mock` (testabilité §1.6 D)

### Bug fix intégré

Tous les nouveaux collaborateurs qui utilisent `Http::pool` ou `Http::post` doivent appliquer le pattern canonique :

```php
if (!$response instanceof \Illuminate\Http\Client\Response || !$response->successful()) {
    Log::error('KLASSCI request failed', ['tenant' => $tenant, 'reason' => $response::class]);
    continue;  // ou throw selon contexte
}
```

C'est exactement le pattern appliqué dans `KlassciBatchFetcher::persistBatchResponses()` (PR #137). Cohérence garantie.

## Requirements (EARS)

### REQ-1 — Extraction `KlassciTenantDiscovery`

WHERE `app/Services/Klassci/Auth/KlassciTenantDiscovery.php` est créé,
THE classe SHALL :
1. Avoir un constructor injectant `HttpFactory`, `LoggerInterface` (PSR-3), `KlassciTenantRegistry` (ou méthode équivalente pour récupérer la liste des institutions actives).
2. Exposer une méthode publique `findMatchingTenants(string $identifier): array` qui :
   - Récupère la liste des tenants actifs.
   - Lance `Http::pool()` en parallèle avec `/auth/check-user` sur chaque tenant.
   - Pour chaque résultat : vérifie `$response instanceof Response` AVANT `->successful()`.
   - Sur exception (ConnectionException, TimeoutException, etc.) : logge + omet le tenant.
   - Retourne la liste des tenants qui ont matché.

WHEN un tenant retourne `ConnectionException`,
THE service SHALL :
- Logger via `LoggerInterface::error()` avec contexte `{tenant_code, reason}`.
- Continuer le parcours des autres tenants (pas de propagation d'exception).
- Ne PAS appeler de méthode sur l'exception (`successful()`, `json()`, etc.).

### REQ-2 — Extraction `KlassciAuthClient`

WHERE `app/Services/Klassci/Auth/KlassciAuthClient.php` est créé,
THE classe SHALL :
1. Constructor injectant `HttpFactory`, `LoggerInterface`.
2. Méthode publique `attemptLogin(string $tenantUrl, string $username, string $password): ?array` qui :
   - Fait un `POST /auth/login` sur le tenant.
   - Check `$response instanceof Response` AVANT `->successful()`.
   - Sur exception ou échec : retourne `null` (sémantique « échec silencieux pour permettre essai sur tenant suivant »).
   - Sur succès : retourne le payload JSON décodé (`['data' => ['user' => ..., 'token' => ...]]`).

### REQ-3 — Extraction `KlassciUserSynchronizer`

WHERE `app/Services/Klassci/Auth/KlassciUserSynchronizer.php` est créé,
THE classe SHALL encapsuler la logique actuelle de `AuthController::syncUserFromKlassci()` et `syncStudentClasses()`. Constructor injectant `ClasseSyncService` + `LoggerInterface`.

THE comportement runtime SHALL être **strictement identique** à l'ancienne méthode :
- DB transaction
- Recherche par `(klassci_id, institution_id)` puis fallback par email
- Champs propagés write-once vs propagés-au-login (préserver les invariants de [`.claude/specs/critical-05-klassci-role-separation/design.md`](.claude/specs/critical-05-klassci-role-separation/design.md) §4)
- `klassci_enseignant_id` write-once à la création (issue #119 préservée)
- `role` LMS non écrasé pour user existant (CRITICAL-05 préservé)

### REQ-4 — Extraction `LocalLmsAuthenticator`

WHERE `app/Services/Auth/LocalLmsAuthenticator.php` est créé,
THE classe SHALL :
1. Constructor sans dépendance externe (utilise `Hash` via injection PSR-12 si applicable, sinon Facade documentée comme « bordure framework »).
2. Méthode publique `attemptLocalAuth(string $identifier, string $password): ?User` qui :
   - Cherche un user où `email == identifier` OU `name == identifier` (`withoutGlobalScope('institution')`).
   - Si trouvé + `Hash::check($password, $user->password)` OK → retourne `$user`.
   - Sinon retourne `null`.

### REQ-5 — `AuthController` réduit à orchestrateur fin

WHEN `AuthController` est refactoré,
THE fichier SHALL :
- Passer de 528 lignes à **≤ 200 lignes** (§5 Controllers).
- Constructor injectant **les 4 collaborateurs** + `KlassciProxyService` (existant) + `ClasseSyncService` (existant).
- Chaque méthode publique (`login`, `me`, `logout`, `refresh`, `check`) SHALL être ≤ 40 lignes.
- Ne contenir AUCUNE Facade `Hash::`, `Http::`, `DB::`, `Log::` directement en code métier (uniquement via les collaborateurs).
- Préserver la **signature publique** : routes inchangées, format de réponses JSON byte-identique.

### REQ-6 — Tests unitaires dédiés par collaborateur

WHEN les 4 collaborateurs sont créés,
THE projet SHALL avoir :
- `tests/Unit/Services/Klassci/Auth/KlassciTenantDiscoveryTest.php` couvrant : happy path, tenant down (ConnectionException), tenant 5xx, identifier inconnu.
- `tests/Unit/Services/Klassci/Auth/KlassciAuthClientTest.php` couvrant : login OK, login refusé (401), tenant down (ConnectionException), tenant 5xx.
- `tests/Unit/Services/Klassci/Auth/KlassciUserSynchronizerTest.php` couvrant : CREATE branch (nouveau user), UPDATE branch (user existant), écriture write-once `klassci_enseignant_id` (issue #119), préservation `role` LMS (CRITICAL-05).
- `tests/Unit/Services/Auth/LocalLmsAuthenticatorTest.php` couvrant : password OK, password KO, user introuvable.

Couverture minimale : ≥ 8 tests par collaborateur (32 tests au total).

### REQ-7 — Zero régression Feature

WHEN la suite `tests/Feature/Security/KlassciRoleSeparationTest.php` (PR #118) et autres tests Feature existants sont exécutés,
THE suite SHALL passer **100%** sans modification.

WHEN les 39 callers de `AuthController` (routes + middleware EnsureKlassciSync + tests) sont rejoués,
THE comportement SHALL être **byte-identique** au pré-refactor (mêmes status codes, mêmes payloads JSON, mêmes side-effects DB).

### REQ-8 — Documentation

WHERE le refactor est livré,
THE PR SHALL inclure :
- Spec complète sous `.claude/specs/auth-controller-refactor/` (requirements.md + design.md + tasks.md).
- Commentaire dans `app/Http/Controllers/API/AuthController.php` class-level expliquant l'architecture en 4 collaborateurs (analog à `KlassciProxyService.php`).
- Liens vers issue #120 et la PR.

## Hors scope (volontairement)

| Item | Pourquoi hors scope |
|------|----------------------|
| Refactor `me()`/`logout()`/`refresh()`/`check()` au-delà de leur intégration aux collaborateurs | Ces méthodes sont déjà ≤ 40 lignes et n'ont pas de logique métier complexe. Si elles dépassent 40 lignes après injection des collaborateurs, on les refacto ; sinon on les laisse. |
| Migration vers JWT au lieu de Sanctum | Décision orthogonale, scope distinct (TIER 3 OPS) |
| Audit complet de `EnsureKlassciSync` middleware | Hors scope direct. Issue séparée si besoin. |
| Backfill historique des users dont `password = Hash::make(uniqid())` | Sécurité acceptable (les users KLASSCI ne se log jamais via password local — le `uniqid()` est juste pour satisfaire la contrainte NOT NULL). À documenter clairement. |
| Fix gobal des 3 god-controllers (`LessonController`, `QuizController`) | Issues séparées (à créer) — PR par PR pour focus. |
| Phpunit.xml align (SQLite + APP_KEY + exclude broken tests) | PR séparée distincte — chore config, pas refactor. |

## Critère d'acceptation global

La PR est mergeable WHEN :

1. ✓ REQ-1 à REQ-8 implémentés
2. ✓ `vendor/bin/phpstan analyse` reste à `[OK] No errors`
3. ✓ `vendor/bin/phpunit tests/Unit/Services/Klassci/Auth/` + `tests/Unit/Services/Auth/` passe 100%
4. ✓ `vendor/bin/phpunit tests/Feature/Security/KlassciRoleSeparationTest.php` passe 100% (zéro régression CRITICAL-05)
5. ✓ Toute la suite `vendor/bin/phpunit` (avec les excludes existants) passe 100%
6. ✓ Le test manuel **« login `coord.losseni.coulibaly` / `Bonjour@2026` avec 4/5 tenants down »** retourne `401 Identifiants incorrects` ou `200 success` (selon réponse esbtp-abidjan KLASSCI), **JAMAIS** crash 500
7. ✓ `AuthController.php` ≤ 200 lignes (§5 Controllers)
8. ✓ Tous les nouveaux fichiers ≤ 300 lignes (§1.1)
9. ✓ Toutes les méthodes ≤ 40 lignes (§5)
10. ✓ Aucune Facade `Hash::`/`Http::`/`DB::`/`Log::` en code métier des nouveaux collaborateurs
11. ✓ `spec-security` audit retourne 0 finding HIGH/CRITICAL
12. ✓ `spec-architect` audit retourne 0 finding HIGH
13. ✓ `spec-reviewer` audit retourne MERGE-READY
14. ✓ Issue #120 fermée manuellement post-merge (mention « closes #120 » dans commit)

## Critère d'invalidation (Q15 — manifeste §4)

Cette solution est **à invalider et reconcevoir** SI :

1. **Migration vers JWT** est décidée — le refactor Sanctum-based deviendrait obsolète.
2. **L'API KLASSCI introduit un endpoint `/auth/discover-tenant` natif** — `KlassciTenantDiscovery` serait remplaçable par 1 seul appel HTTP au lieu d'un pool.
3. **Le projet passe en multi-region avec latence > 500ms par tenant** — `Http::pool()` séquentiel deviendrait inefficient. Migration vers async/queue.
4. **Sanctum est remplacé par Passport ou un autre provider** — `LocalLmsAuthenticator` deviendrait incompatible.
5. **Plus de 3 institutions partagent le même `klassci_api_url`** — le check défensif issue #75 (cf. `KlassciConfigResolver`) devrait être étendu à `KlassciTenantDiscovery` aussi.

Aucune de ces 5 conditions n'est connue aujourd'hui.
