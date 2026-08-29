# Tasks — #566 : suppression logique des utilisateurs

> Checklist hiérarchique (max 2 niveaux). Chaque tâche référence un requirement.
> TDD strict : les tests RED (tâche 1) précèdent l'implémentation.

- [x] 1. Tests RED — prouver la destruction actuelle et spécifier le comportement cible
  - [x] 1.1 `tests/Feature/Admin/UserSoftDeleteTest.php` : suppression détruit les filles
        AUJOURD'HUI (assertDatabaseMissing quiz_attempts/evaluation_submissions) _(R1)_
  - [x] 1.2 Cas GREEN attendus (soft delete, filles préservées, 404 binding, 401 jeton) _(R1,R2,R3)_
  - [x] 1.3 `tests/Feature/Auth/SoftDeletedUserAuthTest.php` : re-sync restaure sans doublon _(R5)_
  - [x] 1.4 `tests/Feature/Auth/SoftDeletedUserAuthTest.php` : login local d'un soft-deleted refusé _(R3)_
  - [x] 1.7 `tests/Feature/Forum/ForumAuthorSurvivesSoftDeleteTest.php` : régression auteur null (withTrashed)
  - [x] 1.5 `tests/Unit/Services/User/UserDeletionServiceTest.php` : révocation + audit + atomicité _(R3,R4)_
  - [x] 1.6 `tests/Feature/Console/PurgeSoftDeletedUsersTest.php` : dry-run inerte, `--force` purge+audite _(R6)_

- [x] 2. Migration `deleted_at`
  - [x] 2.1 `2026_08_15_120000_add_deleted_at_to_users_table.php` : `softDeletes()` + `index('deleted_at')` _(R1)_
  - [x] 2.2 `down()` réversible ; `php artisan migrate` à blanc vérifié _(R1)_

- [x] 3. Modèle `User`
  - [x] 3.1 Ajouter `use Illuminate\Database\Eloquent\SoftDeletes;` au `use` du modèle _(R1,R2)_
  - [x] 3.2 Relation `evaluationSubmissions()` (HasMany, `student_id`) pour le décompte, modèle < 150 l. _(R4)_

- [x] 4. `UserDeletionService`
  - [x] 4.1 `app/Services/User/UserDeletionService.php` : DI `ConnectionInterface` + `AuditLogger` _(R1,R3,R4)_
  - [x] 4.2 `softDelete(User $user)` en transaction : décompte → `tokens()->delete()` → `delete()` →
        `logSecurityEvent('user.soft_deleted', ...)`, méthode ≤ 40 l. _(R1,R3,R4)_

- [x] 5. Contrôleur
  - [x] 5.1 `AdminController::deleteUser` : method injection du service, délégation, reste ≤ 200 l. _(R1)_

- [x] 6. Re-sync KLASSCI (R5)
  - [x] 6.1 `KlassciUserSynchronizer::findExistingUser` : `withTrashed()` sur les 2 requêtes _(R5)_
  - [x] 6.2 `doSync` : si `$user->trashed()` → `restore()` + log info avant `update()` _(R5)_

- [x] 7. Commande de purge
  - [x] 7.1 `PurgeSoftDeletedUsers` : `users:purge-deleted {--force} {--days=30}`, dry-run par défaut,
        `forceDelete()` + audit par ligne, NON planifiée _(R6)_

- [x] 8. Validation finale
  - [x] 8.1 Suite impactée verte en local (Feature Admin/Auth/Console + Unit) _(R1-R6)_
  - [x] 8.2 PHPStan niveau 9 = 0 erreur (pas de rebaseline aveugle) _(§1.6)_
  - [x] 8.3 Revue qualité (`/thermo-nuclear-code-quality-review` ou production-grade + `/code-review`) _(§4)_
  - [x] 8.4 `php artisan migrate:fresh` à blanc + garde taille fichiers (≤300/200/150) _(§1.1)_
