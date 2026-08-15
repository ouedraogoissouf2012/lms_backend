# Requirements — #566 : `DELETE /users/{id}` en suppression logique (SoftDeletes)

> Sous-issue P0 de #563 (audit 2026-08-15). Version détaillée : #571.
> Format EARS (WHEN / IF / WHERE / WHILE + SHALL). Approbation requise avant `design.md`.

## Contexte

`app/Http/Controllers/API/AdminController.php:59-64` exécute `$user->delete()` sur un modèle
`User` **sans** trait `SoftDeletes`. C'est une suppression physique. Les tables filles
déclarent `onDelete('cascade')` :

| Table | Ce qui disparaît |
|---|---|
| `quiz_attempts` | Tentatives + notes de quiz |
| `evaluation_submissions` (`student_id`) | Copies + notes d'évaluation |
| `forum_posts` | Contributions au forum |
| `notifications` | Historique de notification |

L'endpoint `DELETE /api/users/{user}` est ouvert à `role:coordinateur,superAdmin`
(`routes/api/core.php:122`) — donc à des utilisateurs métier. Une erreur humaine ordinaire
(suppression d'un doublon) détruit irréversiblement le dossier académique.

## Fil rouge (racine, pas symptôme)

L'action destructive n'est pas réversible et la vérification « la donnée liée doit survivre »
n'existe nulle part. La correction rend la suppression **réversible par défaut** et réserve la
destruction physique à une opération **délibérée et tracée**.

## Exigences

### R1 — La suppression d'un utilisateur est logique, pas physique
- **WHEN** un coordinateur/superAdmin appelle `DELETE /api/users/{user}` sur un utilisateur de
  son institution, le système **SHALL** effectuer un *soft delete* (renseigner `deleted_at`)
  et **SHALL** conserver la ligne `users` en base.
- **WHEN** l'utilisateur est soft-deleted, le système **SHALL** préserver intégralement ses
  `quiz_attempts`, `evaluation_submissions`, `forum_posts` et `notifications`.

### R2 — Un utilisateur soft-deleted disparaît des lectures normales
- **WHEN** une requête liste ou récupère des utilisateurs sans intention explicite d'inclure les
  supprimés, le système **SHALL** exclure les utilisateurs dont `deleted_at` est renseigné.
- **WHERE** le route-model binding `{user}` résout un utilisateur soft-deleted, le système
  **SHALL** répondre `404` (l'utilisateur n'existe plus pour les opérations courantes).

### R3 — Un utilisateur soft-deleted perd immédiatement son accès
- **WHEN** un utilisateur est soft-deleted, le système **SHALL** révoquer immédiatement tous ses
  jetons Sanctum (sans quoi il conserverait l'accès jusqu'à 7 j).
- **IF** une requête présente un jeton d'un utilisateur soft-deleted, le système **SHALL**
  répondre `401`.
- **WHEN** un utilisateur soft-deleted tente une authentification locale
  (`LocalLmsAuthenticator`), le système **SHALL** refuser (comportement souhaité).

### R4 — Traçabilité de la suppression
- **WHEN** un utilisateur est soft-deleted, le système **SHALL** écrire une entrée `audit_logs`
  (action `user.soft_deleted`) mentionnant l'acteur, la cible et le **décompte des enregistrements
  liés conservés** (quiz_attempts, evaluation_submissions, forum_posts, notifications).
- **IF** l'écriture d'audit échoue, le système **SHALL** ne pas casser la suppression métier
  (l'audit est transversal — cf. `AuditLogger` #241), mais **SHALL** garantir l'atomicité
  soft-delete ↔ révocation des jetons.

### R5 — La re-synchronisation KLASSCI restaure, ne duplique pas
- **WHEN** un utilisateur préalablement soft-deleted se re-connecte via KLASSCI
  (`KlassciUserSynchronizer::sync`), le système **SHALL** retrouver la ligne existante
  (`withTrashed`) et la **restaurer** (`deleted_at = null`), puis la mettre à jour.
- **IF** la restauration n'était pas faite, un INSERT violerait
  `users_klassci_id_institution_id_unique` — le système **SHALL** empêcher cette violation.

### R6 — Purge définitive délibérée (droit à l'effacement RGPD)
- **WHERE** une purge physique est nécessaire (demande RGPD), le système **SHALL** l'exposer via
  une commande console dédiée, **dry-run par défaut** (aucune destruction sans indicateur
  explicite `--force`).
- **WHEN** la commande purge réellement, le système **SHALL** journaliser chaque purge dans
  `audit_logs`.
- La purge **SHALL NE PAS** être planifiée automatiquement (une destruction n'est jamais un
  geste passif).

## Hors périmètre (déclaré)

- Modification de `BelongsToInstitution` et `ResolveInstitution` (sous-issue #565 / #567-SUB2).
- Interaction résiduelle : recréation manuelle (`createUser`) d'un email appartenant à un
  utilisateur soft-deleted de la même institution → collision d'unicité. Documentée en
  `design.md` §Risques ; l'admin doit restaurer plutôt que recréer. Non couvert ici (le chemin
  métier réel de recréation passe par KLASSCI, traité par R5).

## Critères d'acceptation (traçant #571)

- [ ] Test : suppression → l'utilisateur n'apparaît plus dans les listes ; ses `quiz_attempts` et
      `evaluation_submissions` existent toujours en base. _(R1, R2)_
- [ ] Test : suppression → jetons révoqués, requête suivante `401`. _(R3)_
- [ ] Test : re-sync KLASSCI d'un utilisateur supprimé → restauration, **pas** de violation
      d'index unique. _(R5)_
- [ ] Test : connexion locale d'un utilisateur supprimé → refusée. _(R3)_
- [ ] Test : entrée `audit_logs` vérifiée (action + décompte). _(R4)_
- [ ] Test : commande de purge en dry-run ne détruit rien ; avec `--force` purge et audite. _(R6)_
- [ ] `php artisan test` 100 %, PHPStan niveau 9 vert, `php artisan migrate` à blanc OK.
