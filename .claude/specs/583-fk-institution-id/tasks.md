# Tasks — #583 · Clés étrangères manquantes sur `institution_id`

- [ ] 1. Source unique de vérité
  - [ ] 1.1 Créer `config/tenancy.php` avec `institution_scoped_tables` (30 tables),
        commenté « dérivé de la migration 2026_02_11_000002 ». _(REQ-1)_

- [ ] 2. Inspecteur d'intégrité (TDD)
  - [ ] 2.1 Écrire `tests/Unit/Services/Tenancy/InstitutionIntegrityInspectorTest.php`
        (null/orphan/report/orphans/scopedTablesPresent/hasInstitutionForeignKey). _(REQ-2, REQ-5)_
  - [ ] 2.2 Implémenter `App\Services\Tenancy\InstitutionIntegrityInspector`
        (DI `DatabaseManager`, méthodes ≤ 40 lignes, query builder brut). _(REQ-1, REQ-2, REQ-5)_
  - [ ] 2.3 Vert.

- [ ] 3. Commande d'audit read-only (TDD)
  - [ ] 3.1 Écrire `tests/Feature/Console/AuditInstitutionOrphansTest.php`
        (comptes, `--json`, aucune écriture via `DB::listen`). _(REQ-2)_
  - [ ] 3.2 Implémenter `App\Console\Commands\AuditInstitutionOrphans`. _(REQ-2)_
  - [ ] 3.3 Vert.

- [ ] 4. Migration FK (TDD)
  - [ ] 4.1 Écrire `tests/Feature/Database/InstitutionForeignKeyTest.php`
        (INSERT orphelin rejeté, DELETE RESTRICT, NULL accepté, 30 FK présentes). _(REQ-4, REQ-6)_
  - [ ] 4.2 Écrire `tests/Feature/Database/InstitutionForeignKeyMigrationGuardTest.php`
        (down → orphelin → up throw, aucune FK reposée). _(REQ-3, REQ-6)_
  - [ ] 4.3 Implémenter `database/migrations/2026_08_15_140000_add_institution_id_foreign_keys.php`
        (garde pré-vol AVANT DDL, idempotence, `down()`). _(REQ-3, REQ-4, REQ-5)_
  - [ ] 4.4 Vert.

- [ ] 5. Validation & qualité
  - [ ] 5.1 `php artisan migrate:fresh` puis `migrate:rollback` du dernier batch OK (down testé). _(REQ-5)_
  - [ ] 5.2 Suite impactée verte en local (SQLite, FK on).
  - [ ] 5.3 PHPStan level 9 : 0 erreur (pas d'ajout baseline aveugle).
  - [ ] 5.4 Revue `/thermo-nuclear-code-quality-review` (fallback : production-grade-standards + `/code-review`).
  - [ ] 5.5 15 questions self-critique + surface proactive des dettes (mesure prod, nettoyage, #574) dans la PR.

- [ ] 6. Livraison (après accord user)
  - [ ] 6.1 Commit conventionnel (`chore(db)`/`fix(db)`, sujet ≤ 70, Co-Authored-By).
  - [ ] 6.2 PR vers `lms`, reporter le n° à l'orchestrateur.
