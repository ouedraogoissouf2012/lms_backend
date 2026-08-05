# Tasks — Fenêtre d'évaluation fail-closed sur échec KLASSCI (#499)

Ordre TDD : RED → GREEN → non-régression → vérif.

## 1. Tests RED

- [ ] **1.1** `tests/Feature/Evaluation/Student/…` (existant ou nouveau
  `EvaluationWindowFailClosedTest`) — mock `KlassciProxyService::requestWithUserToken`
  qui **lève une exception**, éval **non-entraînement** publiée avec questions →
  `POST /api/evaluations/{id}/start` → **503**, ET `EvaluationSubmission` count
  reste **0**. _(REQ-2/3, AC1.)_ **Doit échouer** aujourd'hui (fail-open → 200 + submission créée).
- [ ] **1.2** Mock KLASSCI qui lève, éval en **mode entraînement** (`isTerminee`)
  → démarrage **autorisé** (200/201). _(REQ-6, AC2.)_
- [ ] **1.3** Non-régression : KLASSCI OK + éval **sans fenêtre** → 200 (REQ-4) ;
  KLASSCI OK + fenêtre **fermée** (`has_ended`) → 403 `window_closed` (REQ-5) ;
  KLASSCI OK + fenêtre **ouverte** → 200. _(AC3/4/5.)_
- [ ] **Lancer 1.1 → RED.**

## 2. Implémentation GREEN

- [ ] **2.1** `EvaluationAttemptStateService` : remplacer `fetchWindowSafe(): ?array`
  par `fetchWindow(): array{fetched: bool, window: ?array}`. _(REQ-1.)_
- [ ] **2.2** Ajouter `resolveWindowGate(...)` (fetch + fail-closed hors
  entraînement + checkWindow) retournant `[?error, ?window]`. _(REQ-2/6.)_
- [ ] **2.3** `startAttempt` : remplacer le bloc `:60-66` par l'appel à
  `resolveWindowGate` (déstructuration `[$windowError, $window]`). `checkWindow`
  inchangé. _(REQ-2.)_
- [ ] **2.4** `getTimeStatus` : `$window = $this->fetchWindow(...)['window']`. _(REQ-7.)_
- [ ] **2.5** `EvaluationStudentAttemptController::startEvaluation` : ajouter le
  bras `'window_check_failed' => errorResponse($result['message'], 503)` avant
  `default`. _(REQ-3.)_
- [ ] **2.6** Lancer 1.1/1.2/1.3 → **GREEN**.

## 3. Non-régression

- [ ] **3.1** `php artisan test tests/Feature/Evaluation/ tests/Feature/Security/KlassciEtudiantIdFromTokenTest.php`
  → 100 % (start/submit, window_closed, tenant).
- [ ] **3.2** Vérifier qu'aucun autre appelant de `fetchWindowSafe` ne subsiste
  (grep) — renommage complet.

## 4. Vérification

- [ ] **4.1** PHPStan level 9 sur les 2 fichiers → 0 erreur (attention au type de
  retour `array{fetched: bool, window: ?array}` et à la déstructuration).
- [ ] **4.2** Garde tailles : `startAttempt` réduite (≤40 visé), `resolveWindowGate`
  + `fetchWindow` ≤40, service ≤300.
- [ ] **4.3** Grep : plus aucun `fetchWindowSafe`.

## 5. Clôture

- [ ] **5.1** Après merge PR : fermer #499 + cocher la case dans l'épique #496.

## Traçabilité exigences → tâches

| Exigence | Tâche(s) |
|---|---|
| REQ-1 (distinguer) | 2.1 |
| REQ-2 (fail-closed) | 1.1, 2.2, 2.3 |
| REQ-3 (503) | 1.1, 2.5 |
| REQ-4 (no-window OK) | 1.3, 2.2 |
| REQ-5 (window_closed 403) | 1.3 |
| REQ-6 (entraînement permissif) | 1.2, 2.2 |
| REQ-7 (getTimeStatus) | 2.4 |
