# Requirements — FileController IDOR cross-tenant fix

**Issue GitHub** : [#102](https://github.com/ouedraogoissouf2012/lms_backend/issues/102)
**Spec workflow** : CONTRIBUTING.md §A
**Date** : 2026-05-17

---

## Contexte

L'audit `spec-security` du Batch 7 PHPStan (PR #101) a remonté un **HIGH IDOR pré-existant** sur `FileController::show()` et `::download()`. L'investigation étendue révèle le **même bug pattern** sur :

- `FileController::stats()` (L280-314) — pas de filtre tenant sur les query
- `UpdateFileRequest::authorize()` ([L42-52](app/Http/Requests/UpdateFileRequest.php#L42-L52)) — `isAdmin()` ambigu + pas de check tenant
- `DeleteFileRequest::authorize()` ([L36-46](app/Http/Requests/DeleteFileRequest.php#L36-L46)) — idem

**Cause racine** : `User::isAdmin()` mélange `admin/administrateur/superAdmin` (intra-tenant) et `supradmin` (cross-tenant légitime). Sans check `institution_id`, un admin de l'institution A peut accéder/modifier/supprimer des fichiers de l'institution B.

**5 méthodes** affectées au total. Scope unifié pour cohérence (1 PR = 1 pattern de bug, comme #91 Forum IDOR).

---

## Objectifs

1. Éliminer le HIGH IDOR sur `show()` et `download()` (lecture cross-tenant)
2. Éliminer le HIGH IDOR latent sur `update` et `destroy` (modération cross-tenant)
3. Éliminer le HIGH cross-tenant leak sur `stats()` (aggrégations cross-tenant)
4. Préserver les autorisations légitimes : owner, admin intra-tenant, supradmin cross-tenant, fichiers publics
5. Aucun usage résiduel de `User::isAdmin()` dans le code touché

---

## Requirements EARS

### REQ-1 — `show()` et `download()` : lecture autorisée

**WHEN** un utilisateur accède à `GET /api/files/{id}` ou `GET /api/files/{id}/download`
**THEN** l'accès DOIT être autorisé si l'une des conditions suivantes est vraie :
- Caller est `supradmin` (cross-tenant légitime), OU
- `file.institution_id === caller.institution_id` ET (
  - `file.is_public === true`, OU
  - `file.user_id === caller.id` (owner), OU
  - caller role ∈ `['admin', 'administrateur', 'superAdmin']` (admin intra-tenant)
)
**OTHERWISE** rejeter (403)

### REQ-2 — `update` et `destroy` : modération autorisée

**WHEN** un utilisateur appelle `PUT /api/files/{id}` ou `DELETE /api/files/{id}`
**THEN** l'accès DOIT être autorisé si :
- Caller est `supradmin`, OU
- `file.institution_id === caller.institution_id` ET (
  - `file.user_id === caller.id` (owner), OU
  - caller role ∈ `['admin', 'administrateur', 'superAdmin']`
)

**Note** : pas de bypass `is_public` ici — un fichier public n'est pas modifiable/supprimable par n'importe qui.

### REQ-3 — `stats()` : filtre tenant + supradmin bypass

**WHEN** un caller non-`supradmin` appelle `GET /api/files/stats`
**THEN** toutes les aggrégations DOIVENT être scopées à `WHERE institution_id = caller.institution_id`
**AND** les étudiants restent restreints à leurs propres fichiers (`WHERE user_id = caller.id`) **dans leur institution**

**WHEN** un caller `supradmin` appelle `stats()`
**THEN** les aggrégations DOIVENT être globales (cross-tenant, sans filtre)

### REQ-4 — Aucun usage de `User::isAdmin()`

**WHEN** le fix est appliqué sur les 5 méthodes ciblées
**THEN** aucun appel à `$user->isAdmin()` ne DOIT subsister dans le code touché
**BECAUSE** la sémantique ambiguë de `isAdmin()` est précisément la source du bug

### REQ-5 — Gestion des entités manquantes

**WHEN** route binding retourne null OU utilisateur non authentifié
**THEN** rejeter (403/404) sans exception non-gérée

### REQ-6 — Préservation des middlewares

**WHEN** le fix est déployé
**THEN** les middlewares routes (`auth:sanctum`, `klassci.sync`, `throttle:*`) restent inchangés

### REQ-7 — Tests d'isolation

**WHEN** la PR est soumise
**THEN** elle DOIT inclure des tests d'isolation cross-tenant pour les 5 méthodes :

Pour `show`/`download` (lecture) :
- Owner intra-tenant → 200
- Admin intra-tenant → 200
- Étudiant non-owner sur fichier public intra → 200
- Étudiant non-owner sur fichier non-public intra → 403
- Admin cross-tenant → 403/404
- Supradmin cross-tenant → 200

Pour `update`/`destroy` (modération) :
- Owner intra → 200
- Admin intra → 200
- Étudiant non-owner même sur fichier public intra → 403
- Admin cross-tenant → 403/404
- Supradmin cross-tenant → 200

Pour `stats` :
- Coordinateur/admin intra A → comptages de A uniquement
- Supradmin → comptages globaux
- Cache isolation entre institutions

### REQ-8 — Trait réutilisable

**WHEN** le pattern d'autorisation Files est implémenté
**THEN** il DOIT être encapsulé dans un trait `ChecksFileAuthorization` réutilisable par le controller (`show`, `download`) et les FormRequests (`UpdateFile`, `DeleteFile`)
**BECAUSE** la logique tenant + ownership + moderator est identique (variation : `is_public` bypass pour la lecture)

---

## Non-objectifs (hors scope)

- Refactoring `User::isAdmin()` ambigu (impact projet-wide — ticket séparé)
- Refactoring file-size FileController 316>200 (split — ticket séparé)
- Rename trait `ChecksForumAuthorization` → générique (cosmétique)
- Migration `File::find()` static vers DI (§1.6 D — ticket séparé)
- Ajout de Laravel Policies

---

## Hypothèses

1. `File::$institution_id` existe (vérifié : ligne 40 du modèle, `BelongsToInstitution` trait)
2. Le rôle `supradmin` est utilisé correctement pour les gestionnaires plateforme
3. `is_public` est uniquement consulté côté lecture (show/download) — pas de bypass modération

---

## Risques

- **R1** : extension du scope du fix à 5 méthodes (au lieu de 3) → diff plus large mais cohérent
- **R2** : tests Feature skip locally sans `pdo_pgsql` — exécution en CI seulement
- **R3** : la décision "coordinateur n'est pas dans `moderatorRoles`" pour Files est-elle correcte ? Actuellement le code ne mentionne pas `coordinateur` — on aligne avec ce comportement (REQ-1, REQ-2 : admins uniquement)

---

## Acceptance criteria

- [ ] Trait `ChecksFileAuthorization` créé avec 2 méthodes : `canReadFile`, `canModerateFile`
- [ ] `FileController::show()` et `::download()` utilisent `canReadFile()`
- [ ] `FileController::stats()` filtre tenant + bypass supradmin + cache key per institution
- [ ] `UpdateFileRequest::authorize()` utilise `canModerateFile()`
- [ ] `DeleteFileRequest::authorize()` utilise `canModerateFile()`
- [ ] Aucun appel `User::isAdmin()` dans le diff
- [ ] Tests Feature HTTP : matrice tenant × rôle pour les 5 méthodes (~15 cas)
- [ ] PHPStan 0 errors hors baseline
- [ ] 3 audits PASS (spec-security strict obligatoire)
- [ ] PR avec `closes #102`
