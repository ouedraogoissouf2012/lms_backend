# Tasks — #578 · Circuit breaker KLASSCI cloisonné par cible

> Ordre TDD strict : tests d'abord (rouge), puis implémentation (vert), puis
> validation. Chaque tâche référence un requirement.

- [ ] 1. **Tests unitaires du breaker (rouge d'abord)**
  - [ ] 1.1 Créer `tests/Unit/Services/Klassci/KlassciCircuitBreakerTest.php` avec
        un double `FakeTargetResolver implements KlassciTargetResolver` et un store
        cache `array` réel. _Requirements: 5.2_
  - [ ] 1.2 `test_failures_on_A_do_not_open_B` : 3 échecs cible A → `isOpen()` true
        pour A, false pour B. _Requirements: 1.1, 1.2_
  - [ ] 1.3 `test_success_on_B_preserves_A_failure_counter` : le compteur de A
        survit à un succès de B. _Requirements: 2.1_
  - [ ] 1.4 `test_same_base_url_shares_breaker` : deux résolveurs même URL →
        disjoncteur partagé. _Requirements: 3.1_
  - [ ] 1.5 `test_null_base_url_uses_default_partition` : cible null → `default`,
        isolée des cibles réelles. _Requirements: 4.1_
  - [ ] 1.6 `test_disabled_flag_prevents_open` : `circuit_breaker_enabled=false` →
        jamais d'ouverture. _Requirements: 6.1_

- [ ] 2. **Abstraction de cible**
  - [ ] 2.1 Créer l'interface `app/Services/Klassci/KlassciTargetResolver.php`
        (`baseUrl(): ?string`). _Requirements: 5.1, 5.2_
  - [ ] 2.2 `KlassciConfigResolver implements KlassciTargetResolver` (aucun autre
        changement — signature déjà conforme). _Requirements: 5.1_

- [ ] 3. **Cloisonnement du breaker**
  - [ ] 3.1 Injecter `KlassciTargetResolver` au constructeur de
        `KlassciCircuitBreaker`. _Requirements: 5.1_
  - [ ] 3.2 Remplacer les constantes par `failuresKey()` / `openUntilKey()`
        dérivant `partition()` (sha256 ou `default`). _Requirements: 1, 2, 4_
  - [ ] 3.3 Router `isOpen()`, `secondsUntilRetry()`, `reportSuccess()`,
        `reportFailure()` sur les clés dérivées. _Requirements: 1, 2, 6.2_

- [ ] 4. **Wiring DI**
  - [ ] 4.1 `AppServiceProvider` : `scoped(KlassciConfigResolver::class)` +
        `bind(KlassciTargetResolver::class, KlassciConfigResolver::class)`.
        _Requirements: 5.1, 6.2_

- [ ] 5. **Raccord des tests existants**
  - [ ] 5.1 `KlassciHttpClientTest::circuitBreaker()` : injecter un résolveur de
        cible (contrat constructeur). _Requirements: 5.1_

- [ ] 6. **Validation (Phase 4)**
  - [ ] 6.1 Suite impactée verte : `KlassciCircuitBreakerTest` +
        `KlassciHttpClientTest` + `KlassciConfigResolverUrlGuardTest`. _Requirements: 6_
  - [ ] 6.2 PHPStan level 9 = 0 erreur sur les fichiers touchés. _Requirements: NFR_
  - [ ] 6.3 Sweep `new KlassciCircuitBreaker(` — 0 site non mis à jour. _Requirements: NFR_
  - [ ] 6.4 Revue qualité (`/thermo-nuclear-code-quality-review` ou fallback). _Requirements: NFR_
