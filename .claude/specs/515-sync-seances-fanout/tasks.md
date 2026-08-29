# Tasks — #515 [PERF][HIGH] SyncKlassciSeances : N+1 HTTP → batch

- [x] 1. Test RED : nouveau test anti-N+1 HTTP
  - Ajouté à `tests/Feature/Services/Seances/KlassciSeancesSyncServiceTest.php`
    (fichier existant, plus cohérent qu'un nouveau fichier).
  - Mock `KlassciProxyService::fetchManyMatieresDetails()`, assertion
    `->once()` avec la liste complète des IDs matières d'un enseignant.
  - Baseline-vs-afterGrowth : 3 matières vs 30 matières → 1 seul appel
    `fetchManyMatieresDetails()` dans les deux cas.
  - Confirmé RED avant le fix (le code appelait encore `requestWithUserToken`
    par matière).
  - _Requirements: R1_

- [x] 2. GREEN : refactor `KlassciSeancesSyncService`
  - `syncTeacherMatieres()` délègue à `TeacherMatieresResolver::resolve()`.
    `syncMatiereSeances()` reçoit `$details` au lieu de le fetcher elle-même.
    `upsertSeance`/`createSeance` inchangées.
  - _Requirements: R1, R2, R3_

- [x] 2bis. Garde-fou taille (§1.1 ≤300 lignes) — 2 classes extraites
  - `TeacherMatieresResolver.php` (batch-fetch, ne dépend que de
    `KlassciProxyService`) et `StaleSeanceArchiver.php` (archivage, extraction
    verbatim) — nécessaire, `KlassciSeancesSyncService.php` seul atteignait
    333 lignes avec le batch inline.
  - _Requirements: R1 (contrainte PRODUCTION_STANDARDS.md §1.1)_

- [x] 3. Mettre à jour les tests cassés par le changement de mécanisme HTTP
  - `tests/Feature/Services/Seances/KlassciSeancesSyncServiceTest.php` (2 tests),
    `tests/Feature/LMS/Seances/SeanceTenantIsolationTest.php` (2 tests),
    `tests/Feature/Jobs/DrainBudgetTest.php` (1 test) — tous mockaient
    `requestWithUserToken('token', 'matieres/{id}', 'GET')`, remplacé par
    `fetchManyMatieresDetails`. Trouvés par exécution de la suite complète
    (pas seulement le fichier direct), leçon : toujours lancer la suite
    complète après un changement de mécanisme HTTP partagé.
  - _Requirements: R2_

- [x] 4. Test tolérance partielle + comptage restauré (R3)
  - 2 matières pour un enseignant, `fetchManyMatieresDetails()` mocké pour
    n'en retourner qu'une → la matière présente est synchronisée normalement
    (isolement, comportement amélioré assumé) ET `stats->errors` est
    incrémenté pour la matière omise (signal restauré après finding de revue
    CRITIQUE — voir tâche 5bis).
  - _Requirements: R3_

- [x] 5. Non-régression : suite Seances/Sync complète + PHPStan
  - Suite complète (1576 tests) verte après correction des 3 tests cassés.
  - PHPStan scope `app/` uniquement (leçon : `phpstan.neon.dist` n'analyse pas
    `tests/` — ne pas lancer PHPStan sur des fichiers de test, hors périmètre
    du garde-fou CI réel).

- [x] 5bis. `/code-review effort max` (fallback de `/thermo-nuclear-code-quality-review`,
      indisponible dans cette session) — 8 agents (3 correctness, 3 cleanup,
      altitude, conventions). Findings traités :
  - **CRITIQUE (removed-behavior)** : le passage au batch faisait disparaître
    `stats->errors` pour une matière en échec HTTP (`KlassciBatchFetcher`
    omet silencieusement, ne lève jamais). Corrigé : nouveau DTO
    `TeacherMatieresResolution` (`resolved` + `failedMatiereIds`), comptage
    restauré dans `syncTeacherMatieres()`. Voir design.md §3.
  - **Fort (simplification)** : le shape `array{matiere, details}` retourné
    par `resolve()` dupliquait le problème que `SeanceSyncStats` avait été
    créée pour éviter (array fragile non typé). Corrigé : DTOs
    `ResolvedMatiere` + `TeacherMatieresResolution`.
  - **Moyen (reuse)** : `indexMatieresById()` dupliquait
    `KlassciSeanceLookupService::keyByMatiereId()`. Corrigé : nouvelle méthode
    partagée `KlassciPayload::keyById()`, même idiome que `uniqueIntIds()`
    déjà présent (#517).
  - **Moyen (spec drift)** : design.md ne reflétait pas l'extraction en
    classes. Corrigé : design.md réécrit pour refléter l'architecture livrée.
  - **Bug confirmé** : `TeacherMatieresResolverTest.php` cassé après le
    changement de shape de retour (array → DTO), jamais mis à jour. Corrigé.
  - **Dette tracée, PAS corrigée ici** : `StaleSeanceArchiver` peut archiver à
    tort une séance si l'échec d'une matière est transitoire (réseau) plutôt
    qu'une vraie suppression KLASSCI — risque pré-existant (pas introduit par
    #515), documenté explicitement en design.md §5, recommandation d'ouvrir
    une issue de suivi dédiée plutôt que d'élargir cette PR.
  - 0 finding sur les angles efficiency/altitude/conventions au-delà de ce qui
    précède (rapportés PASS par les agents dédiés).

- [ ] 6. Audits `spec-security` + `spec-architect` en parallèle (CONTRIBUTING.md §A)

- [ ] 7. PR vers `lms`, reporter le numéro à l'orchestrateur — mentionner
      explicitement la recommandation d'issue de suivi (tâche 5bis, dette
      tracée) dans la description de la PR.
