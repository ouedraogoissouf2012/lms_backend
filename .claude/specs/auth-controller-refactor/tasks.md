# AuthController refactor — Tasks

> Spec parent : [`requirements.md`](./requirements.md) + [`design.md`](./design.md). Issue : [#120](https://github.com/ouedraogoissouf2012/lms_backend/issues/120).
>
> Structure en **1 PR avec 3 commits atomiques** (voir design.md §5).

---

## Pré-requis

- [x] **P0** — PR #133 (#132 role checks) mergée ⇒ pas de conflit `AuthController::login` (vérifié 2026-05-23)
- [x] **P1** — PR #136 + #137 (PERF-02) mergées ⇒ pattern `Klassci/` 5 collaborateurs disponible
- [x] **P2** — Spec validée par user (cette doc + requirements.md + design.md)
- [ ] **P3** — Sync `lms` local : `git checkout lms && git pull origin lms`
- [ ] **P4** — Branche créée : `git checkout -b refactor/120-extract-user-sync-service`
- [ ] **P5** — Vérifier état initial : `vendor/bin/phpstan analyse` = `[OK]`, `vendor/bin/phpunit` = baseline connue

---

## Commit C1 — NEW : 4 collaborateurs + tests unit

**Scope** : ajouter les 4 nouveaux fichiers de service + leurs tests. **Ne touche PAS encore à `AuthController.php`**. À la fin de C1, le code compile, les tests passent, mais aucun caller n'utilise les nouveaux services (le bug `successful()` reste).

### Tâche C1.1 — `KlassciTenantDiscovery`

- [ ] **C1.1.1** Créer `app/Services/Klassci/Auth/KlassciTenantDiscovery.php` (~120 lignes)
  - Constructor : `HttpFactory $http`, `LoggerInterface $logger`
  - Méthode publique `findMatchingTenants(string $identifier): array`
  - Méthode privée `loadActiveTenants(): array` (encapsule `Institution::where('is_active', true)`)
  - Méthode privée `buildCheckUserRequest(Pool $pool, array $tenant, string $identifier): PendingRequest`
  - Méthode privée `filterMatchingResponses(array $tenants, array $responses, string $identifier): array` ← **FIX BUG `instanceof Response`**
  - Aucune Facade en code métier (DIP §1.6)

- [ ] **C1.1.2** Créer `tests/Unit/Services/Klassci/Auth/KlassciTenantDiscoveryTest.php` (~8 tests)
  - `test_returns_empty_array_when_no_active_tenants`
  - `test_finds_single_matching_tenant`
  - `test_finds_multiple_matching_tenants`
  - `test_skips_tenant_with_connection_exception` ← **garde anti-régression bug**
  - `test_skips_tenant_with_5xx_response`
  - `test_skips_tenant_with_found_false`
  - `test_does_not_log_password_in_warnings`
  - `test_handles_ssl_disabled_correctly`

### Tâche C1.2 — `KlassciAuthClient`

- [ ] **C1.2.1** Créer `app/Services/Klassci/Auth/KlassciAuthClient.php` (~100 lignes)
  - Constructor : `HttpFactory $http`, `LoggerInterface $logger`
  - Méthode publique `attemptLogin(string $tenantUrl, string $username, string $password): ?array`
  - Catch explicite `ConnectionException` → return null
  - Check `$response instanceof Response` avant `->successful()` ← **FIX BUG**

- [ ] **C1.2.2** Créer `tests/Unit/Services/Klassci/Auth/KlassciAuthClientTest.php` (~6 tests)
  - `test_returns_payload_on_successful_login`
  - `test_returns_null_on_connection_exception` ← **garde anti-régression bug**
  - `test_returns_null_on_4xx_response`
  - `test_returns_null_on_payload_success_false`
  - `test_does_not_log_password`
  - `test_handles_ssl_disabled_correctly`

### Tâche C1.3 — `KlassciUserSynchronizer`

- [ ] **C1.3.1** Créer `app/Services/Klassci/Auth/KlassciUserSynchronizer.php` (~150 lignes)
  - Constructor : `ClasseSyncService $classeSyncService`, `LoggerInterface $logger`
  - Méthode publique `sync(array $klassciUser, string $klassciToken, string $tenantUrl, ?Institution $institution): User`
  - Logique extraite **verbatim** de `AuthController::syncUserFromKlassci()` (lignes 388-465)
  - Méthode privée `syncStudentClasses(User $user, string $klassciToken): void` (verbatim de l'ancienne)

- [ ] **C1.3.2** Créer `tests/Unit/Services/Klassci/Auth/KlassciUserSynchronizerTest.php` (~10 tests)
  - `test_creates_new_user_when_klassci_id_unknown`
  - `test_initializes_role_lms_to_klassci_role_on_creation`
  - `test_initializes_klassci_enseignant_id_write_once_on_creation` (issue #119)
  - `test_creates_with_uniqid_password` (vérifie qu'on satisfait NOT NULL)
  - `test_updates_existing_user_by_klassci_id`
  - `test_falls_back_to_email_lookup_when_klassci_id_changes`
  - `test_does_not_overwrite_role_lms_on_update` ← **CRITICAL-05 garde**
  - `test_does_not_overwrite_klassci_enseignant_id_on_update` ← **issue #119 garde**
  - `test_updates_klassci_role_on_update`
  - `test_triggers_sync_classes_only_for_students`

### Tâche C1.4 — `LocalLmsAuthenticator`

- [ ] **C1.4.1** Créer `app/Services/Auth/LocalLmsAuthenticator.php` (~80 lignes)
  - Constructor : `HashContract $hasher`, `LoggerInterface $logger`
  - Méthode publique `attemptLocalAuth(string $identifier, string $password): ?User`

- [ ] **C1.4.2** Créer `tests/Unit/Services/Auth/LocalLmsAuthenticatorTest.php` (~6 tests)
  - `test_returns_user_when_email_matches_and_password_correct`
  - `test_returns_user_when_name_matches_and_password_correct`
  - `test_returns_null_when_user_not_found`
  - `test_returns_null_when_password_incorrect`
  - `test_returns_null_when_password_is_null` (KLASSCI user sans password local)
  - `test_finds_user_cross_institution_via_without_global_scope` (supradmin)

### Tâche C1.5 — Validation locale C1

- [ ] **C1.5.1** `vendor/bin/phpstan analyse` = `[OK] No errors`
- [ ] **C1.5.2** `vendor/bin/phpunit tests/Unit/Services/` passe 100% (32 nouveaux tests verts)
- [ ] **C1.5.3** Vérifier que AuthController existant n'est PAS impacté (suite Unit + Feature passe sans modification)
- [ ] **C1.5.4** Commit atomique : `feat(auth): add 4 DIP collaborators for auth multi-tenant (refs #120, part 1/3)`

---

## Commit C2 — REFACTOR : `AuthController` consomme les collaborateurs

**Scope** : refactor `AuthController.php` pour passer de 528 → ≤ 200 lignes. **Préserve la signature publique** des 5 routes.

### Tâche C2.1 — Constructor étendu

- [ ] **C2.1.1** Modifier le constructor `AuthController::__construct` pour injecter les 4 nouveaux collaborateurs en plus de `KlassciProxyService` + `ClasseSyncService` :
  ```php
  public function __construct(
      private KlassciProxyService $klassciService,       // existant
      private ClasseSyncService $classeSyncService,      // existant
      private LocalLmsAuthenticator $localAuth,          // NEW
      private KlassciTenantDiscovery $tenantDiscovery,   // NEW
      private KlassciAuthClient $klassciAuthClient,      // NEW
      private KlassciUserSynchronizer $userSync,         // NEW
  ) {}
  ```

### Tâche C2.2 — Réécrire `login()`

- [ ] **C2.2.1** Réécrire `AuthController::login()` (≤ 40 lignes) en orchestrant les 3 étapes :
  - Étape 1 : `$user = $this->localAuth->attemptLocalAuth($username, $password)` → si non-null, créer token Sanctum + return success
  - Étape 2 : `$tenants = $this->tenantDiscovery->findMatchingTenants($username)` → si vide, return 401
  - Étape 3 : `foreach ($tenants as $tenant) { $payload = $this->klassciAuthClient->attemptLogin($tenant['api_base_url'], $username, $password); if ($payload) { $user = $this->userSync->sync(...); return success; } }`
  - Si fin de loop sans success : return 401

- [ ] **C2.2.2** Préserver byte-identique le format de réponse JSON (frontend non impacté)

### Tâche C2.3 — Supprimer les méthodes privées migrées

- [ ] **C2.3.1** SUPPRIMER `AuthController::getKlassciTenants()` (logique migrée dans `KlassciTenantDiscovery::loadActiveTenants`)
- [ ] **C2.3.2** SUPPRIMER `AuthController::findTenantsForUser()` (migrée dans `KlassciTenantDiscovery::findMatchingTenants`)
- [ ] **C2.3.3** SUPPRIMER `AuthController::syncUserFromKlassci()` (migrée dans `KlassciUserSynchronizer::sync`)
- [ ] **C2.3.4** SUPPRIMER `AuthController::syncStudentClasses()` (migrée dans `KlassciUserSynchronizer`)
- [ ] **C2.3.5** Nettoyer les `use` statements devenus inutiles (`Pool`, `Http`, `Hash`, `DB`, `Log`)

### Tâche C2.4 — Validation locale C2

- [ ] **C2.4.1** `wc -l app/Http/Controllers/API/AuthController.php` ≤ 200 (§5)
- [ ] **C2.4.2** `vendor/bin/phpstan analyse` = `[OK] No errors`
- [ ] **C2.4.3** `vendor/bin/phpunit tests/` passe 100% (zero régression — REQ-7)
- [ ] **C2.4.4** Test manuel : `php artisan serve` + `curl POST /api/auth/login -d '{"username":"coord.losseni.coulibaly","password":"Bonjour@2026"}'` → **NE doit PAS crash 500**. Doit retourner 401 (si esbtp-abidjan refuse le password) ou 200 (si accept).
- [ ] **C2.4.5** Commit : `refactor(auth): AuthController to thin orchestrator (528 → ≤200 lignes) + fix login crash on tenant down (refs #120, part 2/3)`

---

## Commit C3 — TESTS : test Feature anti-régression bug

**Scope** : ajouter un test Feature qui aurait DÛ exister avant — il mocke un tenant inaccessible et vérifie que le login ne crash pas.

### Tâche C3.1 — Test Feature anti-régression

- [ ] **C3.1.1** Créer `tests/Feature/Auth/LoginCrashOnTenantDownTest.php` :
  - `test_login_does_not_crash_when_one_tenant_returns_connection_exception`
  - `test_login_does_not_crash_when_all_tenants_return_connection_exception`
  - `test_login_uses_only_responding_tenant_when_others_are_down`
  - `test_login_returns_401_when_no_tenant_accepts_credentials`
  - Mock via `Http::fake([...])` avec `Http::response()` ET `function() { throw new ConnectionException(...); }`

- [ ] **C3.1.2** Vérifier que le test échoue sur la branche `lms` PRE-fix (preuve de la régression bug)
- [ ] **C3.1.3** Vérifier que le test passe sur la branche `refactor/120-...` POST-fix (preuve de la correction)

### Tâche C3.2 — Commit final

- [ ] **C3.2.1** Commit : `test(auth): add regression test for login crash on tenant down (refs #120, part 3/3)`

---

## Audits & Validation finale

### Tâche A1 — `spec-security` audit

- [ ] Lancer agent `spec-security` sur les fichiers : `app/Services/Klassci/Auth/`, `app/Services/Auth/`, `app/Http/Controllers/API/AuthController.php`
- [ ] Focus :
  - Aucun token leak dans les logs (test garde inclus dans C1)
  - Préservation CRITICAL-05 (klassci_role séparé)
  - Préservation issue #119 (klassci_enseignant_id write-once)
  - Préservation issue #75 (cross-institution token leak)
  - Pas de nouveau vecteur IDOR

### Tâche A2 — `spec-architect` audit

- [ ] Lancer agent `spec-architect` sur les nouveaux fichiers
- [ ] Focus :
  - §1.1 — Tous fichiers ≤ 300 lignes
  - §5 — Toutes méthodes ≤ 40 lignes, controllers ≤ 200 lignes
  - §1.6 D — DIP : aucune Facade en code métier
  - §1.6 S — SRP : chaque collaborateur a UNE responsabilité
  - Cohérence avec pattern PERF-02

### Tâche A3 — `spec-reviewer` audit final

- [ ] Lancer agent `spec-reviewer` (15 questions §4 PRODUCTION_STANDARDS)
- [ ] Verdict MERGE-READY attendu

### Tâche A4 — Push + PR

- [ ] `git push -u origin refactor/120-extract-user-sync-service`
- [ ] `gh pr create --base lms --head refactor/120-... --title "refactor(auth): extract AuthController to 4 DIP collaborators + fix login crash on tenant down (closes #120)"` avec description complète (mention des 3 audits + métriques avant/après)
- [ ] Attendre approbation user → merge sur `lms`

### Tâche A5 — Post-merge

- [ ] Fermer manuellement issue #120 (le `closes #120` dans le titre devrait suffire mais le projet a un historique de close-manuel)
- [ ] Mettre à jour `REFACTORING_ROADMAP.md` : PERF-04 partiel (split AuthController fait)
- [ ] Mettre à jour `MEMORY.md` si décision architecturale clé émerge

---

## Tâches transverses

- [ ] **T1** Si une nouvelle issue émerge (ex: splitter `LessonController` 553 lignes), la créer séparément
- [ ] **T2** Documenter dans `docs/INTEGRATION_KLASSCI.md` si une section sur l'auth multi-tenant est utile (probablement oui)

---

## Estimation effort cumul

| Section | Effort |
|---------|--------|
| Pré-requis P0-P5 | 15 min |
| C1 (4 collaborateurs + 32 tests) | 3h |
| C2 (refactor AuthController) | 1h30 |
| C3 (test regression) | 30 min |
| Audits A1-A3 + remediation | 2h |
| Push + PR + merge | 30 min |
| Post-merge T1-T2 | 30 min |
| **Total** | **~8h** sur 2-3 sessions |

---

## Critères de done global

La PR est mergeable WHEN :

1. ✓ REQ-1 à REQ-8 implémentés (cf. `requirements.md`)
2. ✓ 3 commits atomiques C1, C2, C3 pushed
3. ✓ Critères d'acceptation (14 items) du `requirements.md` tous cochés
4. ✓ Audits A1, A2, A3 → MERGE-READY
5. ✓ Test manuel login `coord.losseni.coulibaly` → pas de crash 500
6. ✓ Issue #120 close-able via la PR
