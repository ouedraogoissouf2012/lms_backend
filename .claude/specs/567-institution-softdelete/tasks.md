# Tasks — #567 : suppression logique des institutions

> Checklist hiérarchique. TDD strict (RED avant impl).

- [x] 1. Tests RED
  - [x] 1.1 `InstitutionSoftDeleteTest` : active → 422 ; inactive → soft delete + ligne présente _(R1,R2)_
  - [x] 1.2 `InstitutionSoftDeleteTest` : jeton d'un user du tenant révoqué → 401 _(R3)_
  - [x] 1.3 `InstitutionSoftDeleteTest` : audit `institution.soft_deleted` + `restore()` _(R4,R1)_
  - [x] 1.4 `InstitutionDiscoveryExcludesSoftDeletedTest` : discovery ignore les supprimées _(R5)_
  - [x] 1.5 `PurgeSoftDeletedInstitutionsTest` : dry-run inerte ; `--force` refuse si users, purge sinon _(R6)_

- [x] 2. Migration `deleted_at` institutions
  - [x] 2.1 `2026_08_15_130000_add_deleted_at_to_institutions_table.php` : `softDeletes()` + index _(R1)_

- [x] 3. Modèle `Institution`
  - [x] 3.1 `use SoftDeletes` + import _(R1,R5)_

- [x] 4. `InstitutionCrudService::softDelete`
  - [x] 4.1 DI `AuditLogger` (constructeur) _(R4)_
  - [x] 4.2 Garde `is_active` → `BusinessException` si active _(R2)_
  - [x] 4.3 Révocation des jetons des users du tenant (sous-requête bornée) _(R3)_
  - [x] 4.4 `delete()` (soft) + `logSecurityEvent('institution.soft_deleted', ...)` + `invalidateCaches()` _(R1,R4)_

- [x] 5. Contrôleur
  - [x] 5.1 `InstitutionController::destroy` : `catch (BusinessException) → businessError()` (422) _(R2)_

- [x] 6. Migration `encrypt_klassci_tokens`
  - [x] 6.1 Ligne 106 : `Institution::withoutGlobalScopes()->whereNotNull(...)` _(migrate:fresh)_

- [x] 7. Commande de purge
  - [x] 7.1 `PurgeSoftDeletedInstitutions` : dry-run par défaut, refuse si users subsistent, `forceDelete()`+audit sinon, non planifiée _(R6)_

- [x] 8. Validation finale
  - [x] 8.1 Suite impactée verte (Institution/Auth/Console + Security) _(R1-R6)_
  - [x] 8.2 PHPStan niveau 9 = 0 erreur _(§1.6)_
  - [x] 8.3 Revue qualité (production-grade + `/code-review` + spec-security/architect) _(§4)_
  - [x] 8.4 `migrate:fresh` à blanc + garde tailles (≤300/200/150) _(§1.1)_
