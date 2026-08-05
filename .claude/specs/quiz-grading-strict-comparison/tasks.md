# Tasks — Notation quiz : comparaison stricte des réponses (#498)

Ordre TDD : RED → GREEN → non-régression → vérif.

## 1. Tests RED

- [ ] **1.1** `tests/Unit/Services/Quiz/QuizGradingServiceTest.php` — AJOUTER les
  cas type-juggling sur `checkAnswer` (multiple_choice ET true_false) :
  - `true` → **incorrect** (REQ-1/2/3) — DOIT échouer aujourd'hui (`==` note correct)
  - `[1,2]` (array sur question scalaire) → incorrect
  - `'5abc'` / `null` → incorrect
  - `5` (int = id bonne réponse) → **correct** (REQ-4, non-régression)
  - `'5'` (string numérique = id bonne réponse) → **correct** (REQ-4)
- [ ] **1.2** `tests/Feature/Quiz/…` — test d'atteignabilité HTTP :
  `POST /api/quiz-attempts/{id}/submit` `{"answers":{"<qid>":true}}` sur une
  question `multiple_choice` → score **0** (pas de faux positif) OU 422. _(AC1.)_
  DOIT échouer aujourd'hui (score plein).
- [ ] **Lancer 1.1/1.2 → RED.**

## 2. Implémentation GREEN

- [ ] **2.1** `QuizGradingService` — ajouter `normalizeAnswerId($userAnswer): ?int`
  (`is_numeric ? (int) : null`). _(REQ-3.)_
- [ ] **2.2** `checkAnswer` — brancher `normalizeAnswerId` dans `multiple_choice`
  (`:63-67`) et `true_false` (`:78-80`) : `null` → `false` ; sinon
  `(int) $a->id === $answerId`. _(REQ-1/2/4.)_
- [ ] **2.3** `SubmitQuizAttemptRequest` — règle `answers.*` rejetant bool/objet
  (closure). _(REQ-5.)_
- [ ] **2.4** Lancer 1.1/1.2 → **GREEN**.

## 3. Non-régression

- [ ] **3.1** `php artisan test tests/Feature/Quiz/ tests/Unit/Services/Quiz/`
  → 100 % (SubmitAttemptHappyPath, QuizGradingServiceTest, QuizCrudResponse,
  BuildsAttemptResponses via les réponses valides).
- [ ] **3.2** Vérifier `multiple_response` (arrays d'ids) toujours correct.
- [ ] **3.3** Suite KnowledgeCheck (partage la route submit) → inchangée.

## 4. Vérification

- [ ] **4.1** PHPStan level 9 sur `QuizGradingService` + `SubmitQuizAttemptRequest`
  → 0 erreur (attention : `$userAnswer` est `mixed`, `normalizeAnswerId(mixed)`
  doit être proprement typée).
- [ ] **4.2** Garde tailles : `checkAnswer` + `normalizeAnswerId` ≤ 40 l. chacune ;
  `QuizGradingService` ≤ 300.
- [ ] **4.3** Grep de contrôle : plus aucun `$a->id == $userAnswer` (==) dans
  `checkAnswer`.

## 5. Clôture

- [ ] **5.1** Après merge PR : fermer #498 + cocher la case dans l'épique #496.
  Noter la vérif `KnowledgeCheckGradingService` (déjà `===`, pas de dette).

## Traçabilité exigences → tâches

| Exigence | Tâche(s) |
|---|---|
| REQ-1 (mc strict) | 1.1, 2.2 |
| REQ-2 (tf strict) | 1.1, 2.2 |
| REQ-3 (rejet bool/non-num) | 1.1, 2.1 |
| REQ-4 (correct inchangé) | 1.1, 2.2, 3.1 |
| REQ-5 (validation entrée) | 2.3 |
| REQ-6 (call-sites) | 3.1 |
