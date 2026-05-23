# PERF-02 — Tasks

> Spec parent : [`requirements.md`](./requirements.md) + [`design.md`](./design.md). Issue : #137 (à créer).
>
> Découpage en **3 PRs séquentielles** + tâches hiérarchiques par PR.

## Pré-requis (avant PR 1)

- [ ] **P0** — Vérifier que PR #133 (`refactor/132-migrate-role-checks-to-enum`) est mergée avant de partir sur PERF-02. Sinon attendre — risque conflit `EvaluationController.php` (les 2 PRs y touchent : #133 sur `$user->isStudent()`, PERF-02 sur le batch matieres).
- [ ] **P1** — Créer l'issue GitHub #137 "PERF-02 — N+1 HTTP KLASSCI → batch + caching" avec lien vers `.claude/specs/perf-02-klassci-batch-cache/`.
- [ ] **P2** — Sync `main` local : `git checkout main && git pull origin main`.
- [ ] **P3** — Vérifier état initial sain : `vendor/bin/phpstan analyse` = `[OK]`, `vendor/bin/phpunit` = vert (ou tests skipped si pas `pdo_pgsql`).

---

## PR 1 — Split architectural + Couches 1+2 (memoization + cache user-token-aware)

**Branche** : `refactor/perf-02-memoization-and-cache`
**Issue** : #137 (part a/c)
**Scope** : split de `KlassciProxyService` en **4 collaborateurs DIP-friendly** + orchestrateur fin + ajout des couches 1+2 dans l'orchestrateur. Aucun caller ne change (le contract public de `KlassciProxyService` est préservé byte-pour-byte).

**Note importante (révision 2026-05-23)** : initialement le split était planifié en follow-up. L'audit `spec-architect` a retourné BLOCKED suite à HIGH-1 (fichier 700 lignes) + HIGH-2 (DRY ~45 lignes). Le manifeste §1.1 dit « Exceptions : Aucune » → le split est **intégré dans PR 1**.

### Tâche 1.1 — Configuration

- [ ] **1.1.1** — Étendre `config/services.php` clé `klassci` :
  ```php
  'pool_size' => env('KLASSCI_POOL_SIZE', 4),
  'user_token_cache_default_ttl' => env('KLASSCI_USER_TOKEN_TTL', 300),
  'memoize_enabled' => env('KLASSCI_MEMOIZE_ENABLED', true),
  ```
- [ ] **1.1.2** — Ajouter les 3 vars à `.env.example` (sans valeur sensible, avec commentaire).

### Tâche 1.2 — Couche 1 — Memoization

- [ ] **1.2.1** — Ajouter propriété `private array $requestMemo = []` (typage PHPStan `array<string, array<string, mixed>>`) dans `KlassciProxyService`.
- [ ] **1.2.2** — Ajouter méthode privée `memoKey(string $method, string $endpoint, array $params, ?string $tokenHash): string` (utilise `hash('xxh3', json_encode(...))`).
- [ ] **1.2.3** — Modifier `get()` (ligne ~116) pour court-circuiter via memo **avant** `Cache::remember`. Garder la signature publique inchangée.
- [ ] **1.2.4** — Ajouter méthode privée `clearRequestMemo(): void` et l'appeler dans `invalidateCache()` (après le `Cache::forever`).
- [ ] **1.2.5** — Respecter le flag `config('services.klassci.memoize_enabled')` — short-circuit si `false` (escape hatch ops).

### Tâche 1.3 — Couche 2 — Cache user-token-aware

- [ ] **1.3.1** — Ajouter méthode privée `userTokenHash(string $userToken): string` (`substr(hash('xxh3', $userToken), 0, 16)`).
- [ ] **1.3.2** — Ajouter méthode privée `generateUserTokenCacheKey(string $endpoint, array $params, string $tokenHash): string` (réutilise `resolveTenantCacheKey()` + `invalidatedAt`).
- [ ] **1.3.3** — Modifier `requestWithUserToken()` (ligne ~444) :
  - Nouvel argument `?int $customTTL = null` en fin de signature
  - Pour `$method === 'GET'` : check memo → check cache distribué → HTTP. Si HTTP succès : store cache + memo.
  - Pour `$method` ∈ {POST, PUT, DELETE} : pas de lecture cache, mais appel `invalidateCache($endpoint)` après HTTP succès → propage déjà au memo via 1.2.4.
- [ ] **1.3.4** — Verification : le `Log::info` des appels HTTP ne logge PAS le `cacheKey` complète (préservation `tokenHash` opaque).

### Tâche 1.4 — Tests unitaires (REQ-12 — suite Memo)

- [ ] **1.4.1** — Créer `tests/Unit/Services/KlassciProxyServiceMemoTest.php` (avec `Http::fake()`).
- [ ] **1.4.2** — Test 1 — `test_memoizes_identical_get_within_request` — 3× même `get('matieres')` → 1 seul `Http::sent`.
- [ ] **1.4.3** — Test 2 — `test_memoizes_identical_user_token_get_within_request`.
- [ ] **1.4.4** — Test 3 — `test_post_clears_request_memo` (preuve que le reset memo après write est effectif).
- [ ] **1.4.5** — Test 4 — `test_user_token_cache_uses_token_hash_in_key` (2 tokens distincts → 2 entrées cache).
- [ ] **1.4.6** — Test 5 — `test_user_token_post_does_not_read_cache`.
- [ ] **1.4.7** — Test 6 — `test_user_token_post_invalidates_tenant_cache`.
- [ ] **1.4.8** — Test 7 — `test_memoize_disabled_via_config_skips_memo` (preuve du flag d'escape hatch).
- [ ] **1.4.9** — Test 8 — `test_token_hash_is_not_logged_brutely` (assertion `Log::shouldReceive` sans token).

### Tâche 1.5 — Validation & audits PR 1

- [ ] **1.5.1** — `vendor/bin/phpstan analyse` → `[OK] No errors`.
- [ ] **1.5.2** — `vendor/bin/phpunit tests/Unit/Services/KlassciProxyServiceMemoTest.php` → 8/8 verts.
- [ ] **1.5.3** — Suite Feature complète passe sans modification (regression zero).
- [ ] **1.5.4** — Lancer `spec-security` agent sur `app/Services/KlassciProxyService.php` (focus : token leak, cross-user, IDOR).
- [ ] **1.5.5** — Lancer `spec-architect` agent (SOLID, taille fichier, DIP).
- [ ] **1.5.6** — Lancer `spec-reviewer` agent (15 questions §4 PRODUCTION_STANDARDS).
- [ ] **1.5.7** — Si tous green : commit + push + créer PR + attendre approbation user pour merge.

---

## PR 2 — Couche 3 (batch helper `fetchManyByEndpoint` + helpers spécialisés)

**Branche** : `refactor/perf-02-batch-helpers`
**Issue** : #137 (part b/c)
**Dépendance** : PR 1 mergée.
**Scope** : ajouts publics `fetchManyByEndpoint` + `fetchManyMatieresDetails` + `fetchManyClassesDetails` + `fetchManyClasseEtudiants`.

### Tâche 2.1 — Helper générique `fetchManyByEndpoint`

- [ ] **2.1.1** — Implémenter méthode publique `fetchManyByEndpoint(array $ids, string $endpointPattern, string $userToken, ?int $customTTL = null): array` dans `KlassciProxyService` selon design §4.2.
- [ ] **2.1.2** — Algorithme :
  - Pre-filter memo + cache distribué → `$resolved` + `$needsFetch[]`
  - Pour `$needsFetch` : `array_chunk(..., $poolSize)` puis pour chaque chunk `Http::pool(...)`
  - Pour chaque réponse OK : populate memo + cache + `$resolved`
  - Pour chaque réponse échouée : `Log::error('KLASSCI batch fetch failed', [...])` + omettre du map
- [ ] **2.1.3** — PHPStan : typage strict `array<int>` en entrée, `array<int, array<string, mixed>>` en sortie.

### Tâche 2.2 — Helpers spécialisés

- [ ] **2.2.1** — `fetchManyMatieresDetails(array $matiereIds, string $userToken, ?int $ttl = 600): array` — délègue à `fetchManyByEndpoint` avec `"matieres/{id}"`.
- [ ] **2.2.2** — `fetchManyClassesDetails(array $classeIds, string $userToken, ?int $ttl = 600): array` — délègue avec `"classes/{id}"`.
- [ ] **2.2.3** — `fetchManyClasseEtudiants(array $classeIds, ?int $anneeId = null, ?int $ttl = 300): array` — variante **sans user-token** (token système global). Utilise le mode `get()` interne en boucle parallélisée via `Http::pool` (helper interne car endpoint global, pas user-scoped).
- [ ] **2.2.4** — PHPDoc complète sur chaque helper (paramètres, exemples, TTL par défaut, complexité O(N/poolSize)).

### Tâche 2.3 — Tests unitaires (REQ-12 — suite Batch)

- [ ] **2.3.1** — Créer `tests/Unit/Services/KlassciProxyServiceBatchTest.php`.
- [ ] **2.3.2** — Test 1 — `test_fetch_many_returns_map_indexed_by_id` (3 IDs → map 3 entrées).
- [ ] **2.3.3** — Test 2 — `test_fetch_many_skips_failed_ids` (1 ID 500 → map sans cet ID + log émis).
- [ ] **2.3.4** — Test 3 — `test_fetch_many_uses_pool_concurrency` (8 IDs, pool_size=4 → 2 batches).
- [ ] **2.3.5** — Test 4 — `test_fetch_many_cache_hit_short_circuits_network` (pre-fill cache 2/3 → 1 appel HTTP).
- [ ] **2.3.6** — Test 5 — `test_fetch_many_memo_hit_short_circuits_cache_and_network` (pre-fill memo 2/3 → 0 cache + 1 HTTP).
- [ ] **2.3.7** — Test 6 — `test_fetch_many_empty_array_returns_empty_map`.
- [ ] **2.3.8** — Test 7 — `test_fetch_many_classe_etudiants_works_without_user_token` (variante 2.2.3).
- [ ] **2.3.9** — Test 8 — `test_fetch_many_handles_endpoint_pattern_with_special_chars` (regression : `"matieres/{id}/details?foo=bar"` correctement substitué).

### Tâche 2.4 — Validation & audits PR 2

- [ ] **2.4.1** — `vendor/bin/phpstan analyse` → `[OK]`.
- [ ] **2.4.2** — Suite tests batch 8/8 verts.
- [ ] **2.4.3** — Toute la suite passe sans régression.
- [ ] **2.4.4** — `spec-security` sur le helper (pas de fuite token, isolation entre IDs).
- [ ] **2.4.5** — `spec-architect` (taille service après ajout : ~650 lignes, acceptable).
- [ ] **2.4.6** — `spec-reviewer` → MERGE-READY.
- [ ] **2.4.7** — Commit + push + PR + attente approbation user.

---

## PR 3 — Refactor des 7 callsites

**Branche** : `refactor/perf-02-migrate-callers`
**Issue** : #137 (part c/c)
**Dépendance** : PR 2 mergée.
**Scope** : 7 callers refactorés, chacun avec son test Feature.

### Tâche 3.1 — Refactor LMSMatieresController (REQ-5)

- [ ] **3.1.1** — Refactorer `LMSMatieresController.php:535-572` (méthode `getMatieresEnrichies`) — pattern §5.1 du design.
- [ ] **3.1.2** — Vérifier que la structure JSON de la réponse est byte-équivalente (manuellement via dump + diff).
- [ ] **3.1.3** — Test Feature `test_get_matieres_enrichies_uses_single_batch_call` avec `Http::fake()->assertSentCount` (1 + 1 vs 1 + N).

### Tâche 3.2 — Refactor LMSSeancesController (REQ-6)

- [ ] **3.2.1** — Refactorer `LMSSeancesController.php:1297-1334` (méthode privée `getSeanceDataFromKlassci`).
- [ ] **3.2.2** — Préserver la sémantique "search-and-stop" : on charge toutes les matières en parallèle puis on cherche côté PHP.
- [ ] **3.2.3** — Test Feature `test_get_seance_data_uses_batch_helper`.

### Tâche 3.3 — Refactor SeanceQueryService (REQ-7)

- [ ] **3.3.1** — Refactorer `SeanceQueryService.php:269-280` (méthode `resolveSeanceForCoordinator`).
- [ ] **3.3.2** — Test Unit `test_resolve_seance_for_coordinator_uses_batch_helper` (avec mock `KlassciProxyService`).

### Tâche 3.4 — Refactor EvaluationController studentEvaluations (REQ-8)

- [ ] **3.4.1** — Ajouter méthode `isStandaloneLms()` sur `Evaluation` model (helper local, retourne `$this->klassci_evaluation_id === null`).
- [ ] **3.4.2** — Refactorer `EvaluationController.php:521-588` en 2 passes (pre-pass collect IDs + batch resolve + map lecture).
- [ ] **3.4.3** — Test Feature `test_student_evaluations_uses_batch_matieres_classes`.

### Tâche 3.5 — Refactor LessonController (REQ-9)

- [ ] **3.5.1** — Refactorer `LessonController.php:323-330`.
- [ ] **3.5.2** — Test Feature `test_classe_enrichment_uses_batch_helper`.

### Tâche 3.6 — Refactor NotifyUpcomingEvaluations (REQ-10)

- [ ] **3.6.1** — Refactorer `NotifyUpcomingEvaluations.php:44-48` pour utiliser `fetchManyClasseEtudiants()`.
- [ ] **3.6.2** — Test Feature Console `test_notify_uses_batch_classe_etudiants`.

### Tâche 3.7 — Refactor Jobs async (REQ-11)

- [ ] **3.7.1** — Refactorer `ClasseSyncService.php:148-169`.
- [ ] **3.7.2** — Refactorer `SyncKlassciSeances.php:55-63`.
- [ ] **3.7.3** — Test Feature Jobs `test_sync_uses_batch_matieres_details`.

### Tâche 3.8 — Documentation

- [ ] **3.8.1** — `docs/INTEGRATION_KLASSCI.md` — section "Performance — Batch & Cache".
- [ ] **3.8.2** — `CONTRIBUTING.md` — règle "tout nouveau endpoint KLASSCI parametré par ID doit avoir un helper batch".
- [ ] **3.8.3** — PHPDoc class-level `KlassciProxyService` — décrire les 3 couches.

### Tâche 3.9 — Validation & audits PR 3

- [ ] **3.9.1** — `vendor/bin/phpstan analyse` → `[OK]`.
- [ ] **3.9.2** — Toute la suite Feature + Unit passe (les nouveaux tests inclus).
- [ ] **3.9.3** — Benchmark manuel : mesurer les compteurs `Http::fake()->assertSentCount` avant/après pour 3 callers représentatifs (LMSMatieres, EvaluationStudent, NotifyUpcoming). Documenter dans la description PR.
- [ ] **3.9.4** — `spec-security` audit final (focus IDOR : aucun nouveau path qui mélange tokens).
- [ ] **3.9.5** — `spec-architect` audit final.
- [ ] **3.9.6** — `spec-reviewer` audit final → MERGE-READY.
- [ ] **3.9.7** — Commit + push + PR + attente approbation user.
- [ ] **3.9.8** — Après merge, fermer manuellement issue #137 avec commentaire récapitulatif des 3 PRs.

---

## Tâches transverses

- [ ] **T1** — Après merge de PR 3, mettre à jour `REFACTORING_ROADMAP.md` : marquer PERF-02 comme DONE, ajouter référence vers PRs #X/#Y/#Z + métriques mesurées.
- [ ] **T2** — Mettre à jour `MEMORY.md` (auto-memory) si une décision architecturale clé émerge des audits (ex: pool_size optimal mesuré).
- [ ] **T3** — Si un follow-up émerge (ex: endpoint KLASSCI batch côté backend), créer une issue séparée (pas dans le scope PERF-02).

---

## Estimation effort

| PR | Tâches | Estimation |
|----|--------|------------|
| PR 1 | 1.1-1.5 (~25 sous-tâches) | 4-6h |
| PR 2 | 2.1-2.4 (~20 sous-tâches) | 3-5h |
| PR 3 | 3.1-3.9 (~30 sous-tâches) | 6-10h |
| Total | ~75 sous-tâches | **13-21h** sur 3 sessions |

L'estimation peut diminuer si les helpers de PR 2 capturent les abstractions correctement (moins de refactor caller-par-caller).

---

## Checklist de démarrage immédiat

1. [ ] User valide la PR #133 merger sur GitHub (pré-requis P0)
2. [ ] `git checkout main && git pull origin main`
3. [ ] `git checkout -b refactor/perf-02-memoization-and-cache`
4. [ ] Créer issue GitHub #137 avec lien spec
5. [ ] Démarrer Tâche 1.1 (Configuration)
