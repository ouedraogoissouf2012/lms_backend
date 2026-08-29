# Tasks — N+1 DB + dashboard admin non caché (#546)

- [x] 1. Site 1 — Cache `AdminDashboardService::buildStats`
  - [x] 1.1 Tests RED : cache hit (1 seule agrégation sur 2 appels), isolation
        inter-institution, isolation intra-institution par `klassci_tenant_url`
        _Requirements: 1, 2_
  - [x] 1.2 Implémentation : injecter `CacheRepository`/`TenantManager`/
        `LoggerInterface`, `cacheKey()`, `remember()` 300s
        _Requirements: 1, 2_
  - [x] 1.3 Sweep constructeur : mettre à jour `AdminDashboardServiceTest`
        (`new AdminDashboardService()` → avec dépendances) + fix
        `DashboardAdminResponseTest` (`Sanctum::actingAs` → Bearer réel, le
        cache exige un tenant résolu, cf. `DashboardAdminStatsIsolationTest`)
        _Requirements: 5_

- [x] 2. Site 2 — Batching tentatives quiz (`QuizCrudService::list`)
  - [x] 2.1 Test RED anti-N+1 : requêtes constantes quand le nombre de quiz
        sur la page croît (pattern baseline vs afterGrowth)
        _Requirements: 3, 6_
  - [x] 2.2 Test RED correctness : `user_attempts_count`/`user_can_attempt`/
        `user_best_attempt` identiques au comportement actuel (quota atteint,
        quiz indisponible, meilleur score)
        _Requirements: 3, 5_
  - [x] 2.3 Implémentation : `QuizAccessService::finalizedAttemptsByQuiz()` +
        `QuizCrudService::list()` batché
        _Requirements: 3, 5, 6_

- [x] 3. Site 3 — Batching stats matières (`MyMatieresQueryService`)
  - [x] 3.1 Test RED anti-N+1 : requêtes constantes quand le nombre de
        matières KLASSCI croît
        _Requirements: 4, 6_
  - [x] 3.2 Test RED correctness : compteurs publiés/brouillons/séances
        identiques par matière (multi-matières, matière sans lesson)
        _Requirements: 4, 5_
  - [x] 3.3 Implémentation : `preloadStats()` + `enrichMatiere()` batché
        _Requirements: 4, 5, 6_

- [x] 4. Validation finale
  - [x] 4.1 `php artisan test` périmètre (Dashboard, Quiz, Matiere) 100%
  - [x] 4.2 PHPStan level 9 = 0 nouvelle erreur (scope des 4 fichiers modifiés)
  - [x] 4.3 Garde tailles (`check-file-sizes.php`) : OK sur tous les fichiers modifiés
  - [x] 4.4 Suite complète 1528 passed / 0 failed (3 skips pré-existants
        environnementaux : Redis absent, Imagick absent, quirk Windows
        Http::fake) avant push
