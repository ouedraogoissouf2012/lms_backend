# klassci_etudiant_id from token — Tasks

> Spec parent : [`requirements.md`](./requirements.md), [`design.md`](./design.md). Issue : [#123](https://github.com/ouedraogoissouf2012/lms_backend/issues/123).

## Stratégie de découpage

**1 seule PR** ciblée sur la racine du problème. Scope ~+40 lignes net (`−200` code applicatif via suppression `studentEvaluations` et `Validator::make` inline, `+240` tests + spec). Précédent cohérent : PR #122 (#119, scope comparable). Découper créerait une fenêtre de vulnérabilité entre les merges.

**Ordre d'exécution** : strictement séquentiel.
- 1 → 5 : code applicatif
- 6 : vérification avant suppression `studentEvaluations`
- 7 → 9 : tests
- 10 : audits (security + architect en parallèle, puis reviewer)
- 11 : validation locale
- 12 : commit + push + PR + post-merge

## Tâches

- [ ] **1. `StartEvaluationRequest` : retirer la règle vulnérable + serrer `authorize()`**
  - [ ] 1.1 Dans [`app/Http/Requests/StartEvaluationRequest.php`](app/Http/Requests/StartEvaluationRequest.php), retirer la règle `'klassci_etudiant_id' => 'required|integer|min:1'` du `rules()`. _Requirements: REQ-3, REQ-5_
  - [ ] 1.2 Retirer les 3 messages associés (`klassci_etudiant_id.required`, `.integer`, `.min`) du `messages()`. _Requirements: REQ-3_
  - [ ] 1.3 Modifier `authorize()` : remplacer `return auth()->check();` par `return auth()->user()?->isStudent() === true;`. Ajouter un commentaire WHY (« Issue #123 : seuls les étudiants démarrent une évaluation ; prof/coordinateur n'ont pas vocation à passer une éval »). _Requirements: REQ-3_
  - [ ] 1.4 Mettre à jour la PHPDoc de classe : retirer la phrase « klassci_etudiant_id must match authenticated user's KLASSCI ID » qui devient trompeuse ; remplacer par « Identité étudiant dérivée du token Sanctum (cf. issue #123). Body never read for klassci_etudiant_id. ». _Requirements: REQ-3_

- [ ] **2. `EvaluationController::startEvaluation` : signature + suppression Validator inline + résolution `$user` au début**
  - [ ] 2.1 Modifier la signature de [`startEvaluation`](app/Http/Controllers/API/EvaluationController.php#L601) : `public function startEvaluation(int $id, StartEvaluationRequest $request): JsonResponse`. Ajouter `use App\Http\Requests\StartEvaluationRequest;` en tête de fichier si pas présent. _Requirements: REQ-2, REQ-3_
  - [ ] 2.2 Déplacer la résolution `$user = $this->authenticatedUser($request)` au TOUT DÉBUT de la méthode (avant la lookup `Evaluation::find($id)`). Définir immédiatement `$klassciEtudiantId = $user->klassci_id;`. _Requirements: REQ-2_
  - [ ] 2.3 Supprimer le bloc `Validator::make($request->all(), ['klassci_etudiant_id' => 'required|integer'])` et son `if ($validator->fails()) { return 422 }` (lignes ~624-633). _Requirements: REQ-2_
  - [ ] 2.4 Supprimer la ligne `$klassciEtudiantId = $request->klassci_etudiant_id;` (ligne ~635). _Requirements: REQ-2_
  - [ ] 2.5 Supprimer le 2ᵉ `$user = $this->authenticatedUser($request)` dans le try-catch ligne ~639 (devenu doublon de 2.2). _Requirements: REQ-2_
  - [ ] 2.6 Ajouter un commentaire WHY au-dessus de `$klassciEtudiantId = $user->klassci_id` : « Issue #123 : identité étudiant dérivée du token Sanctum, jamais lue du body. ». _Requirements: REQ-1, REQ-2_

- [ ] **3. Suppression dead code : route + méthode `studentEvaluations`**
  - [ ] 3.1 Étape de **vérification** : lire [`EvaluationController::myEvaluations`](app/Http/Controllers/API/EvaluationController.php) (ligne 638 routes) et confirmer qu'elle fait essentiellement le même travail que `studentEvaluations` (lookup dashboard via token, retour des évals enrichies avec submissions). Si différent : noter les fonctionnalités manquantes et adapter la stratégie de suppression. _Requirements: REQ-4_
  - [ ] 3.2 Si `myEvaluations` couvre tout : supprimer entièrement la méthode `studentEvaluations(int $klassciEtudiantId, Request $request)` lignes ~411-595 (≈180 lignes). _Requirements: REQ-4_
  - [ ] 3.3 Supprimer la route `Route::get('evaluations/student/{klassciEtudiantId}', [EvaluationController::class, 'studentEvaluations']);` dans `routes/api.php` (ligne 641). _Requirements: REQ-4_
  - [ ] 3.4 Vérifier qu'aucun `php artisan route:list` n'affiche plus la route avec param. _Requirements: REQ-4, REQ-5_

- [ ] **4. Audit grep post-modification**
  - [ ] 4.1 Exécuter `grep -rn '\$request->klassci_etudiant_id' app/` — résultat attendu : 0 hit. _Requirements: REQ-5_
  - [ ] 4.2 Exécuter `grep -n '{klassciEtudiantId}' routes/api.php` — résultat attendu : 0 hit. _Requirements: REQ-5_
  - [ ] 4.3 Exécuter `grep -rn 'function.*int \$klassciEtudiantId' app/Http/Controllers/` — résultat attendu : 0 hit. _Requirements: REQ-5_
  - [ ] 4.4 Vérifier qu'aucun import de `StartEvaluationRequest` n'a été oublié dans `EvaluationController`. _Requirements: REQ-3_

- [ ] **5. PHP lint sur les fichiers touchés**
  - [ ] 5.1 `php -l app/Http/Requests/StartEvaluationRequest.php` → No syntax errors. _Requirements: REQ-3_
  - [ ] 5.2 `php -l app/Http/Controllers/API/EvaluationController.php` → No syntax errors. _Requirements: REQ-2, REQ-4_
  - [ ] 5.3 `php -l routes/api.php` → No syntax errors. _Requirements: REQ-4_

- [ ] **6. Smoke check `myEvaluations` est bien intacte**
  - [ ] 6.1 `php artisan route:list --path=evaluations/student` doit montrer **seulement** `GET evaluations/student` (sans param), nommée `myEvaluations`. _Requirements: REQ-4, REQ-7_

- [ ] **7. Tests Feature — IDOR étudiant fermé**
  - [ ] 7.1 Créer `tests/Feature/Security/KlassciEtudiantIdFromTokenTest.php` avec `RefreshDatabase` + `Sanctum::actingAs` + helpers (`institution`, `evaluation`, `student`). _Requirements: REQ-6_
  - [ ] 7.2 Implémenter `test_start_evaluation_ignores_klassci_etudiant_id_from_body` : étudiant A (klassci_id=42) POST `/api/evaluations/{id}/start` avec body `{"klassci_etudiant_id": 999}` → asserter qu'une `EvaluationSubmission` est créée avec `klassci_etudiant_id === 42` (PAS 999). _Requirements: REQ-2, REQ-6 #1_
  - [ ] 7.3 Implémenter `test_start_evaluation_reuses_active_submission_of_authenticated_user_only` : créer une submission existante `(evaluation_id=X, klassci_etudiant_id=42, status='en_cours')` ; A POST avec body forge `<999>` → la response data référence la submission de 42 (pas une nouvelle, pas celle de 999). _Requirements: REQ-2, REQ-6 #2_
  - [ ] 7.4 Implémenter `test_start_evaluation_blocked_for_non_student` : enseignant POST `/start` → 403 (autorize bloque). _Requirements: REQ-3, REQ-6 #3_
  - [ ] 7.5 Implémenter `test_start_evaluation_max_attempts_counts_authenticated_user_only` : étudiant A a 3 submissions terminées (statuses 'soumis'/'corrige'), évaluation `max_attempts=3`, body forge `<999>` (qui a 0 submission) → 403 « Nombre maximum de tentatives atteint » (le count de A est utilisé). _Requirements: REQ-2, REQ-6 #4_
  - [ ] 7.6 Implémenter `test_get_student_evaluations_with_param_returns_404` : étudiant authentifié appelle `GET /api/evaluations/student/<X>` → 404 (route inexistante). _Requirements: REQ-4, REQ-6 #5_
  - [ ] 7.7 Implémenter `test_get_my_evaluations_route_returns_evaluations_for_authenticated_user` : étudiant A appelle `GET /api/evaluations/student` (sans param) → 200 et le payload concerne A (vérifier via la submission présente). _Requirements: REQ-4, REQ-6 #6, REQ-7_

- [ ] **8. Régression check**
  - [ ] 8.1 `vendor/bin/phpunit tests/Feature/LMS` → 50 tests existants intacts. _Requirements: REQ-7_
  - [ ] 8.2 `vendor/bin/phpunit tests/Feature/Security` → 14 tests pré-existants intacts + 6 nouveaux = 20 tests chargés. _Requirements: REQ-6, REQ-7_
  - [ ] 8.3 `vendor/bin/phpunit tests/Feature/Quiz tests/Feature/Forum tests/Feature/Notifications tests/Feature/Files` → suites intactes. _Requirements: REQ-7_

- [ ] **9. PHPStan check**
  - [ ] 9.1 `vendor/bin/phpstan analyse --no-progress --memory-limit=2G` → `[OK] No errors`. Si baseline gonfle, investiguer (suppression `studentEvaluations` peut **réduire** la baseline — bonus). _Requirements: critère d'acceptation §2_

- [ ] **10. Audits read-only**
  - [ ] 10.1 Lancer `spec-security` + `spec-architect` en parallèle sur le diff. Cible : 0 finding HIGH/CRITICAL. _Requirements: critères §4 + §5_
  - [ ] 10.2 Corriger les findings éventuels AVANT 10.3.
  - [ ] 10.3 Lancer `spec-reviewer` qui consomme les 2 rapports + 15 questions. Verdict cible : MERGE-READY. _Requirements: critère §6_

- [ ] **11. Validation locale finale**
  - [ ] 11.1 Récap diff : `git diff lms..HEAD --stat`. Le bilan attendu : ~7-8 fichiers, net `≈ +40` lignes (suppression `studentEvaluations` ~180 lignes + suppression `Validator` inline ~10 lignes + ajout tests ~220 + spec ~3 fichiers). _Requirements: critère §1_
  - [ ] 11.2 Confirmer que `php artisan route:list --path=evaluations` ne contient plus `evaluations/student/{klassciEtudiantId}` mais bien `evaluations/student` (sans param). _Requirements: REQ-4_

- [ ] **12. Commit + push + PR + fermeture #123**
  - [ ] 12.1 Présenter le récap des changements à l'utilisateur AVANT `git commit` (rule `feedback_no_commit_without_approval`).
  - [ ] 12.2 Sur approbation explicite, créer 1 commit Conventional Commit type `fix(security)` avec mention `closes #123` dans le body. _Requirements: critère §7_
  - [ ] 12.3 `git push -u origin fix/critical-123-klassci-etudiant-id-from-token`.
  - [ ] 12.4 `gh pr create --base lms --title "fix(security): derive klassci_etudiant_id from Sanctum token, kill IDOR vector on student evaluations (closes #123)"` avec body-file détaillé : scénario d'attaque, REQs couverts, breaking changes (section dédiée), tests passants, audits PASS.
  - [ ] 12.5 Attendre que l'utilisateur merge la PR côté GitHub. Pas de `gh pr merge --auto`.
  - [ ] 12.6 Post-merge : `gh issue close 123 -c "Résolu par PR #XXX..."` (la branche `lms` ≠ default → `closes #123` ne s'auto-ferme pas).

## Récap mapping `_Requirements_`

| REQ | Tasks |
|---|---|
| REQ-1 (identité = token unique) | 2.6 |
| REQ-2 (`startEvaluation` n'utilise que `$user->klassci_id`) | 2.1-2.6, 7.2-7.5 |
| REQ-3 (FormRequest retrait règle + authorize étudiant) | 1.1-1.4, 2.1, 4.4, 7.4 |
| REQ-4 (suppression route + méthode `studentEvaluations`) | 3.1-3.4, 6.1, 7.6, 11.2 |
| REQ-5 (grep audit final propre) | 1.1, 4.1-4.3 |
| REQ-6 (6 tests obligatoires) | 7.1-7.7, 8.2 |
| REQ-7 (régression check) | 6.1, 7.7, 8.1-8.3 |

## Estimation et risques

- **Temps estimé** : ~2-3h en exécution séquentielle locale. Plus court que #119 (#122) car le code applicatif change peu (~−200 lignes via suppression, ~+5 lignes ajoutées).
- **Risque principal** : la **vérification de `myEvaluations`** (tâche 3.1) peut révéler des fonctionnalités absentes de `myEvaluations` mais présentes dans `studentEvaluations`. Si oui, la stratégie pivote vers « renommer `studentEvaluations` → `myEvaluations` avec déplacement de la lecture vers le token » au lieu de simplement supprimer. La spec design.md §4.3 prévoit ce pivot.
- **Risque secondaire** : un client frontend obscur (mobile, intégration externe) appelle peut-être `/api/evaluations/student/{klassciEtudiantId}` aujourd'hui. Le 404 post-merge cassera son UI. **Mitigation** : surveiller les logs 404 post-deploy ; restaurer une route admin propre `/admin/evaluations/student/{...}` si besoin (cf. design §11 critère d'invalidation point 2).
- **Risque tertiaire** : le `authorize()` strict étudiant (REQ-3) peut casser un workflow admin/coordinateur qui démarrait une éval pour debug. **Mitigation** : aucun cas connu, design §8 documente que c'est un changement **potentiellement breaking** mais non identifié. À signaler en PR body.
