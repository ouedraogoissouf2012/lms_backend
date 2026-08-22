# Requirements — #580 : unicité de l'email PAR INSTITUTION (et non globale)

> Sous-issue P1 de #563 (audit 2026-08-15).
> Format EARS (WHEN / IF / WHERE / WHILE + SHALL).

## Contexte mesuré

Deux règles de validation interrogent la table `users` **sans** scope tenant :

| Fichier:ligne | Règle actuelle |
|---|---|
| `app/Http/Requests/CreateUserRequest.php:39` | `required\|email\|unique:users,email` |
| `app/Http/Requests/UpdateUserRequest.php:35` | `sometimes\|email\|unique:users,email,` . `$userId` |

`unique:` passe par le `DatabasePresenceVerifier`, qui construit un `DB::table('users')` —
**query builder brut**, donc ni le global scope `institution`
(`app/Models/Traits/BelongsToInstitution.php:79`) ni le `SoftDeletingScope` ne s'appliquent.
La contrainte est **globale à la plateforme**.

Or le schéma dit l'inverse :

- `2026_03_23_121616_drop_email_unique_from_users_table.php` **supprime** l'unicité globale
  de `email` ;
- `2026_03_23_200000_add_unique_email_institution_to_users_table.php` la remplace par l'index
  composite `users_email_institution_unique` sur `(email, institution_id)`.

Le reste du code applique déjà la règle par institution :
`KlassciEmailConflictGuard::assertNotOwnedByAnother()`
(`app/Services/Klassci/Auth/KlassciEmailConflictGuard.php:46-48`) scope explicitement sur
`institution_id`, et `KlassciUserSynchronizer::findExistingUser()` (`:175-190`) cherche par
`(klassci_id, institution_id)` puis `(email, institution_id)`.
**Seules les deux FormRequests d'administration divergent.**

## Fil rouge (racine, pas symptôme)

La racine n'est pas « il manque un `where` » : c'est que **la validation n'exprime pas la
contrainte que la base fait respecter**. Toute divergence entre les deux produit soit un refus
illégitime (validation plus stricte que la base — le bug d'aujourd'hui), soit une `500`
(validation plus permissive que la base — le piège des soft deletes).

L'exigence transverse est donc : **la règle de validation doit être l'image exacte de l'index
`users_email_institution_unique`**, y compris ses angles morts.

## Exigences

### R1 — L'unicité de l'email est évaluée dans l'institution cible

- **WHEN** un administrateur de l'école B crée un utilisateur dont l'email existe déjà dans
  l'école A, le système **SHALL** accepter la création (`201`).
- **WHERE** la création passe par `POST /api/users`, l'institution cible **SHALL** être celle à
  laquelle l'acteur est rattaché — c'est-à-dire exactement la valeur que
  `BelongsToInstitution::creating` écrira dans `institution_id`.
- **WHERE** la mise à jour passe par `PUT /api/users/{user}`, l'institution cible **SHALL** être
  celle de **l'utilisateur cible** (`institution_id` n'est pas modifiable par cet endpoint), et
  non celle de l'acteur.

### R2 — Un doublon intra-institution reste refusé

- **WHEN** un administrateur crée deux fois le même email dans la **même** institution, le
  système **SHALL** refuser la seconde création avec un `422` portant l'erreur sur `email`.
- **WHEN** une mise à jour affecte à un utilisateur un email déjà détenu par un **autre**
  utilisateur de la même institution, le système **SHALL** refuser (`422`).

### R3 — Une mise à jour ne se heurte jamais à sa propre ligne

- **WHEN** un utilisateur met à jour son profil en renvoyant son email **inchangé**, le système
  **SHALL** accepter (`200`) : sa propre ligne est exclue du contrôle d'unicité.
- **IF** l'identifiant à exclure provient du route-model binding (donc un modèle `User`, et non
  un entier), le système **SHALL** l'exclure correctement — la règle **SHALL NOT** dépendre
  d'une conversion implicite du modèle en chaîne.

### R4 — Un compte soft-deleted occupe toujours son email (fidélité à l'index)

- **WHERE** un utilisateur de l'institution a été soft-deleted (#566), sa ligne **SHALL**
  continuer d'être prise en compte par le contrôle d'unicité — MySQL et SQLite n'ayant pas
  d'index unique partiel, la ligne occupe toujours son emplacement dans
  `users_email_institution_unique`.
- **WHEN** un administrateur tente de créer un compte avec l'email d'un compte supprimé de son
  institution, le système **SHALL** répondre `422` avec un message **distinct et actionnable**
  (« compte supprimé — restaurez-le »), et **SHALL NOT** répondre `500`.
- **IF** la règle excluait les lignes soft-deleted, l'`INSERT` violerait l'index unique et
  remonterait en `500` — c'est précisément ce que `KlassciUserSynchronizer:172-174` documente et
  évite via `withTrashed()`.

### R5 — Institution cible non résolue : refus fail-closed

- **IF** l'institution cible ne peut pas être déterminée (compte plateforme `supradmin` dont
  `institution_id` est `NULL` ; cible plateforme en mise à jour), le système **SHALL** refuser la
  requête avec un `422` sur `email` plutôt que de retomber sur une contrainte
  `institution_id IS NULL`.
- **Justification** : l'index unique de la base ne contraint **rien** pour `institution_id NULL`
  (SQL : deux `NULL` ne sont jamais « égaux »). Une validation qui laisserait passer créerait des
  comptes plateforme à email dupliqué **sans filet base**, et `LocalLmsAuthenticator:57-62`
  résout le login par email via `->first()` — l'ambiguïté serait silencieuse.
- **Cohérence** : `AssignableRole` (`app/Rules/AssignableRole.php:60-64`) échoue déjà fermé sur
  acteur non résoluble. On ne suppose jamais un privilège par défaut.

### R6 — Le message d'erreur ne divulgue rien d'une autre institution

- **WHEN** l'email existe uniquement dans une **autre** institution, le système **SHALL** accepter
  la création — donc **SHALL NOT** émettre « Cet email est déjà utilisé », qui révélait
  indirectement l'existence de ce compte à un administrateur sans droit sur ce tenant (oracle
  d'énumération).

### R7 — Non-régression de la surface existante

- Le système **SHALL** conserver le contrat de réponse des trois endpoints admin
  (`201/200/200`, enveloppe `{success, message, data}`) — cf. `AdminUserResponseTest`.
- Le système **SHALL** conserver le blocage d'escalade de rôle (`AssignableRole`) — cf.
  `AdminUserRoleEscalationTest`.
- Le système **SHALL** conserver l'isolation cross-tenant du binding — cf.
  `AdminUserTenantIsolationTest`.

## Hors périmètre (dettes tracées, à ouvrir en issues distinctes)

1. **Ambiguïté de login inter-institutions** — `LocalLmsAuthenticator::attemptLocalAuth`
   (`:57-62`) cherche l'email **sans scope tenant** puis `->first()`. Deux comptes homonymes dans
   deux écoles existent **déjà** en base via le sync KLASSCI (dont le garde est scopé
   institution) : le défaut est **préexistant** et indépendant de #580, mais la présente
   correction le rend atteignable par un second chemin (création admin). À traiter séparément.
2. **Absence de parcours de restauration côté admin** — un compte soft-deleted n'est restauré que
   par re-sync KLASSCI (`KlassciUserSynchronizer::restoreIfTrashed`). Sans endpoint
   `POST /users/{id}/restore`, le message de R4 reste informatif mais non actionnable en un clic.
3. **Comptes plateforme sans filet base** — l'index unique ne contraint pas `institution_id NULL`
   (cf. R5). Un index partiel (PostgreSQL) ou une colonne sentinelle serait la correction au
   niveau base ; hors périmètre (aucune migration dans #580).
