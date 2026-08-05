# Requirements — Notation quiz : comparaison stricte des réponses (#498)

## Contexte & preuves

`app/Services/Quiz/QuizGradingService::checkAnswer()` note les réponses avec une
comparaison **lâche** `==` :
- `:66` — `fn ($a): bool => $a->id == $userAnswer && $a->is_correct` (multiple_choice)
- `:80` — `$correctAnswer->id == $userAnswer` (true_false)

`$userAnswer` est pris **brut, non casté** (`gradeAttempt:133` :
`$attempt->answers[$question->id] ?? null`). La validation d'entrée ne contraint
pas les valeurs : `SubmitQuizAttemptRequest.php:36` = `'answers' => 'required|array'`
**seulement** (aucune règle `answers.*`). Contrôleur : `QuizAttemptStudentController:58`,
route `content.php:196` sous `auth:sanctum` (étudiant).

### Vecteur d'exploit (1 requête)
`POST /api/quiz-attempts/{id}/submit` body `{"answers": {"12": true}}` :
`contains(fn = $a->id == true && $a->is_correct)`. En PHP, `int_non_nul == true`
vaut **TRUE** → dès qu'une réponse `is_correct` existe (toujours), la question
`multiple_choice`/`true_false` est notée **correcte**. Un étudiant obtient le
**score maximum** sans connaître les réponses. Corruption de l'intégrité de la
notation (HIGH).

### Précédent — le strict est déjà la norme ailleurs
`KnowledgeCheckGradingService.php:51,60` compare déjà avec `===` (strict) — il
n'a PAS ce bug. `QuizGradingService` est le seul à utiliser `==`.

### Call-sites impactés (le fix dans checkAnswer les couvre TOUS)
- `gradeAttempt` (:141 via `calculatePoints`) — la notation elle-même
- `BuildsAttemptResponses.php:63-64` — construction de la réponse (`is_correct`/`points_earned` renvoyés au client)

## Portée

- **IN** : durcir `checkAnswer` (multiple_choice + true_false) + validation des
  valeurs de réponse dans `SubmitQuizAttemptRequest`.
- **OUT** : `multiple_response` (`:69-76`, déjà `===` sur arrays triés — vérifier
  la non-régression) ; `KnowledgeCheckGradingService` (déjà strict) ; l'évaluation
  (service distinct).

## Exigences (EARS)

**REQ-1 — Comparaison stricte multiple_choice**
WHEN `checkAnswer` traite une question `multiple_choice`, THE SYSTEM SHALL ne
noter correcte QUE si `$userAnswer` est un identifiant **numérique** égal (après
normalisation en entier) à l'id d'une réponse `is_correct`. Une valeur booléenne,
un tableau, une chaîne non numérique ou `null` SHALL être notés **incorrect**.

**REQ-2 — Comparaison stricte true_false**
WHEN `checkAnswer` traite une question `true_false`, THE SYSTEM SHALL appliquer la
même règle stricte que REQ-1.

**REQ-3 — Rejet du type juggling booléen**
IF `$userAnswer === true` (ou tout non-scalaire/non-numérique), THEN THE SYSTEM
SHALL noter la question **incorrecte** (jamais correcte par coercition PHP).

**REQ-4 — Notation correcte inchangée**
THE SYSTEM SHALL continuer de noter **correctement** les vraies réponses : un id
entier `5` OU sa forme string `"5"` (JSON) égal à l'id de la bonne réponse →
correct. `multiple_response` (exact set match) inchangé.

**REQ-5 — Validation d'entrée (défense en profondeur)**
THE SYSTEM SHALL valider dans `SubmitQuizAttemptRequest` que chaque valeur de
`answers.*` est un entier, une chaîne numérique, ou un tableau (multiple_response) —
rejetant (422) les valeurs booléennes/objets manifestement malformées, avant même
la notation.

**REQ-6 — Pas de régression des call-sites**
THE SYSTEM SHALL préserver le comportement de `gradeAttempt` et
`BuildsAttemptResponses` (score, points, `is_correct` de la réponse) pour les
réponses valides.

## Critères d'acceptation

1. `{"answers":{"12":true}}` → question notée **incorrecte** (0 point) OU rejetée
   422, jamais correcte.
2. `{"answers":{"12":[1,2]}}` (array sur une question scalaire) → incorrecte.
3. `{"answers":{"12":"5abc"}}` / `null` → incorrecte.
4. `{"answers":{"12":5}}` où 5 = id de la bonne réponse → **correct** (non-régression).
5. `{"answers":{"12":"5"}}` (string numérique JSON) → **correct** (non-régression).
6. `multiple_response` exact set match inchangé (vrai/faux corrects).
7. `php artisan test` 100 %, PHPStan level 9 vert, garde tailles OK.

## Q15 — Critères d'invalidation

- ❌ Caster `(int) $userAnswer` sans rejeter les non-numériques → `(int) true === 1`
  matcherait l'id 1 (bug déplacé, pas corrigé).
- ❌ Casser la notation des réponses string-numériques légitimes (`"5"`) que le
  frontend peut envoyer en JSON.
- ❌ Régresser `multiple_response` (déjà correct).
- ❌ Valider si strictement `answers.*` = int que ça rejette les vraies soumissions
  `multiple_response` (arrays) → 422 sur des réponses valides.
