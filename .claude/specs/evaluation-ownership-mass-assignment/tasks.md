# Evaluation ownership mass-assignment — Tasks

> Spec parent : [`requirements.md`](./requirements.md), [`design.md`](./design.md). Issue : [#124](https://github.com/ouedraogoissouf2012/lms_backend/issues/124).

## Stratégie de découpage

**1 seule PR** ciblée. Scope ~+265 lignes net (~+14 code applicatif, +250 tests, +3 spec docs). Précédent cohérent : PR #127 (#123, scope similaire). Découper créerait une fenêtre de vulnérabilité entre les merges.

**Ordre d'exécution** strictement séquentiel.

## Tâches

- [ ] **1. `StoreEvaluationRequest` : retirer la règle obsolète**
  - [ ] 1.1 Dans [`app/Http/Requests/StoreEvaluationRequest.php`](app/Http/Requests/StoreEvaluationRequest.php), retirer la ligne `'klassci_enseignant_id' => 'nullable|integer'` du `rules()`. _Requirements: REQ-3_
  - [ ] 1.2 Ajouter un commentaire WHY au-dessus de la liste rules : « Issue #124 : `klassci_enseignant_id` retiré — désormais dérivé du token serveur côté controller. ». _Requirements: REQ-3_
  - [ ] 1.3 Ne PAS retirer `klassci_evaluation_id` (référence KLASSCI légitime, pas un identifiant d'ownership). _Requirements: design §6_

- [ ] **2. `EvaluationController::store` : force serveur**
  - [ ] 2.1 Localiser la méthode `store(StoreEvaluationRequest $request)` (~ligne 70-250). _Requirements: REQ-1_
  - [ ] 2.2 Déplacer la résolution `$user = $this->authenticatedUser($request)` AU TOUT DÉBUT de la méthode (si elle est plus loin actuellement). Définir immédiatement le **guard 403** : `if ($user->klassci_enseignant_id === null) { return 403 }`. Inclure un message clair côté client. _Requirements: REQ-1_
  - [ ] 2.3 Retirer `'klassci_enseignant_id'` du tableau `$request->only([...])` (ligne ~175). _Requirements: REQ-1_
  - [ ] 2.4 Dans le second tableau `[matiere_nom => ..., classe_nom => ...]` du `array_merge`, ajouter `'klassci_enseignant_id' => $user->klassci_enseignant_id` (force serveur). _Requirements: REQ-1_
  - [ ] 2.5 Ajouter un commentaire WHY au-dessus du `Evaluation::create(array_merge(...))` : « Issue #124 : `klassci_enseignant_id` forcé depuis le token (jamais lu du body). Empêche la pollution d'inbox d'un autre enseignant. ». _Requirements: REQ-1_

- [ ] **3. `EvaluationController::update` : exclusion des 5 champs immuables**
  - [ ] 3.1 Localiser la méthode `update(UpdateEvaluationRequest $request, int $id)` (~ligne 256-310). _Requirements: REQ-2_
  - [ ] 3.2 Remplacer `$evaluation->update($request->except(['questions']))` par `$evaluation->update($request->except(['questions', 'klassci_enseignant_id', 'institution_id', 'klassci_classe_id', 'klassci_matiere_id', 'klassci_evaluation_id']))`. _Requirements: REQ-2_
  - [ ] 3.3 Ajouter un commentaire WHY au-dessus, listant les 5 champs exclus et leur raison (ownership, tenant, cible, matière, référence KLASSCI). _Requirements: REQ-2_

- [ ] **4. PHP lint**
  - [ ] 4.1 `php -l app/Http/Requests/StoreEvaluationRequest.php` → No syntax errors. _Requirements: REQ-3_
  - [ ] 4.2 `php -l app/Http/Controllers/API/EvaluationController.php` → No syntax errors. _Requirements: REQ-1, REQ-2_

- [ ] **5. Tests Feature**
  - [ ] 5.1 Créer `tests/Feature/Security/EvaluationOwnershipMassAssignmentTest.php` avec `RefreshDatabase` + `Sanctum::actingAs` + helpers (`institution`, `teacher`, `evaluation`). _Requirements: REQ-5_
  - [ ] 5.2 Implémenter `test_create_evaluation_forces_klassci_enseignant_id_from_token` : enseignant A (`klassci_enseignant_id=42`) POST body forge `<999>` → asserter éval créée avec `klassci_enseignant_id === 42`. _Requirements: REQ-1, REQ-5 #1_
  - [ ] 5.3 Implémenter `test_create_evaluation_blocked_for_user_without_klassci_enseignant_id` : user authentifié avec `klassci_enseignant_id = null` POST → 403 avec message clair. _Requirements: REQ-1, REQ-5 #2_
  - [ ] 5.4 Implémenter `test_create_evaluation_ignores_klassci_enseignant_id_from_body_silently` : A POST sans le champ, puis A POST avec le champ → 2 évals créées avec `klassci_enseignant_id === A->klassci_enseignant_id`. _Requirements: REQ-1, REQ-5 #3_
  - [ ] 5.5 Implémenter `test_update_evaluation_cannot_transfer_ownership` : A possède E (`klassci_enseignant_id=A`). PUT body forge `<B>` → 200 OK, `evaluation->fresh()->klassci_enseignant_id === A`. _Requirements: REQ-2, REQ-5 #4_
  - [ ] 5.6 Implémenter `test_update_evaluation_cannot_change_institution_id` : A PUT body `{"institution_id": <autre>}` → `institution_id` inchangé. _Requirements: REQ-2, REQ-5 #5_
  - [ ] 5.7 Implémenter `test_update_evaluation_cannot_change_klassci_classe_id` : A PUT body `{"klassci_classe_id": <autre>}` → `klassci_classe_id` inchangé. _Requirements: REQ-2, REQ-5 #6_
  - [ ] 5.8 Implémenter `test_update_evaluation_can_still_change_titre` : A PUT body `{"titre": "Nouveau titre"}` → `titre` mis à jour (régression check). _Requirements: REQ-2, REQ-5 #7, REQ-6_

- [ ] **6. Régression check**
  - [ ] 6.1 `vendor/bin/phpunit tests/Feature/Security` → 20 tests pré-existants intacts + 7 nouveaux = 27 tests chargés. _Requirements: REQ-5, REQ-6_
  - [ ] 6.2 `vendor/bin/phpunit tests/Feature/LMS` → 50 tests existants intacts. _Requirements: REQ-6_
  - [ ] 6.3 `vendor/bin/phpunit tests/Feature/Quiz tests/Feature/Forum tests/Feature/Notifications tests/Feature/Files` → suites intactes. _Requirements: REQ-6_

- [ ] **7. PHPStan check**
  - [ ] 7.1 `vendor/bin/phpstan analyse --no-progress --memory-limit=2G` → `[OK] No errors`. _Requirements: critère d'acceptation §2_

- [ ] **8. Audits read-only**
  - [ ] 8.1 Lancer `spec-security` + `spec-architect` en parallèle sur le diff. Cible : 0 finding HIGH/CRITICAL. _Requirements: critères §4 + §5_
  - [ ] 8.2 Corriger les findings éventuels AVANT 8.3.
  - [ ] 8.3 Lancer `spec-reviewer` (15 questions). Verdict cible : MERGE-READY. _Requirements: critère §6_

- [ ] **9. Validation locale finale**
  - [ ] 9.1 Récap diff : `git diff lms..HEAD --stat`. Bilan attendu : ~5-6 fichiers, net `≈ +265` lignes (10 code + 250 tests + spec docs). _Requirements: critère §1_
  - [ ] 9.2 Vérifier par grep que `$request->only([...])` dans `store` ne contient plus `klassci_enseignant_id`. _Requirements: REQ-1_
  - [ ] 9.3 Vérifier par grep que `$request->except([...])` dans `update` contient les 5 champs immuables. _Requirements: REQ-2_

- [ ] **10. Commit + push + PR + fermeture #124**
  - [ ] 10.1 Présenter le récap des changements à l'utilisateur AVANT `git commit` (rule `feedback_no_commit_without_approval`).
  - [ ] 10.2 Sur approbation explicite, créer 1 commit Conventional Commit type `fix(security)` avec mention `closes #124` dans le body. _Requirements: critère §7_
  - [ ] 10.3 `git push -u origin fix/security-124-evaluation-ownership-mass-assignment`.
  - [ ] 10.4 `gh pr create --base lms --title "fix(security): force server-side klassci_enseignant_id on evaluation create/update (closes #124)"` avec body-file détaillé.
  - [ ] 10.5 Attendre que l'utilisateur merge la PR côté GitHub. Pas de `gh pr merge --auto`.
  - [ ] 10.6 Post-merge : `gh issue close 124 -c "Résolu par PR #XXX..."`.

## Récap mapping `_Requirements_`

| REQ | Tasks |
|---|---|
| REQ-1 (CREATE force serveur + guard null) | 2.1-2.5, 5.2, 5.3, 5.4 |
| REQ-2 (UPDATE exclusion 5 champs immuables) | 3.1-3.3, 5.5, 5.6, 5.7 |
| REQ-3 (FormRequest retrait règle) | 1.1, 1.2 |
| REQ-4 (model `$fillable` préservé) | (no code change — design §7 justifie) |
| REQ-5 (7 tests obligatoires) | 5.1-5.8 |
| REQ-6 (régression check) | 5.8, 6.1-6.3 |

## Estimation et risques

- **Temps estimé** : ~2h en exécution séquentielle locale. Précédents similaires #122 / #127.
- **Risque principal** : un test Feature existant qui dépendait du comportement vulnérable (création avec `klassci_enseignant_id` du body) pourrait casser. **Mitigation** : tâche 6.1-6.3 (régression check exhaustive). Si cassure, analyser au cas par cas — soit corriger le test (s'il testait un comportement non-spec), soit ouvrir un cas pour révision.
- **Risque secondaire** : un client frontend (mobile, intégration) envoie aujourd'hui `klassci_enseignant_id` dans son body pour le store. Le comportement post-PR est de l'ignorer silencieusement → le client continuera à fonctionner mais la valeur sera serveur (correcte). **Pas un breaking change perceptible** côté client (le résultat reste cohérent).
- **Risque tertiaire** : le guard 403 « pas de `klassci_enseignant_id` synchronisé » peut bloquer un admin LMS qui voudrait créer une éval pour debug. **Mitigation** : aucun cas connu, design §11 critère 1 documente le pivot vers route admin dédiée si besoin.
