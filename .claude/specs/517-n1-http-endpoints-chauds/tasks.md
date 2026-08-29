# Tasks — #517 N+1 HTTP sur endpoints chauds

- [x] 1. Requirements + Design approuvés (voir requirements.md / design.md)

- [ ] 2. H3 — KlassciSeanceLookupService
  - [ ] 2.1 Créer `App\Services\Seances\LocalSeanceMatiereResolver` (_Requirements: R1, R3_)
  - [ ] 2.2 Refactorer `KlassciSeanceLookupService` : `findSeanceAmongMatieres()` unifié +
        `tryLocalFastPath()` + `keyByMatiereId()`, suppression de `stringId()` mort
        (_Requirements: R1, R2, R3, R6, R7_)
  - [ ] 2.3 Mettre à jour `KlassciSeanceLookupServiceTest` (mock `fetchManyMatieresDetails`) +
        ajouter cas fast-path local et fallback batch (_Requirements: R1, R2, R3, R6_)

- [ ] 3. H4 — MatiereSeancesFetcher
  - [ ] 3.1 Injecter `LocalSeanceLookup`, remplacer les lookups SQL unitaires
        (`filterHiddenAndArchivedForStudent`, `enrichSeances`) par `preload()` + accès mémoire
        (_Requirements: R4, R6, R7_)
  - [ ] 3.2 Batcher les appels `classes/{id}` via `fetchManyClassesDetails()` dans `enrichSeances()`
        (_Requirements: R4, R6_)
  - [ ] 3.3 Nouveau test `MatiereSeancesFetcherTest` (baseline vs afterGrowth, N+1 SQL + HTTP)
        (_Requirements: R4, R6_)

- [ ] 4. H5 — KlassciClassesFetcher
  - [ ] 4.1 Batcher `fetchAllClassesWithDetails()` via `fetchManyClassesDetails()`
        (_Requirements: R5, R6_)
  - [ ] 4.2 Batcher `fetchTeacherClasses()` via `fetchManyMatieresDetails()` (_Requirements: R5, R6_)
  - [ ] 4.3 Mettre à jour `ClasseSyncServiceTest` (mock batch) + nouveau
        `KlassciClassesFetcherTest` (preuve batch fallback enseignant) (_Requirements: R5, R6_)

- [ ] 5. Validation
  - [ ] 5.1 `php artisan test` sur le périmètre (Seances, Matiere, Sync/Classes) = 100%
        (_Requirements: R6_)
  - [ ] 5.2 PHPStan level 9 = 0 erreur sur les fichiers modifiés (_Requirements: R7_)
  - [ ] 5.3 `php scripts/check-file-sizes.php` sur les fichiers modifiés = 0 dépassement
        (_Requirements: R7_)
  - [ ] 5.4 Revue qualité (production-grade-standards / 15 questions self-critique)
