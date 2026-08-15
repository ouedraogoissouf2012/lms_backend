# Tasks — #564 Notation évaluations = 0 + zéros poussés dans KLASSCI

> Checklist hiérarchique (max 2 niveaux). Chaque tâche référence un requirement.
> Ordre TDD strict : test RED d'abord, puis code GREEN.

## 1. Preuve RED (test-first)
- [ ] 1.1 Créer `tests/Feature/Evaluation/EvaluationGradingScoreTest.php` avec le cas
  GREEN principal `test_map_payload_all_correct_scores_full` (map, 2 qcm corrects,
  bareme 20 → score=20, note=20). Il DOIT échouer maintenant (RED : 500 sur map).
  _Requirements: R2.1, R2.2, N2_
- [ ] 1.2 Lancer le test → confirmer l'échec (RED) et capturer la sortie. _Requirements: N2_

## 2. Correctif du contrat (GREEN)
- [ ] 2.1 `SubmitEvaluationRequest::rules()` : LISTE → MAP (`answers` map,
  `answers.*` via closure scalaire-chaîne | tableau<chaîne> | vide). _Requirements: R1.1, R1.2, R1.3, R3.2, R3.3_
- [ ] 2.2 Ajouter `answerValueRule(): Closure` + constantes `MAX_ANSWER_LENGTH`,
  `MAX_MULTIPLE_ANSWERS` (méthode ≤40 lignes). _Requirements: R1.4, R3.2, R3.3_
- [ ] 2.3 `prepareForValidation()` : remplacer le `array_merge` (qui plante sur map) par
  un trim map-safe via `trimAnswers()` (clés préservées). _Requirements: R1.1 (fin du 500)_
- [ ] 2.4 `messages()` : retirer les messages `answers.*.question_id`/`answers.*.answer`
  obsolètes ; garder/adapter `answers.required|array|min`. _Requirements: R3.1_
- [ ] 2.5 Vérifier `authorize()` inchangé + fichier ≤300 lignes, méthodes ≤40. _Requirements: R4.2, N1_
- [ ] 2.6 Relancer 1.1 → GREEN. _Requirements: N2_

## 3. Couverture complète (nouveaux cas)
- [ ] 3.1 Compléter `EvaluationGradingScoreTest` : partiel, qcm_multiple, valeurs vides
  (201 pas 500), liste obsolète→422, >10000→422, booléen→422, multi-tenant.
  _Requirements: R1.3, R1.4, R2.3, R3.2, R3.3, N3_
- [ ] 3.2 Supprimer `EvaluationGradingScoreCharacterizationTest.php` (temporaire). _Requirements: —_

## 4. Réécriture des tests existants (format map)
- [ ] 4.1 `tests/Feature/Requests/SubmitEvaluationRequestTest.php` : 18 cas → map ;
  remplacer les 2 cas spécifiques liste par cas map équivalents. _Requirements: R4.1, R4.2, R4.3_
- [ ] 4.2 `tests/Feature/Evaluation/Student/EvaluationStudentAttemptResponseTest.php` :
  2 cas `submitEvaluation` → payload map (start/time-status inchangés). _Requirements: R4.1_

## 5. Validation qualité (pré-push)
- [ ] 5.1 `php artisan test --filter=Evaluation` = 100% vert. _Requirements: N1_
- [ ] 5.2 Suite complète impactée verte (requests + evaluation + performance eval). _Requirements: N1_
- [ ] 5.3 `vendor/bin/phpstan analyse --memory-limit=2G` = 0 erreur. _Requirements: N1_
- [ ] 5.4 Revue `/thermo-nuclear-code-quality-review` (fallback : production-grade-standards
  + `/code-review`) + audits `spec-security`/`spec-architect`/`spec-reviewer`. _Requirements: N1_
- [ ] 5.5 Grep anti-patterns (`getMessage()` exposé, `dd`, `var_dump`). _Requirements: N4_

## 6. Remédiation données KLASSCI (documentaire)
- [ ] 6.1 Rédiger `.claude/specs/564-eval-grading-zero/remediation-klassci.md` (recensement
  → recalcul dry-run idempotent → re-push externe). _Requirements: R5.1, R5.3_
- [ ] 6.2 **Poser les 2 questions à l'utilisateur** (livrer la commande de recalcul ? ;
  qui/quand/où pour le re-push). Ne rien pousser. _Requirements: R5.2_

## 6bis. Findings de la revue adversariale multi-agents (traités)
- [x] 6b.1 **HIGH confirmé** : dissertation notée 0/déflatée poussée dans KLASSCI
  (aucune correction manuelle). Décision user : sécurité in-périmètre + suivi.
- [x] 6b.2 `EvaluationGradingService::requiresManualGrading()` (dissertation) — miroir quiz.
- [x] 6b.3 `EvaluationKlassciSyncController` : fail-closed 409 si question à correction
  manuelle (syncToKlassci + syncNotesToKlassci), DI du grading service. _Requirements: R5.2_
- [x] 6b.4 **MEDIUM confirmé** : garde `is_scalar` dans `reponse_courte` → plus de 500
  sur payload mal formé. _Requirements: R3.3_
- [x] 6b.5 Tests : `test_sync_blocked_when_evaluation_has_manual_grading_question`,
  `test_array_answer_for_scalar_type_does_not_error`. RED→GREEN prouvés.
- [x] 6b.6 Docs corrigées (design §1/§3, remediation §5) — retrait des affirmations
  « un seul maillon fautif » / « fin de tous les nouveaux 0 » (honnêteté).
- [x] 6b.7 Issue de suivi ouverte : **#588** (endpoint de notation manuelle enseignant
  pour les dissertations — finaliser + pousser ces notes). _Requirements: R5.1_

## 7. Livraison
- [ ] 7.1 `git add -f` des specs `.claude/specs/564-*` + code + tests. _Requirements: —_
- [ ] 7.2 Commit conventional (sujet ≤70, trailer Co-Authored-By) — **après accord user**. _Requirements: —_
- [ ] 7.3 Push + PR vers `lms` (`Closes #564`) — **après accord user**. _Requirements: —_
- [ ] 7.4 Reporter le n° de PR à l'orchestrateur ; l'utilisateur merge. _Requirements: —_
