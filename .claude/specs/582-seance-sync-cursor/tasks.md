# Tasks — #582 · Reprise par curseur de la sync des séances

Ordre imposé par le TDD : les tests RED (tâche 1) précèdent toute implémentation.

- [x] 1. Tests RED prouvant la famine et l'archivage inerte
  - [x] 1.1 `tests/Feature/Services/Seances/SeanceSyncCursorTest.php` — disjonction
        de deux passes, bouclage du curseur, archivage par tenant, garde-fou
        souillure, métriques de passe. _Requirements: R1, R4, R5, R6_
  - [x] 1.2 `tests/Unit/Services/Seances/Cursor/TeacherCursorStreamTest.php` —
        flux paresseux, positionnement keyset, et garde « colonnes réelles »
        reproduisant sur SQLite le défaut MySQL de la colonne supprimée.
        _Requirements: R2, R3_
  - [x] 1.3 `tests/Unit/Services/Seances/Cursor/EloquentSeanceSyncCursorStoreTest.php`
        — persistance, relecture, remise à zéro, souillure. _Requirements: R1, R5_

- [x] 2. Schéma
  - [x] 2.1 Migration `add_synced_at_to_seances_table` (+ index de balayage).
        _Requirements: R4_
  - [x] 2.2 Migration `create_seance_sync_cursors_table`. _Requirements: R1, R5_
  - [x] 2.3 Migration `add_sync_scan_index_to_users_table`. _Requirements: R2_
  - [x] 2.4 `App\Models\Seance` — `synced_at` en `fillable` + cast `datetime`.
        _Requirements: R4_
  - [x] 2.5 `App\Models\SeanceSyncCursor` — casts uniquement. _Requirements: R1_

- [x] 3. Curseur
  - [x] 3.1 `SeanceSyncPosition` (valeur immuable). _Requirements: R1, R5_
  - [x] 3.2 `SeanceSyncCursorStore` (interface) + `EloquentSeanceSyncCursorStore`.
        _Requirements: R1_
  - [x] 3.3 `TeacherCursorStream` — requête keyset, colonnes réelles, flux paresseux.
        _Requirements: R1, R2, R3_
  - [x] 3.4 Liaison de l'interface dans `AppServiceProvider`. _Requirements: R1_

- [x] 4. Archivage par tenant
  - [x] 4.1 `SeanceSyncStamper` — marquage `synced_at` par enseignant.
        _Requirements: R4_
  - [x] 4.2 `StaleSeanceArchiver` — balayage `(tenant, cycle)` au lieu de la liste
        d'identifiants. _Requirements: R4_
  - [x] 4.3 `SeanceSyncCycleState` + `TenantArchiveCoordinator` — frontières de
        tenant et garde-fou souillure. _Requirements: R4, R5_

- [x] 5. Orchestration
  - [x] 5.1 `SeanceUpsertService` — extraction mécanique de `upsertSeance` /
        `createSeance` (§1.1). _Requirements: R7_
  - [x] 5.2 `KlassciSeancesSyncService::sync()` — flux, budget, frontières,
        sauvegarde du curseur. _Requirements: R1, R2, R3, R4, R5_
  - [x] 5.3 `SeanceSyncStats` + journal de passe. _Requirements: R6_

- [x] 6. Validation
  - [x] 6.1 Suites impactées vertes (sync, drain, archiver, schedule, tenant).
  - [x] 6.2 Suite complète verte (`KlassciSeancesSyncService` est partagé).
  - [x] 6.3 PHPStan level 9 sans nouvelle entrée de baseline.
  - [x] 6.4 Revue qualité finale + réponses aux 15 questions.
