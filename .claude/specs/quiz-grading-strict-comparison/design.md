# Design — Notation quiz : comparaison stricte des réponses (#498)

## 1. Le cœur : normaliser puis comparer strictement

Le problème de `$a->id == $userAnswer` : PHP coercit `int == bool`, `int == string`
de façon permissive (`5 == true` → true). La correction sûre = **rejeter d'abord
les valeurs non-numériques**, puis comparer en entier avec `===`.

Helper privé dans `QuizGradingService` :

```php
/**
 * Normalise une réponse "single id" en entier, ou null si la valeur n'est pas
 * un identifiant numérique valide (bool, array, string non numérique, null).
 * `is_numeric` exclut true/false et les tableaux, fermant le type-juggling
 * PHP (`int == true`) tout en acceptant les ids envoyés en string JSON ("5").
 *
 * @param  mixed  $userAnswer
 */
private function normalizeAnswerId($userAnswer): ?int
{
    return is_numeric($userAnswer) ? (int) $userAnswer : null;
}
```

- `is_numeric(true)` → false → `null` → jamais de match (REQ-3).
- `is_numeric([1,2])` → false → `null`.
- `is_numeric('5')` → true → `5` (REQ-4, string JSON légitime).
- `is_numeric(5)` → true → `5`.
- `is_numeric('5abc')` → false → `null`.

## 2. `checkAnswer` corrigé

```php
case 'multiple_choice':
    $answerId = $this->normalizeAnswerId($userAnswer);
    if ($answerId === null) {
        return false;   // valeur non-scalaire/non-numérique → incorrect (REQ-1/3)
    }
    return $answers->contains(
        fn ($a): bool => (int) $a->id === $answerId && $a->is_correct
    );

case 'true_false':
    $answerId = $this->normalizeAnswerId($userAnswer);
    if ($answerId === null) {
        return false;   // (REQ-2/3)
    }
    $correctAnswer = $answers->firstWhere('is_correct', true);
    return $correctAnswer !== null && (int) $correctAnswer->id === $answerId;
```

`multiple_response` (`:69-76`) **inchangé** — il fait déjà un exact-set-match `===`
sur des arrays d'ids triés. (Note : ses éléments viennent aussi du JSON ; le set
match string-vs-int pourrait diverger, mais REQ le laisse hors périmètre car pas
de faux positif « tout correct » — à vérifier en non-régression seulement.)

## 3. Validation d'entrée (REQ-5, défense en profondeur)

`SubmitQuizAttemptRequest` : ajouter une règle sur les valeurs. Contrainte : une
valeur est soit un **scalaire** (id unique) soit un **tableau** (multiple_response).
Rejeter les booléens et objets.

```php
public function rules(): array
{
    return [
        'answers' => ['required', 'array'],
        // Chaque réponse : un id scalaire (int / string numérique) OU un tableau
        // d'ids (multiple_response). Rejette bool/objet (#498).
        'answers.*' => ['nullable', function (string $attr, $value, \Closure $fail): void {
            if (is_bool($value)) {
                $fail('Une réponse ne peut pas être un booléen.');
                return;
            }
            $ok = is_int($value) || is_string($value) || is_array($value);
            if (! $ok) {
                $fail('Format de réponse invalide.');
            }
        }],
    ];
}
```

- `checkAnswer` reste la barrière **autoritaire** (défense en profondeur : même
  si un booléen passait, la notation le rejetterait). La validation ferme la porte
  en amont avec un 422 clair.

## 4. Décisions & justifications

| Décision | Pourquoi |
|---|---|
| `is_numeric()` puis `(int)` | Exclut bool/array/non-numérique SANS le piège `(int) true === 1` (Q15). |
| `(int) $a->id === $answerId` | Les deux côtés en int → strict, cohérent avec KnowledgeCheck. |
| Accepter `"5"` (string numérique) | Le frontend peut envoyer des ids en string JSON (REQ-4, non-régression). |
| Helper privé `normalizeAnswerId` | DRY entre les 2 branches, testable, lisible (≤40 l.). |
| Validation `answers.*` en défense en profondeur | Ferme le vecteur en amont (422) sans dépendre du type de question. |
| `multiple_response` non touché | Déjà exact-set `===`, pas de faux positif « tout correct ». |

## 5. Non-régression à surveiller

- Tests quiz existants (`SubmitAttemptHappyPathTest`, `QuizGradingServiceTest`,
  `QuizCrudResponseTest`) : les vraies réponses (int/string numérique) restent
  correctes.
- `BuildsAttemptResponses` : `is_correct`/`points_earned` inchangés pour réponses
  valides.

## 6. Fichiers touchés

| Fichier | Nature |
|---|---|
| `app/Services/Quiz/QuizGradingService.php` | + `normalizeAnswerId()`, durcir `checkAnswer` (2 branches) |
| `app/Http/Requests/SubmitQuizAttemptRequest.php` | + règle `answers.*` |
| `tests/Unit/Services/Quiz/QuizGradingServiceTest.php` | + cas type-juggling (bool/array/string) |
| `tests/Feature/Quiz/…` (nouveau ou existant) | + test d'atteignabilité HTTP `{"answers":{"id":true}}` → pas correct/422 |
