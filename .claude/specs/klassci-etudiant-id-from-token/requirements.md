# klassci_etudiant_id — Identité étudiant dérivée du token Sanctum

> Issue GitHub : [#123 [security HIGH] EvaluationController — klassci_etudiant_id lu depuis le request body (pattern IDOR étudiant)](https://github.com/ouedraogoissouf2012/lms_backend/issues/123)
>
> Identifiée par l'audit `spec-security` (finding F2 HIGH) de la PR [#122](https://github.com/ouedraogoissouf2012/lms_backend/pull/122). Pattern anti-IDOR identique à #34/#119 — différent vecteur (request body / URL param au lieu de blob JSON).

## Contexte

Deux endpoints d'évaluation acceptent `klassci_etudiant_id` directement du client (body ou URL) au lieu de le dériver du token Sanctum :

```php
// app/Http/Controllers/API/EvaluationController.php:624-635
$validator = Validator::make($request->all(), [
    'klassci_etudiant_id' => 'required|integer',
]);
// ...
$klassciEtudiantId = $request->klassci_etudiant_id;
```

```php
// app/Http/Controllers/API/EvaluationController.php:415
public function studentEvaluations(int $klassciEtudiantId, Request $request): JsonResponse
```

```
// routes/api.php:641
Route::get('evaluations/student/{klassciEtudiantId}', [EvaluationController::class, 'studentEvaluations']);
```

Le `klassciEtudiantId` est ensuite utilisé pour :
1. **Créer une soumission d'évaluation** au nom de cet ID (ligne 746 : `'klassci_etudiant_id' => $klassciEtudiantId`)
2. **Lire les soumissions existantes** filtrées par cet ID (lignes 715, 731, 560)
3. **Lister les évaluations** comme si l'utilisateur était cet étudiant (ligne 437 et tout `studentEvaluations`)

## Scénario d'attaque

### Vecteur 1 — Body (`POST /api/evaluations/{id}/start`)

1. Étudiant A est authentifié (Bearer Sanctum + `klassci.sync`)
2. A appelle `POST /api/evaluations/123/start` avec body `{ "klassci_etudiant_id": <id_de_B> }`
3. `startEvaluation` crée une nouvelle soumission `EvaluationSubmission` avec `klassci_etudiant_id = B`
4. A répond aux questions ; la note finale apparaît sous le nom de B (ou A peut faire passer plusieurs évaluations sous différents IDs étudiants pour tester une question, brouiller les statistiques, frauder en gonflant les notes d'autrui, etc.)

### Vecteur 2 — URL param (`GET /api/evaluations/student/{klassciEtudiantId}`)

1. Étudiant A appelle `GET /api/evaluations/student/<id_de_B>`
2. Le controller lit `$klassciEtudiantId = B` (param URL) et utilise `$user = $this->authenticatedUser($request)` (A) pour le token KLASSCI
3. Le dashboard renvoyé est celui de A (lecture token), mais les **soumissions filtrées** dans le payload sont celles de B (filtre `where('klassci_etudiant_id', $klassciEtudiantId)`)
4. **A voit les notes, soumissions, statuts d'évaluation de B**

L'attaque est **active** (POST/GET direct, ne nécessite pas de KLASSCI compromis), **sans audit log** spécifique, **bypass complet** de l'isolation par identité étudiant.

## Solution

Suivre le même invariant que #34 / #119 / pattern Sanctum : **l'identité étudiant est dérivée du token authentifié, jamais acceptée du client**. Aucune valeur de `klassci_etudiant_id` ne doit être lue d'un body ou d'une URL param.

## Requirements (EARS)

### REQ-1 — Identité étudiant dérivée du token Sanctum (source unique)

WHEN un controller ou service prend une décision (lecture, écriture, autorisation) basée sur l'identité étudiant KLASSCI d'un utilisateur,
THE système SHALL utiliser `$user->klassci_id` (où `$user = $this->authenticatedUser($request)`) et SHALL ne PAS lire ni `$request->klassci_etudiant_id`, ni un param URL `{klassciEtudiantId}`.

### REQ-2 — `startEvaluation` : ignorer toute valeur client de `klassci_etudiant_id`

WHEN un client appelle `POST /api/evaluations/{id}/start`,
THE controller SHALL utiliser `$user->klassci_id` (du token Sanctum) pour :
- créer la nouvelle `EvaluationSubmission` (ligne 746 actuelle)
- rechercher une `activeSubmission` existante (ligne 715)
- compter le nombre de tentatives `attemptsCount` (ligne 731)

THE controller SHALL ne PAS lire `$request->klassci_etudiant_id`.

IF le body contient `klassci_etudiant_id`, THE controller SHALL ignorer ce champ silencieusement (backward-compat : les anciens clients qui l'envoient ne reçoivent pas d'erreur, mais leur valeur n'a aucun effet).

### REQ-3 — `StartEvaluationRequest` : retrait de la règle vulnérable + branchement à la route

WHERE le FormRequest `StartEvaluationRequest` est modifié,
THE classe SHALL :
- retirer la règle `'klassci_etudiant_id' => 'required|integer|min:1'`
- retirer les 3 messages associés
- ajouter dans `authorize()` la vérification `auth()->user()?->isStudent() === true` (seuls les étudiants peuvent démarrer une éval — coordinateurs/enseignants n'ont rien à faire ici)

WHEN la signature de `EvaluationController::startEvaluation()` est modifiée,
THE controller SHALL recevoir `StartEvaluationRequest $request` au lieu de `Request $request`. Le `Validator::make` inline (lignes 624-633) SHALL être supprimé (doublon désormais).

### REQ-4 — Suppression de la route `studentEvaluations` avec param URL

WHEN la route `GET /api/evaluations/student/{klassciEtudiantId}` est touchée,
THE système SHALL la **supprimer entièrement** (`routes/api.php:641`).

WHEN la méthode `EvaluationController::studentEvaluations(int $klassciEtudiantId, Request $request)` est touchée,
THE système SHALL la **supprimer entièrement** (dead code post-fix).

Justification : la méthode `myEvaluations` (`GET /api/evaluations/student` sans param, ligne 638 routes) existe déjà et couvre tous les usages legit (un étudiant voit ses propres évaluations via son token Sanctum). La version avec param URL est strictement un vecteur d'attaque sans utilité fonctionnelle.

### REQ-5 — Aucun autre site ne lit `klassci_etudiant_id` depuis le client

WHEN un audit grep est exécuté post-PR,
THE code base SHALL ne contenir aucun match pour les patterns suivants dans `app/` (hors commentaires et messages d'erreur internes) :

- `$request->klassci_etudiant_id`
- `{klassciEtudiantId}` dans `routes/api.php`
- Signature `function(int $klassciEtudiantId` dans un controller

THE seules occurrences de `klassci_etudiant_id` autorisées dans `app/` SHALL être :
- Lecture sur un model déjà chargé (`$submission->klassci_etudiant_id` — clé étrangère interne)
- Écriture avec `$user->klassci_id` (sites lignes 790, 437 post-fix)
- Filtre `where('klassci_etudiant_id', $user->klassci_id)` (déjà sécurisé : lignes 68, 849, 1542 + LMSMatieres + LMSVisio)
- Aggregation prof côté serveur (lignes 1228, 1240, 1329, 1340, 1341, 1409 — récupération via `KlassciProxyService::getClasseEtudiants` qui passe par le token du prof)

### REQ-6 — Tests obligatoires

WHEN les tests sont écrits,
THE suite SHALL couvrir au minimum :

| # | Test | Description | Assertion clé |
|---|---|---|---|
| 1 | `test_start_evaluation_ignores_klassci_etudiant_id_from_body` | Étudiant A POST avec body `{"klassci_etudiant_id": <B>}` | `EvaluationSubmission::latest()` créée a `klassci_etudiant_id === A->klassci_id`, PAS B |
| 2 | `test_start_evaluation_reuses_active_submission_of_authenticated_user_only` | A a soumission `en_cours`, A POST avec body `<B>` | Reprise de la soumission de A (pas création nouvelle au nom de B) |
| 3 | `test_start_evaluation_blocked_for_non_student` | Enseignant POST `/start` | 403 (REQ-3 — authorize bloque) |
| 4 | `test_start_evaluation_max_attempts_counts_authenticated_user_only` | A a 3 soumissions terminées, max_attempts=3, A POST avec body `<B>` (qui a 0 soumissions) | 403 (le counter de A est utilisé, pas celui de B) |
| 5 | `test_get_student_evaluations_with_param_returns_404` | `GET /api/evaluations/student/<B>` (route supprimée) | 404 |
| 6 | `test_get_my_evaluations_route_returns_evaluations_for_authenticated_user` | `GET /api/evaluations/student` (sans param) authentifié | 200 + payload concerne l'authenticated user |

### REQ-7 — Aucune régression sur les chemins legit

WHEN les tests Feature des suites existantes (`tests/Feature/LMS`, `tests/Feature/Quiz`, `tests/Feature/Forum`, `tests/Feature/Notifications`, `tests/Feature/Files`, `tests/Feature/Security`) sont exécutés,
THE suite SHALL passer 100% sans modification. Aucun consommateur de `klassci_etudiant_id` côté lecture (filtres prof, agrégation par classe via KLASSCI API) ne doit être altéré.

## Hors scope (volontairement)

| Item | Pourquoi hors scope |
|---|---|
| Refactorisation des 8 sites legit de `klassci_etudiant_id` | Pas vulnérables. Tous lisent `$user->klassci_id` ou récupèrent l'ID depuis `KlassciProxyService::getClasseEtudiants` (token du prof). |
| Renommer la colonne `klassci_etudiant_id` en `klassci_user_id` (cohérence — `klassci_id` user-side, `klassci_etudiant_id` columns-side) | Confusion sémantique pré-existante. Hors scope sécurité, à traiter dans un refactor terminologique. |
| Ajouter une colonne `klassci_etudiant_id` à `users` (à la #119) | YAGNI : `users.klassci_id` est déjà la source d'identité, déjà write-once (jamais modifié par re-sync, hérité de la migration de `klassci_id` originale). Pas besoin d'une 2ᵉ colonne. |
| Auditer/réparer rétroactivement les soumissions créées par l'exploitation passée | Aucun cas connu, et la base ne permet pas de distinguer une soumission légitime d'une exploitation. Hors scope — mitigation côté ops si soupçon (audit DB manuel). |
| Documenter la breaking change pour `/api/evaluations/student/{klassciEtudiantId}` côté frontend | À gérer dans la PR via le PR body (note breaking-change explicite) + dans le changelog projet, pas requis comme critère d'acceptation. |

## Critère d'acceptation global

La PR est mergeable WHEN :

1. ✓ Tous les REQ-1 à REQ-7 sont implémentés et couverts par les tests listés en REQ-6
2. ✓ `vendor/bin/phpstan analyse` reste à `[OK] No errors`
3. ✓ `vendor/bin/phpunit tests/Feature/LMS tests/Feature/Security tests/Feature/Quiz tests/Feature/Forum tests/Feature/Notifications tests/Feature/Files` passe sans régression
4. ✓ `spec-security` audit retourne 0 finding HIGH/CRITICAL
5. ✓ `spec-architect` audit retourne 0 finding HIGH/CRITICAL
6. ✓ `spec-reviewer` audit retourne MERGE-READY
7. ✓ L'issue GitHub #123 sera fermée manuellement au merge (la branche `lms` ≠ default, `closes #123` n'auto-ferme pas)

## Critère d'invalidation (Q15 — manifeste §4)

Cette solution est **à invalider et reconcevoir** SI :

1. **Un cas légitime émerge où un prof/coordinateur doit pouvoir démarrer une évaluation au nom d'un étudiant** (par ex. saisie manuelle en salle d'examen). Dans ce cas, REQ-2 doit être révisée : autoriser `klassci_etudiant_id` dans le body **uniquement** si `$user->isAdmin() || $user->isCoordinator()` (avec audit log structuré « evaluation_started_for_other_user »).
2. **Un frontend externe (mobile, intégration) dépend de `/api/evaluations/student/{klassciEtudiantId}` avec param** pour un usage admin légitime. Dans ce cas, REQ-4 doit être révisée : conserver la route mais ajouter une autorisation stricte `$user->isAdmin()` au début du controller. Probabilité estimée nulle (aucun frontend documenté ne consomme cette route).
3. **L'identité Sanctum n'est plus fiable** (ex : une décision business de partager des tokens entre utilisateurs — improbable mais à signaler si jamais). Dans ce cas, toute la stratégie « identité depuis token » s'effondre — bien au-delà du scope #123.

Aucun de ces 3 cas n'est connu aujourd'hui. La solution tient.
