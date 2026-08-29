# Tasks — #547 : PDF async par défaut + purge tenant réelle (store `database`)

> TDD strict : chaque tâche de code est précédée de son test RED.
> Marquer `- [x]` immédiatement après complétion (CONTRIBUTING §A.4).

## 1. Requirement 1 — PDF async par défaut

- [ ] 1.1 RED — Feature test : `POST /api/admin/reports/attendance` **sans flag** →
  `202` + `Queue::assertPushedOn('low', GenerateReportPdf::class)`. Échoue aujourd'hui
  (défaut = sync → 200 binaire). _Requirements: 1.1_
- [ ] 1.2 RED — Feature test : `?sync=1` → `200` PDF binaire ; `?async=1` → `202`
  (rétro-compat) ; `sync=1&async=1` → `202` (async l'emporte). _Requirements: 1.2, 1.3, 1.4_
- [ ] 1.3 GREEN — `ReportController` : remplacer `wantsAsync()` par `wantsSync()`,
  inverser les 3 conditions d'action. Aucune route/signature modifiée. _Requirements: 1.1–1.4_
- [ ] 1.4 Vérifier non-régression isolation worker (#536) : tests existants
  `AsyncReportTenantIsolationTest` verts. _Requirements: 1.5_

## 2. Requirement 2 — Abstraction de purge (DIP/OCP/LSP)

- [ ] 2.1 RED — Unit test factory : `TenantCachePurgerFactory` retourne `TaggedCachePurger`
  si `supportsTags()`, `DatabaseCachePurger` si store `DatabaseStore`, `NullCachePurger`
  sinon. _Requirements: 2.6_
- [ ] 2.2 GREEN — `TenantCachePurgerInterface { purge(string $namespace): void }`. _Requirements: 2.6_
- [ ] 2.3 GREEN — `TaggedCachePurger` : `tags([ns])->flush()` (extrait le comportement
  Redis actuel). _Requirements: 2.2_
- [ ] 2.4 GREEN — `NullCachePurger` : `warning('tenant_cache.flush_skipped_unsupported_store')`,
  aucun flush global. _Requirements: 2.3_
- [ ] 2.5 GREEN — `DatabaseCachePurger` : `DELETE ... WHERE key LIKE prefix+ns+':' + '%'`,
  prefix/connection/table lus du `DatabaseStore`. `escapeLike()` sur le namespace. Log
  du nombre supprimé. _Requirements: 2.1, 2.5_
- [ ] 2.6 GREEN — `TenantCachePurgerFactory::make(Repository): TenantCachePurgerInterface`. _Requirements: 2.6_

## 3. Requirement 2/3 — Intégration TenantScopedCache

- [ ] 3.1 RED — Unit : `remember()` sur store sans tags → clé préfixée `institution_X:key`
  (idempotent si déjà préfixée). _Requirements: 2.4_
- [ ] 3.2 RED — Feature (store `database`) : après `remember()` de 2 tenants,
  `flushTenant()` du tenant A supprime SES lignes `cache` et laisse celles de B. _Requirements: 2.1, 3.1_
- [ ] 3.3 RED — Unit : tenant non résolu → namespace `institution_none`. _Requirements: 3.2_
- [ ] 3.4 GREEN — `TenantScopedCache` : injecter `TenantCachePurgerInterface` ;
  `remember()` namespace la clé (branche sans tags) ; `flushTenant()` délègue
  `purger->purge(tenantTag())`. Retirer la branche `if supportsTags` de `flushTenant()`. _Requirements: 2.1–2.4, 3.1, 3.2_
- [ ] 3.5 GREEN — `AppServiceProvider` : bind `TenantCachePurgerInterface` via la
  factory avec le Repository concret déjà résolu (bloc #374). _Requirements: 2.6_
- [ ] 3.6 Adapter `TenantScopedCacheTest` existant au purger injecté (les 5 tests
  restent, la branche flush no-op migre vers `NullCachePurgerTest`). _Requirements: 4.1_

## 4. Validation finale

- [ ] 4.1 `php artisan test` sur le périmètre (Cache, Reports, KlassciProxy) = 100%. _Requirements: 4.4_
- [ ] 4.2 `phpstan analyse` = 0 erreur (pas de rebaseline aveugle). _Requirements: 4.3_
- [ ] 4.3 `wc -l` : chaque fichier ≤300, méthodes ≤40. _Requirements: 4.3_
- [ ] 4.4 Audits `spec-security` + `spec-architect` + `spec-reviewer` (ou
  `/thermo-nuclear-code-quality-review`) : aucun CRITICAL/HIGH. _Requirements: 4.1–4.4_
