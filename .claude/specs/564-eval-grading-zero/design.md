# Design — #564 Notation évaluations = 0 + zéros poussés dans KLASSCI

## 1. Vue d'ensemble

Le correctif traite **deux défauts** derrière le symptôme #564 (notation = 0 poussée
dans KLASSCI) :

1. **Défaut de format (cause principale, prouvée).** `SubmitEvaluationRequest`
   valide/normalise une **LISTE** alors que le frontend, le service de correction et
   le quiz utilisent une **MAP** `{ "<question_id>": <réponse> }`. On aligne la
   request sur la **MAP** ; le frontend et le cœur du service sont déjà corrects.

2. **Défaut de correction manuelle (découvert en revue adversariale).** Les questions
   de type `dissertation` n'ont **aucune règle d'auto-correction** dans
   `EvaluationGradingService` (elles tombent sur `return false` → 0 point, points
   comptés au dénominateur → note déflatée) et **aucun chemin de notation manuelle
   n'existe** pour les évaluations. Ces notes déflatées/0 étaient poussées dans
   KLASSCI. → **fail-closed** : on refuse de synchroniser une évaluation contenant
   une question à correction manuelle tant que la notation manuelle n'existe pas.
   Le **vrai** endpoint de notation manuelle enseignant est traité en **issue de
   suivi #588** (débordement du périmètre hotfix #564).

> Correction d'une affirmation initiale erronée : ce n'était **pas** « un seul maillon
> fautif ». Le fix touche donc trois fichiers applicatifs (request + service + sync
> controller), tous dans le périmètre #564.

### Contrat réel du frontend (vérifié dans le code)

`TakeQuestionCard.vue` + `useTakeEvaluation.js` produisent, pour **chaque** question :

| Type question | Valeur `answers[question_id]` | Non répondu |
|---|---|---|
| `qcm` | chaîne = **texte de l'option** (ex. `"4"`, `"Paris"`) | `""` |
| `vrai_faux` | chaîne `"Vrai"` \| `"Faux"` | `""` |
| `reponse_courte` / `dissertation` | chaîne (texte libre) | `""` |
| `qcm_multiple` | **tableau de chaînes** (textes d'options) | `[]` |

Le frontend envoie **toutes** les questions (les non répondues incluses, sous forme
`""` ou `[]`). La validation doit donc **tolérer les valeurs vides** (question non
répondue = 0 point, légitime), et **rejeter** les types aberrants (booléen, entier,
tableau imbriqué).

## 2. Flux corrigé

```mermaid
sequenceDiagram
    participant FE as Frontend (map)
    participant RQ as SubmitEvaluationRequest
    participant CT as EvaluationStudentAttemptController
    participant SV as EvaluationGradingService
    participant DB as evaluation_submissions

    FE->>RQ: POST /evaluations/{id}/submit { answers: {"12":"Paris","13":["A","C"], "14":""} }
    RQ->>RQ: prepareForValidation() → trim (clés préservées) + default submitted_at
    RQ->>RQ: rules() → answers = map ; answers.* = string|array<string>|vide
    RQ-->>CT: validated('answers') = MAP (clés = question_id)
    CT->>SV: submit($submission)  (answers = MAP)
    SV->>SV: calculateScore(): pour chaque question, lit answers[$q->id]
    SV->>DB: score = points obtenus ; note_sur_20 = (obtenus/total)×bareme
    CT-->>FE: 201 { data:{ submission, score, note_sur_20 } }
```

## 3. Changements de code

### 3.1 `app/Http/Requests/SubmitEvaluationRequest.php` (SEUL fichier applicatif modifié)

**Constantes**
```php
private const MAX_ANSWER_LENGTH   = 10000; // anti-DOS, inchangé vs existant
private const MAX_MULTIPLE_ANSWERS = 200;  // borne le nb d'options cochées (qcm_multiple)
```

**`rules()`** — passage LISTE → MAP :
```php
return [
    'answers'   => ['required', 'array', 'min:1'],
    // Chaque valeur est indexée par question_id (MAP). Deux formes valides :
    //  - scalaire chaîne  : qcm / vrai_faux / reponse_courte / dissertation
    //  - tableau<chaîne>  : qcm_multiple
    // Vides ('' ou []) tolérés = question non répondue (0 point, légitime).
    // Rejet strict des autres types (bool/int/objet) → ferme le type-juggling (#498).
    'answers.*' => [$this->answerValueRule()],
    'submitted_at' => ['sometimes', 'date_format:Y-m-d\\TH:i:s\\Z'],
];
```
`answerValueRule(): Closure` (méthode privée ≤40 lignes) encapsule la logique :
```php
private function answerValueRule(): \Closure
{
    return function (string $attribute, mixed $value, \Closure $fail): void {
        if ($value === null || $value === '' || $value === []) {
            return; // non répondu
        }
        if (is_array($value)) {
            if (count($value) > self::MAX_MULTIPLE_ANSWERS) {
                $fail('Trop de réponses sélectionnées.'); return;
            }
            foreach ($value as $element) {
                if (!is_string($element) || mb_strlen($element) > self::MAX_ANSWER_LENGTH) {
                    $fail('Chaque réponse doit être une chaîne de 10000 caractères max.'); return;
                }
            }
            return;
        }
        if (!is_string($value) || mb_strlen($value) > self::MAX_ANSWER_LENGTH) {
            $fail('Chaque réponse doit être une chaîne de 10000 caractères max.');
        }
    };
}
```
> **Effet sur R1.4** : un payload au format LISTE `[{question_id, answer}]` a des
> valeurs `answers.*` de type **tableau associatif** `{question_id, answer}` dont
> l'élément `question_id` est un **entier** → `!is_string($element)` → **422**
> (rejet propre, plus jamais de 500 ni de 0 silencieux).

**`prepareForValidation()`** — trim map-safe, clés préservées :
```php
protected function prepareForValidation(): void
{
    if (!$this->has('submitted_at')) {
        $this->merge(['submitted_at' => now()->format('Y-m-d\\TH:i:s\\Z')]);
    }
    $answers = $this->input('answers');
    if (is_array($answers)) {
        $this->merge(['answers' => $this->trimAnswers($answers)]);
    }
}

/** Trim défensif : chaînes et éléments de tableaux ; autres types laissés tels
 *  quels (la validation les rejettera). Les clés (question_id) sont préservées. */
private function trimAnswers(array $answers): array
{
    return collect($answers)->map(function ($value) {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_array($value)) {
            return array_map(fn ($e) => is_string($e) ? trim($e) : $e, $value);
        }
        return $value;
    })->all(); // ->all() préserve les clés associatives
}
```

**`messages()`** — remplacer les messages liés à `answers.*.question_id` /
`answers.*.answer` (obsolètes) par les messages map (`answers.required`,
`answers.array`, `answers.min`). Les échecs de la closure portent leur propre message.

**`authorize()`** : **inchangé** (autorisations R4.2).

### 3.2 `EvaluationGradingService` — 2 ajouts ciblés

- **Lecture map inchangée** : `calculateScore` lit déjà `$submission->answers[$question->id]`
  (map). Le cast Eloquent `answers => array` décode `{"12":"Paris"}` en `[12 => "Paris"]`
  (PHP normalise les clés numériques), donc `isset($answers[$question->id])` matche.
  Vérifié par le test GREEN. Le calcul de note n'est pas modifié.
- **Garde robustesse (MEDIUM #564)** : dans la branche `reponse_courte` de
  `isCorrectAnswer`, on rejette une valeur non scalaire (`if (!is_scalar($answer)) return false;`)
  **avant** le `(string)`, ce qui évitait un « Array to string conversion » → 500 sur
  un payload mal formé.
- **Nouveau `requiresManualGrading(EvaluationQuestion): bool`** : `true` pour
  `dissertation` (seul type sans auto-correction). Miroir du concept quiz. Utilisé
  par le fail-closed du sync (§3.3).

### 3.3 `EvaluationKlassciSyncController` — fail-closed

- Injection DI de `EvaluationGradingService`.
- `syncToKlassci` charge désormais `questions` et **refuse (409)** de pousser si
  l'évaluation contient une question à correction manuelle
  (`hasManualGradingQuestion()`), avant toute construction de notes → aucune note
  déflatée/0 n'atteint KLASSCI (SIS officiel).
- `syncNotesToKlassci` (stub) reçoit la même garde pour ne pas marquer
  `synced_to_klassci=true` des soumissions non finalisées (sinon le futur push réel
  les sauterait).
- Le **re-push des 0 déjà poussés** reste une remédiation de données externe → §6.

## 4. Data model

- `evaluation_submissions.answers` (cast `array`) : après correctif, stocké en **MAP**
  `{ "<question_id>": "<réponse>" | ["<r1>","<r2>"] }` — identique au modèle quiz
  (`quiz_attempts.answers`). Aucune migration nécessaire (colonne JSON existante).
- Les soumissions **historiques** ont `answers` en **LISTE** → prises en charge par le
  plan de remédiation §6 (conversion liste→map avant recalcul).

## 5. Stratégie de test (TDD, pattern AAA)

### 5.1 Nouveau — `tests/Feature/Evaluation/EvaluationGradingScoreTest.php` (preuve de valeur)
1. `test_map_payload_all_correct_scores_full` — **GREEN principal** : map, 2 qcm corrects
   (10+10 pts, bareme 20) → **score=20, note_sur_20=20** (≠ 0). *(R2.1, R2.2)*
2. `test_map_payload_partial_correct_scores_partial` — 1 correct / 1 faux → score=10,
   note=10. *(R2.3)*
3. `test_map_payload_qcm_multiple_exact_set_scores` — réponse `["A","C"]` == correct set
   → points crédités. *(R1.3, R2.1)*
4. `test_map_payload_unanswered_values_are_zero_not_error` — `{"12":"", "13":[]}` → 201,
   score=0 (pas de 500, pas de 422). *(R1.1, R2.3, régression Défaut B)*
5. `test_obsolete_list_payload_is_rejected_422` — `[{question_id,answer}]` → **422**
   (plus de 0 silencieux ni 500). *(R1.4)*
6. `test_answer_exceeding_max_length_fails_422` — scalaire > 10000 → 422. *(R3.2)*
7. `test_boolean_answer_value_is_rejected_422` — `{"12": true}` → 422. *(R3.3)*
8. `test_grading_is_scoped_to_evaluation_institution` — multi-tenant : une soumission de
   l'institution A est notée sur les questions de A uniquement. *(N3)*

### 5.2 Réécriture — `tests/Feature/Requests/SubmitEvaluationRequestTest.php`
Les 18 cas passent du format liste au format **map**. Les 2 cas spécifiques au format
liste (`test_missing_question_id_fails`, `test_invalid_question_id_fails`) sont
**remplacés** par : `test_obsolete_list_payload_rejected`, `test_boolean_value_rejected`,
`test_map_with_empty_values_allowed`. Le reste (auth, deadline, draft, déjà soumis,
timestamp, DOS) conservé, payload adapté en map.

### 5.3 Réécriture ciblée — `tests/Feature/Evaluation/Student/EvaluationStudentAttemptResponseTest.php`
Les 2 cas `submitEvaluation` (`test_submit_success_returns_201_with_message_and_data`,
`test_submit_failure_returns_500_error_envelope`) : payload liste → map. Les autres cas
(start / time-status) inchangés.

### 5.4 Suppression
`EvaluationGradingScoreCharacterizationTest.php` (temporaire) supprimé — remplacé par 5.1.

### 5.5 Non-régression large
`php artisan test --filter=Evaluation` puis suite complète impactée verte avant push.

## 6. Plan de remédiation des données KLASSCI (documentaire — action = utilisateur)

> **Aucune action KLASSCI n'est exécutée par cette fenêtre.** Ce plan est proposé pour
> décision. Voir aussi `.claude/specs/564-eval-grading-zero/remediation-klassci.md`.

**Étape 1 — Recensement (lecture seule, sûr).**
Identifier les soumissions candidates : `status IN ('soumis','corrige')`, `answers`
non vide, et dont le **recalcul** (après conversion liste→map si nécessaire) donne une
note ≠ note stockée. Ne PAS présumer que tout 0 est faux : certains 0 sont légitimes.

**Étape 2 — Recalcul (local, idempotent, dry-run par défaut).**
Commande artisan dédiée : pour chaque soumission, normaliser `answers`
(liste→map), ré-exécuter `EvaluationGradingService::calculateScore`, produire un
rapport (avant/après). Écriture en base seulement en mode `--apply`.

**Étape 3 — Re-push KLASSCI (EXTERNE — décision utilisateur).**
Pour les évaluations avec `klassci_evaluation_id` et dont les notes ont changé,
re-pousser via le flux de sync. **C'est l'action sensible sur le SIS** : qui la
déclenche, sur quel environnement, avec quelle fenêtre de contrôle → à décider.

**Question ouverte à l'utilisateur** (posée en fin d'implémentation) :
- (a) La **commande de recalcul** (Étapes 1-2, sans push) doit-elle être livrée dans
  CETTE PR, dans une PR de suivi, ou pas du tout ?
- (b) Le **re-push KLASSCI** (Étape 3) : qui/quand/quel environnement ? (Je ne pousse rien.)

## 7. Gestion des erreurs

| Cas | Réponse |
|---|---|
| `answers` absent/vide | 422, message « Au moins une réponse est requise » |
| Valeur non-chaîne/non-tableau (bool, int, objet) | 422, message closure |
| Valeur > 10000 car. | 422, message closure |
| Format liste obsolète | 422 (élément `question_id` entier → rejet closure) |
| Exception service inattendue | 500 enveloppe générique existante (message fixe, pas de `getMessage()` — §1.2) |

## 8. Conformité PRODUCTION_STANDARDS

- Fichier request estimé ~200 lignes (<300) ; méthodes ≤40 lignes ; pas de `new`/Facade
  métier ; validation systématique (§1.5) ; pas de `getMessage()` exposé ; TDD (§1.3) ;
  aucun N+1 introduit (§1.4). PHPStan 0 erreur.
