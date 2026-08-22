# Tasks — #580 : unicité de l'email par institution

> Checklist hiérarchique (max 2 niveaux). Chaque tâche référence un requirement.
> TDD strict : les tests RED (tâche 1) précèdent l'implémentation (tâches 2–3).

- [x] 1. Tests RED — prouver les deux défauts et spécifier le comportement cible
  - [x] 1.1 `tests/Feature/Admin/AdminUserEmailUniquenessTest.php` : même email accepté dans une
        seconde institution (RED aujourd'hui : `422`) _(R1, R6)_
  - [x] 1.2 Même fichier : doublon intra-institution refusé `422` (garde anti-régression) _(R2)_
  - [x] 1.3 Même fichier : `PUT` de son propre email inchangé accepté `200`
        (RED aujourd'hui : `500`, modèle sérialisé dans la règle) _(R3)_
  - [x] 1.4 Même fichier : `PUT` vers l'email d'un autre utilisateur de l'institution → `422` _(R2)_
  - [x] 1.5 Même fichier : `PUT` vers un email détenu dans une autre institution → `200` _(R1)_
  - [x] 1.6 Même fichier : création sur l'email d'un compte **soft-deleted** de l'institution →
        `422` explicite, **jamais** `500` _(R4)_
  - [x] 1.7 Même fichier : `supradmin` plateforme (`institution_id` NULL) → `422` fail-closed,
        aucun compte créé _(R5)_
  - [x] 1.8 `tests/Unit/Rules/UniqueEmailInInstitutionTest.php` : les 7 cas unitaires de la règle
        _(R1–R5)_

- [x] 2. Règle `App\Rules\UniqueEmailInInstitution`
  - [x] 2.1 `implements ValidationRule`, constructeur **privé** `(?int $institutionId, ?int $ignoreUserId)`
        + constructeurs nommés `forCreationBy(?User)` / `forUpdateOf(?User)` _(R1)_
  - [x] 2.2 Branche fail-closed si `institutionId === null` _(R5)_
  - [x] 2.3 Lookup `withoutGlobalScope('institution')->withTrashed()` scopé `(email, institution_id)`,
        exclusion de `ignoreUserId`, méthode ≤ 40 lignes _(R1, R3, R4)_
  - [x] 2.4 Messages distincts actif / supprimé / non résolu, aucun détail cross-tenant _(R4, R6)_
  - [x] 2.5 Docblock : faille corrigée, mesures P1–P4, pourquoi les lignes supprimées comptent

- [x] 3. Câblage des deux FormRequests
  - [x] 3.1 `CreateUserRequest:39` → `['required','email', UniqueEmailInInstitution::forCreationBy($actor)]` _(R1)_
  - [x] 3.2 `UpdateUserRequest:35` → `['sometimes','email', UniqueEmailInInstitution::forUpdateOf($target)]`,
        suppression de la concaténation du modèle _(R1, R3)_
  - [x] 3.3 Retirer la clé de message `email.unique` devenue morte dans les deux `messages()` _(Q5)_

- [x] 4. Validation finale
  - [x] 4.1 Suite impactée verte : `tests/Feature/Admin/`, `tests/Unit/Rules/`,
        `tests/Feature/Auth/SoftDeletedUserAuthTest.php` _(R7)_
  - [x] 4.2 PHPStan level 9 : 0 erreur, 0 ajout de baseline
  - [x] 4.3 Revue qualité (`thermo-nuclear-code-quality-review` / `production-grade-standards`)
  - [x] 4.4 Dettes hors périmètre remontées dans la PR (ambiguïté de login, absence de
        restauration admin, comptes plateforme sans filet base, clé `role.in` morte)

## Journal de validation

- RED mesuré : 8 échecs / 10 sur `AdminUserEmailUniquenessTest`, les 2 gardes
  anti-régression (rattachement tenant, doublon intra-institution) déjà verts.
- GREEN : 21/21 sur les deux fichiers de test dédiés, puis **80 passés**
  (`tests/Feature/Admin` + `tests/Unit/Rules`).
- Suite complète : `1733 passés, 2 ignorés, 1 échec` — `QueueDrainCommandTest` (exit 12 du
  worker sous charge de suite) ; **repassé vert en isolation (41,9 s)**, sans rapport avec ce
  correctif (aucun fichier commun).
- PHPStan level 9 : **0 erreur, 0 entrée de baseline ajoutée ou retirée**.
- Revue `/code-review` (niveau high) sur le diff : les deux constats remontés sont les dettes
  n° 1 et n° 2 déjà listées dans `requirements.md`. Le message « compte supprimé » a été
  reformulé à la suite de la revue : il énonce un fait au lieu de conseiller une restauration
  dont l'endpoint n'existe pas.
