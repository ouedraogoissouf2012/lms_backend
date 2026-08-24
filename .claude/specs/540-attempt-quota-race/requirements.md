# #540 — Races de quota de tentatives (éval + knowledge-check) sans filet DB fiable

> Sous-issue de #535 · P2 · Branche `fix/540-attempt-quota-race`

## 1. Constat vérifié (Phase 1 — audit, pas énoncé)

Chaque affirmation ci-dessous a été **exécutée**, pas déduite (sonde `ProbeAuditTest`,
suite locale SQLite, schéma dumpé via `Schema::getIndexes()`).

### 1.1 Évaluation — le filet DB se déclenche sur le chemin NOMINAL (500), pas sur la course

`evaluation_submissions` porte bien l'unique `eval_sub_unique
(evaluation_id, klassci_etudiant_id, attempt)` et les 3 colonnes sont **NOT NULL**.

> ⚠️ Correction de l'énoncé de l'issue : « `klassci_etudiant_id` **nullable** contourne
> l'unicité (plusieurs NULL admis) » est **faux**. Le dump du schéma réel donne
> `klassci_etudiant_id … nullable=false`. Aucune migration ne l'a jamais rendue nullable.
> Le contournement décrit n'existe pas ; le vrai défaut est ailleurs (§1.1.1).

#### 1.1.1 Racine : identité étudiant dédoublée

- `EvaluationAttemptStateService::resolveOrCreateSubmission()` crée la soumission avec
  `klassci_etudiant_id` **et laisse `student_id` à NULL** (`app/Services/Evaluation/Student/EvaluationAttemptStateService.php:107-116`).
- `EvaluationStudentAttemptController::submitEvaluation()` cherche la soumission active
  **par `student_id`** (`app/Http/Controllers/API/Evaluation/Student/EvaluationStudentAttemptController.php:82`)
  et, ne la trouvant jamais, en **recrée une** avec `attempt = 1` codé en dur (`:86-94`).
- La seconde insertion viole `eval_sub_unique` → `UniqueConstraintViolationException` →
  `catch (\Exception)` générique → **500**.

**Preuve exécutée** (`ProbeAuditTest::test_probe_eval_start_then_submit`) :

```
[P1] start status=200
[P1] row after start: student_id=NULL klassci=5555 attempt=1
[P1] submit status=500 body={"success":false,"message":"Erreur lors de la soumission"}
[P1] rows total=1
```

**Le parcours nominal « démarrer puis soumettre » d'une évaluation en ligne est cassé à 100 %.**
C'est la source réelle du « 500 brut » que l'issue attribue à la course.

#### 1.1.2 Course résiduelle

`resolveOrCreateSubmission()` fait READ (`count`) → CHECK (`max_attempts`) → WRITE (`create`)
hors transaction. Deux `start` concurrents calculent tous deux `attempt = 1` ; l'un gagne,
l'autre reçoit la violation d'unicité non rattrapée → 500.

Défaut secondaire : le comptage ignore `en_cours`
(`whereIn('status', ['soumis','corrige'])`, `:100`) — un quota se calcule sur les
tentatives **consommées**, pas seulement finalisées.

Défaut tertiaire : `startAttempt()` exige `klassci_token` mais **pas** `klassci_id`
(`:56-60`). Un étudiant non synchronisé atteint `create()` avec
`klassci_etudiant_id = NULL` → violation NOT NULL → 500.

### 1.2 Knowledge-check — aucun filet DB, et aucun contrôle de quota à la soumission

`knowledge_check_attempts` n'a **ni `attempt_number` ni index unique** (dump du schéma :
seuls des index non-uniques). Il n'existe donc aucun filet base.

Pire que l'énoncé : `KnowledgeCheckAttemptService::submitAttempt()`
(`app/Services/KnowledgeCheck/KnowledgeCheckAttemptService.php:127-160`) **ne vérifie
jamais le quota**. `canAttempt()` n'est appelé qu'au `start` — or `start` ne persiste
rien. Le quota est donc purement décoratif : il ne faut **aucune concurrence** pour le
contourner, il suffit d'appeler `/submit` en boucle.

**Preuve exécutée** (`ProbeAuditTest::test_probe_kc_quota_bypass_on_submit`, `max_attempts = 1`) :

```
[P2] submit #1 status=200
[P2] submit #2 status=200
[P2] submit #3 status=200
[P2] attempts persisted=3 (max_attempts=1)
```

## 2. Exigences (EARS)

### R1 — Filet base sur les tables de tentatives
- **R1.1** WHEN une tentative de knowledge-check est insérée, THEN la base SHALL rejeter
  toute seconde ligne de même `(knowledge_check_id, user_id, attempt_number)`.
- **R1.2** WHERE des tentatives de knowledge-check préexistent, THE migration SHALL leur
  attribuer un `attempt_number` déterministe (1..n par couple quiz/étudiant, dans l'ordre
  chronologique d'`id`) AVANT de poser l'unique, sans perte ni collision.
- **R1.3** THE migration SHALL être réversible (`down()`) et SHALL s'exécuter à l'identique
  sous SQLite et MySQL 8.

### R2 — Aucune violation d'unicité sur le chemin nominal
- **R2.1** WHEN un étudiant démarre puis soumet une évaluation, THEN le système SHALL
  réutiliser la soumission créée au démarrage et SHALL répondre en succès (jamais 500).
- **R2.2** THE service de démarrage SHALL renseigner `student_id` **et**
  `klassci_etudiant_id` sur toute soumission créée.
- **R2.3** IF l'étudiant n'a pas de `klassci_id`, THEN le démarrage SHALL être refusé en
  401 avec un message métier, sans tenter d'insertion.

### R3 — Conflit de course traité en métier, jamais en 500
- **R3.1** IF l'insertion d'une tentative viole l'unique, THEN le système SHALL
  re-résoudre la tentative gagnante et SHALL la renvoyer comme reprise (200) lorsqu'elle
  est encore ouverte — un double-clic ne SHALL PAS produire d'erreur.
- **R3.2** IF le conflit provient d'une tentative déjà finalisée (quota atteint), THEN le
  système SHALL répondre 403 `max_attempts`.
- **R3.3** IF le conflit ne peut être re-résolu, THEN le système SHALL répondre 409, et
  SHALL PAS 500.

### R4 — Le quota compte les tentatives consommées
- **R4.1** THE comptage de quota d'une évaluation SHALL inclure les tentatives `en_cours`.
  > **Honnêteté sur la preuve** : cette règle est de la défense en profondeur et n'est
  > **pas observable** via l'API — une tentative `en_cours` est systématiquement *reprise*
  > avant que le quota ne soit consulté, donc elle ne peut pas servir de contournement
  > côté évaluation. Le contournement réellement atteignable par des tentatives non
  > finalisées concerne le **quiz**, où aucune reprise n'a lieu (#581). Aucun test
  > dédié n'est écrit ici : il ne prouverait rien de plus que le code lui-même.
- **R4.2** WHEN un knowledge-check est soumis alors que le quota est atteint, THEN la
  soumission SHALL être refusée (400) et aucune ligne SHALL être persistée.
- **R4.3** THE numéro de tentative SHALL être `max(attempt) + 1` et non `count + 1`.
  Constaté : deux tentatives numérotées 1 et 3 (suppression administrative de la 2ᵉ)
  font recalculer `count + 1 = 3`, en collision frontale avec `eval_sub_unique` →
  **500**. Bug latent confirmé par test avant correctif.

### R5 — Contrainte de non-régression
- **R5.1** THE correctif SHALL PAS introduire de nouveau statut de soumission
  (piège connu #540/#564 : casse `max_attempts`).
- **R5.2** THE contrat JSON existant des endpoints touchés SHALL rester inchangé pour les
  cas déjà couverts par les tests de caractérisation.

## 3. Hors périmètre (tracé, non corrigé ici)

| Sujet | Renvoi |
|---|---|
| Quota quiz (`in_progress` non compté) + course `attempt_number` | #581 (PR suivante de cette fenêtre) |
| `allow_retake` / toggles inertes | #501 (PR suivante de cette fenêtre) |
| Autres unicités / FK manquantes hors tables de tentatives | #541 (fenêtre 4) |
| Transaction manquante autour de `submit` KC + `ChapterProgress` | #543 |
| Index redondants `(evaluation_id, klassci_etudiant_id)` et `(quiz_id, user_id, attempt_number)` doublant l'unique | signalé §5 du design |
