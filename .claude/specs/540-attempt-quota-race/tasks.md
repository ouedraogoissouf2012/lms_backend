# #540 — Tâches

Ordre imposé par le TDD : chaque tâche d'implémentation est précédée du test
RED qui la justifie.

## 1. Filet base (knowledge-check)

- [ ] 1.1 Test RED structurel : `knowledge_check_attempts` porte un index unique
      sur `(knowledge_check_id, user_id, attempt_number)` — échoue aujourd'hui,
      la colonne n'existe même pas. _Requirements: R1.1_
- [ ] 1.2 Test RED : deux tentatives de même `attempt_number` sont rejetées par la
      base (`UniqueConstraintViolationException`). _Requirements: R1.1_
- [ ] 1.3 Migration `add_attempt_number_to_knowledge_check_attempts_table` :
      colonne + backfill 1..n déterministe + unique. _Requirements: R1.1, R1.2, R1.3_
- [ ] 1.4 Test du backfill : trois tentatives préexistantes du même couple
      reçoivent 1, 2, 3 dans l'ordre chronologique. _Requirements: R1.2_

## 2. Garde de conflit partagée

- [ ] 2.1 Test unitaire RED `AttemptConflictGuardTest` : `created` /
      `resolved` / `unresolved`, et **aucune** autre exception avalée.
      _Requirements: R3.1, R3.2, R3.3_
- [ ] 2.2 `AttemptInsertOutcome` + `AttemptConflictGuard`. _Requirements: R3.x_

## 3. Évaluation — chemin nominal et course

- [ ] 3.1 Test RED : `/start` puis `/submit` renvoie un succès et **une seule**
      ligne en base (aujourd'hui : 500 + ligne orpheline). _Requirements: R2.1_
- [ ] 3.2 Test RED : `/start` renseigne `student_id` **et**
      `klassci_etudiant_id`. _Requirements: R2.2_
- [ ] 3.3 Test RED : étudiant sans `klassci_id` → 401, aucune ligne créée
      (aujourd'hui : 500 NOT NULL). _Requirements: R2.3_
- [ ] 3.4 Test RED de course déterministe : une insertion concurrente est
      intercalée entre le comptage et l'insertion → 200 « reprise », jamais 500.
      _Requirements: R3.1_
- [ ] 3.5 Test RED : conflit non re-résoluble → 409. _Requirements: R3.3_
- [ ] 3.6 Test RED : une tentative `en_cours` compte dans le quota.
      _Requirements: R4.1_
- [ ] 3.7 `EvaluationAttemptOpener` + branchement dans
      `EvaluationAttemptStateService` et dans `submitEvaluation`.
      _Requirements: R2.x, R3.x, R4.1_
- [ ] 3.8 `SubmitEvaluationRequest::authorize()` — Check 5 sur les soumissions
      finalisées. _Requirements: R2.1_
- [ ] 3.9 Migration de réparation `student_id` sur les lignes historiques.
      _Requirements: R2.2_

## 4. Knowledge-check — quota réellement appliqué

- [ ] 4.1 Test RED : `max_attempts = 1`, trois `/submit` → la 2ᵉ est refusée en
      400 et **une seule** ligne persiste (aujourd'hui : 3 lignes, 3× 200).
      _Requirements: R4.2_
- [ ] 4.2 Test RED : conflit d'insertion concurrent → 409, jamais 500.
      _Requirements: R3.3_
- [ ] 4.3 Quota au `submit` + `nextAttemptNumberForUser` + insertion sous garde +
      retour porteur de statut + `match` du controller. _Requirements: R3.3, R4.2_

## 5. Non-régression et livraison

- [ ] 5.1 Aucun nouveau statut de tentative introduit (relecture des enums et des
      migrations du diff). _Requirements: R5.1_
- [ ] 5.2 Suite impactée verte : `Evaluation`, `KnowledgeCheck`, `Quiz`,
      `Requests`, `Report`. _Requirements: R5.2_
- [ ] 5.3 PHPStan level 9 : 0 erreur, 0 entrée de baseline devenue morte.
- [ ] 5.4 `php artisan migrate` à blanc sur base neuve + `migrate:rollback` de
      la migration knowledge-check.
- [ ] 5.5 Revue qualité (production-grade-standards + `/code-review`) puis PR
      vers `lms`.
