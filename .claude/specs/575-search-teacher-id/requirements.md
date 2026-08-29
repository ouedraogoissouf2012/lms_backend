# Requirements — #575 · colonnes fantômes dans la recherche

> Sous-issue de #563 · P1 · Branche `fix/575-search-teacher-id` · PR vers `lms`

## Contexte vérifié (lecture du code, pas supposition)

Quatre requêtes de `app/Services/Search/` filtrent sur `teacher_id`, colonne
absente de **toutes** les migrations :

| Fichier | Ligne | Modèle | Colonne réellement migrée |
|---|---|---|---|
| `app/Services/Search/GlobalSearchService.php` | 157 | `Lesson` | `enseignant_id` (`2025_10_14_160000_create_lessons_table.php:20`) |
| `app/Services/Search/GlobalSearchService.php` | 188 | `Evaluation` | `klassci_enseignant_id` (`2025_10_19_180924_create_evaluations_table.php:21`) |
| `app/Services/Search/SearchSuggestionsService.php` | 57 | `Lesson` | `enseignant_id` |
| `app/Services/Search/SearchSuggestionsService.php` | 69 | `Evaluation` | `klassci_enseignant_id` |

**Découverte supplémentaire, faite en lisant le code (non mentionnée dans #575) :**
la table `evaluations` n'a pas de colonne `title` — elle s'appelle `titre`
(`2025_10_19_180924_create_evaluations_table.php:23`, `Evaluation::$fillable:24`).
Trois occurrences supplémentaires du **même** défaut, dans les **mêmes** fichiers :

| Fichier | Ligne | Expression fautive |
|---|---|---|
| `GlobalSearchService.php` | 183 | `->where('title', 'LIKE', ...)` sur `Evaluation` |
| `GlobalSearchService.php` | 198 | `'title' => $evaluation->title` (valeur toujours `null`) |
| `SearchSuggestionsService.php` | 66 | `->where('title', 'LIKE', ...)` + `->pluck('title')` |

Cette découverte n'est pas hors-sujet : `/api/search` agrège leçons **et**
évaluations dans **la même requête HTTP**. Tant que `title` reste fautif,
l'erreur MySQL 1054 sur les évaluations renvoie un 500 et rend le critère de
fermeture « tests verts sur la jambe MySQL » de #575 **inatteignable**. Le
défaut est par ailleurs déjà répertorié comme bug latent connu (`Evaluation::$title`,
colonne = `titre`) lors du lot 1 de #363. Il est donc corrigé ici, dans le
périmètre des deux fichiers possédés, et déclaré explicitement dans la PR.

### Pourquoi le bug a survécu à 1 446 tests

Divergence de moteur documentée :

- **MySQL** (production) : identifiant inconnu → erreur `1054 Unknown column`
  → 500 via le handler générique de `bootstrap/app.php`.
- **SQLite** (tests/CI) : par compatibilité historique, un identifiant entre
  guillemets doubles qui ne résout vers aucune colonne est **réinterprété comme
  chaîne littérale** (misfeature « double-quoted string literals », activée par
  défaut — cf. sqlite.org/quirks.html § *Double-quoted String Literals Are
  Accepted*). `WHERE "teacher_id" = 5` devient `WHERE 'teacher_id' = 5` → faux
  → 0 ligne, sans erreur.

Conséquence pratique **testable dès SQLite** : le filtre enseignant est toujours
faux, donc un enseignant ne voit **jamais** ses propres leçons ni ses propres
évaluations. Le test RED n'exige pas MySQL — c'est le mode d'échec (0 résultat
au lieu de 500) qui diffère, pas l'existence du défaut.

Conséquence inverse pour `'title'` : `'title' LIKE '%le%'` est **vrai** (la
chaîne littérale « title » contient « le »), donc le groupe `OR` est vrai pour
**toutes** les lignes. Un compte admin/coordinateur (aucun filtre de rôle
appliqué) qui cherche `le`, `it`, `ti`… reçoit **toutes** les évaluations du
tenant, quel que soit le terme recherché.

## Exigences (EARS)

### REQ-1 — Un enseignant retrouve ses propres leçons

**WHEN** un utilisateur de rôle `enseignant` interroge `GET /api/search?query=X`
**AND** une leçon dont il est l'auteur a un `title`, `description` ou `content`
contenant `X`,
**THEN** le système **SHALL** retourner cette leçon dans `results.lessons`.

### REQ-2 — Un enseignant ne voit pas les leçons d'un collègue

**WHEN** un utilisateur de rôle `enseignant` interroge `GET /api/search?query=X`
**AND** une leçon correspondante appartient à un **autre** enseignant de la même
institution,
**THEN** le système **SHALL** exclure cette leçon de `results.lessons`.

### REQ-3 — Un enseignant retrouve ses propres évaluations

**WHEN** un utilisateur de rôle `enseignant` doté d'un `klassci_enseignant_id`
interroge `GET /api/search?query=X`
**AND** une évaluation dont il est propriétaire a un `titre` ou une `description`
contenant `X`,
**THEN** le système **SHALL** retourner cette évaluation dans `results.evaluations`,
avec la clé `title` du payload **renseignée** depuis la colonne `titre`.

### REQ-4 — Un enseignant ne voit pas les évaluations d'un collègue

**WHEN** un utilisateur de rôle `enseignant` interroge `GET /api/search?query=X`
**AND** une évaluation correspondante porte le `klassci_enseignant_id` d'un autre
enseignant,
**THEN** le système **SHALL** exclure cette évaluation de `results.evaluations`.

### REQ-5 — Enseignant sans identité KLASSCI : fermeture par défaut

**IF** un utilisateur de rôle `enseignant` a `klassci_enseignant_id = NULL`
**WHEN** il interroge `GET /api/search`
**THEN** le système **SHALL** retourner **zéro** évaluation.

> Justification : `where('col', null)` est réécrit par Laravel en `whereNull('col')`
> (`Illuminate\Database\Query\Builder::where()`, branche `is_null($value)`), ce qui
> ferait remonter **toutes** les évaluations à propriétaire NULL. Un tel utilisateur
> ne peut posséder aucune évaluation : `EvaluationCrudController.php:84` refuse la
> création sans `klassci_enseignant_id`, et `ChecksEvaluationOwnership.php:99-101`
> refuse déjà tout accès dans ce cas. Le fail-closed est donc un alignement sur
> l'existant, pas une invention.

### REQ-6 — Même couverture sur les suggestions

**WHEN** un utilisateur de rôle `enseignant` interroge
`GET /api/search/suggestions?query=X`,
**THEN** le système **SHALL** appliquer exactement les règles REQ-1 à REQ-5 aux
titres de leçons et d'évaluations proposés.

### REQ-7 — Aucun changement pour les autres rôles

**WHEN** un utilisateur de rôle `etudiant`, `admin` ou `coordinateur` interroge
les deux endpoints,
**THEN** le système **SHALL** conserver le contrat de réponse existant
(clés racine `success`/`query`/`results`/`total`/`categories`, figé par
`tests/Feature/Search/SearchResponseTest.php`).

### REQ-8 — Recherche d'évaluation réellement filtrante

**WHEN** un utilisateur sans filtre de rôle (admin/coordinateur) interroge
`GET /api/search?query=le`
**AND** aucune évaluation ne contient « le » dans son `titre` ou sa `description`,
**THEN** le système **SHALL** retourner zéro évaluation.

## Hors périmètre (constaté, déclaré, non corrigé ici)

1. `GlobalSearchService.php:137` — `$u->role_display_name` : aucun accesseur
   `getRoleDisplayNameAttribute()` sur `User`, aucune colonne (seule occurrence
   voisine : `AuthResponsePresenter.php:90`, qui lit une clé du payload KLASSCI).
   Le champ `description` du bucket `users` vaut donc toujours `null`. Corriger
   exige de **décider** la chaîne d'affichage par rôle = changement de contrat
   d'API → issue dédiée.
2. `GlobalSearchService.php:191` — filtre étudiant `where('status', 'published')`
   sur `Evaluation` : la colonne existe mais ses valeurs réelles sont
   `brouillon|planifiee|en_cours|terminee` (`EvaluationFactory:29`, défaut de
   migration `brouillon`). Aucun étudiant ne voit donc jamais d'évaluation. Bug
   sémantique, pas de colonne fantôme : aucun 500 en MySQL, ne bloque pas #575.
3. Filtre étudiant sur les leçons non restreint à **sa** classe (cf. #482) —
   explicitement renvoyé au lot P2 par #575 lui-même.
4. `TeacherStatsService.php:48` — `Lesson::where('enseignant_id', $klassciTeacherId)`
   compare la colonne à `users.klassci_enseignant_id`, alors que
   `TeacherDashboardService.php:55` compare la **même** colonne à `$user->id`.
   Les deux ne peuvent pas être justes (cf. `design.md` § Décision d'identité).
   La convention KLASSCI y est de plus figée par des tests
   (`tests/Unit/Services/Dashboard/TeacherStatsServiceTest.php:67,136,157`) : la
   correction devra donc amender ces tests, pas seulement le service. Hors des
   deux fichiers possédés.
5. `SearchSuggestionsService` n'a **aucun** garde de rôle pour l'étudiant : ni
   sur les leçons (titres de brouillons/archives du tenant), ni sur les
   évaluations. Ce n'est pas une régression — le service n'en a jamais eu — mais
   le bucket évaluations passe d'un comportement absurde (tout ou rien selon que
   le terme cherché soit un fragment du mot « title ») à un comportement
   fonctionnel, ce qui rend le trou visible. Même famille que les points 2 et 3,
   donc même destination : le lot P2 du cloisonnement étudiant, où la règle
   `#482` sera appliquée d'un seul tenant aux trois endpoints.

Ces quatre points sont remontés dans la description de PR, pas silencieusement omis.
