# Requirements — #564 Notation évaluations = 0 + zéros poussés dans KLASSCI

> Sous-issue **P0** de l'épique #563 (audit 2026-08-15). Branche : `fix/564-eval-grading-zero`.
> Format EARS (WHEN / IF / WHERE / WHILE + SHALL).

## 1. Contexte & cause racine (prouvée empiriquement)

La notation automatique des évaluations renvoie **toujours 0**, et ces zéros sont
poussés dans **KLASSCI** (SIS officiel). Preuve par test exécuté
(`EvaluationGradingScoreCharacterizationTest`, 2026-08-15) :

| Payload envoyé à `POST /api/evaluations/{id}/submit` | HTTP | score | note_sur_20 |
|---|---|---|---|
| **LISTE** `[{question_id, answer}]` (ce que valide `SubmitEvaluationRequest`) | 201 | **0.00** | **0.00** |
| **MAP** `{question_id: answer}` (ce qu'envoie réellement le frontend) | **500** | — | — |

Deux défauts distincts, même cause (désalignement du **format des réponses**) :

- **Défaut A — score 0 (corruption de données).**
  `SubmitEvaluationRequest` valide/normalise une **LISTE** `[{question_id, answer}]`
  ([`SubmitEvaluationRequest.php:104-125`](../../../app/Http/Requests/SubmitEvaluationRequest.php)),
  donc `$submission->answers` est stocké indexé `0,1,2…`. Or
  `EvaluationGradingService::calculateScore` lit une **MAP**
  `$submission->answers[$question->id]`
  ([`EvaluationGradingService.php:105`](../../../app/Services/Evaluation/EvaluationGradingService.php)).
  L'accès map sur une liste ne retrouve jamais la bonne réponse → 0 point → 0/20.

- **Défaut B — 500 sur le frontend réel (soumission impossible).**
  Le frontend construit et envoie une **MAP** `{ [question.id]: réponse }`
  ([`useTakeEvaluation.js:24,61-67,179-183`](../../../../lms-frontend/src/composables/useTakeEvaluation.js),
  lecture symétrique dans [`evaluationResultAnswers.js:11-17`](../../../../lms-frontend/src/utils/evaluationResultAnswers.js)).
  `SubmitEvaluationRequest::prepareForValidation()` fait `array_merge($answer, …)`
  en supposant que chaque `$answer` est un tableau `{question_id, answer}`
  ([`SubmitEvaluationRequest.php:165-169`](../../../app/Http/Requests/SubmitEvaluationRequest.php)) ;
  sur une map, `$answer` est un scalaire → `array_merge(string, …)` → **TypeError → 500**.

- **Référence de cohérence : le QUIZ.** `SubmitQuizAttemptRequest` valide une **MAP**
  `answers.*` ([`SubmitQuizAttemptRequest.php:33-53`](../../../app/Http/Requests/SubmitQuizAttemptRequest.php))
  et `QuizGradingService::gradeAttempt` lit `$attempt->answers[$question->id]`
  ([`QuizGradingService.php:160`](../../../app/Services/Quiz/QuizGradingService.php)). Cohérent.
  → **L'évaluation doit s'aligner sur ce modèle : MAP des deux côtés.**

**Conclusion de conception** : la MAP est le contrat réel du frontend, du service de
correction et du quiz. Le seul maillon en LISTE est `SubmitEvaluationRequest`. On
aligne la request sur une **MAP** ; le service et le frontend sont déjà corrects et
restent inchangés. Les tests écrits au format liste encodaient le bug et seront
réécrits au format map.

## 2. Périmètre

**Dans le périmètre** (fenêtre #564) :
- `app/Http/Requests/SubmitEvaluationRequest.php` (règles + messages + normalisation).
- Tests associés à la request et au flux de soumission/notation.
- Documentation d'un **plan de remédiation** des scores déjà poussés à KLASSCI.

**Hors périmètre (ne pas toucher)** :
- Logique de quota de tentatives / `attempt_number` (#540, autre fenêtre).
- `EvaluationGradingService` (lit déjà une map correctement — inchangé sauf preuve du contraire).
- Tout **push effectif** vers KLASSCI (action externe sur le SIS → décision utilisateur).

## 3. Exigences fonctionnelles (EARS)

### R1 — Accepter le contrat MAP réel du frontend
- **R1.1** WHEN un étudiant authentifié soumet `POST /api/evaluations/{id}/submit` avec
  `answers` sous forme de **MAP** `{ "<question_id>": <réponse> }`, le système SHALL
  accepter la requête (pas de 500, pas de 422 pour cause de forme).
- **R1.2** WHERE une réponse est de type QCM simple / vrai-faux / réponse courte /
  dissertation, la valeur `answers[question_id]` SHALL être un **scalaire chaîne**.
- **R1.3** WHERE une réponse est de type QCM multiple, la valeur `answers[question_id]`
  SHALL être un **tableau de chaînes**.
- **R1.4** IF le payload est envoyé au format **LISTE** obsolète `[{question_id, answer}]`,
  le système SHALL le rejeter proprement en **422** (message explicite), jamais un 500
  ni un enregistrement silencieux noté 0.

### R2 — Calculer le score réel (fin de la corruption)
- **R2.1** WHEN une soumission valide au format map contient des réponses correctes, le
  système SHALL calculer `score` = somme des points des bonnes réponses et
  `note_sur_20` = (points obtenus / points totaux) × `bareme`, arrondi à 2 décimales.
- **R2.2** WHEN toutes les réponses d'une soumission sont correctes, `note_sur_20` SHALL
  être égal au `bareme` de l'évaluation (ex. 20/20), et NON 0.
- **R2.3** WHERE une réponse est incorrecte ou absente, le système SHALL ne créditer
  aucun point pour cette question (0 légitime, distinct du bug).

### R3 — Validation & robustesse d'entrée (§1.5)
- **R3.1** IF `answers` est absent ou vide, le système SHALL répondre 422.
- **R3.2** IF une valeur de réponse dépasse la limite anti-DOS (10 000 caractères pour un
  scalaire ; idem par élément pour un tableau), le système SHALL répondre 422.
- **R3.3** IF une valeur de réponse a un type interdit (booléen, objet imbriqué), le
  système SHALL répondre 422 (fermeture du type-juggling, cohérent avec #498 côté quiz).

### R4 — Préservation des contrats existants (non-régression)
- **R4.1** Le système SHALL conserver l'enveloppe de réponse de succès existante
  (`{success, message, data:{submission, score, note_sur_20}}`, HTTP 201).
- **R4.2** Le système SHALL conserver les règles d'autorisation existantes (étudiant
  uniquement, évaluation publiée, échéance non dépassée, non déjà soumis) — inchangées.
- **R4.3** Le système SHALL conserver le champ optionnel `submitted_at` (format ISO 8601)
  et son comportement par défaut (now()).

### R5 — Remédiation des données déjà poussées à KLASSCI (documentaire, action = utilisateur)
- **R5.1** Le système (via cette PR) SHALL **documenter** un plan de remédiation :
  recensement des soumissions notées 0 à tort + recalcul à partir des `answers` stockées +
  re-push vers KLASSCI.
- **R5.2** La correction de code NE SHALL PAS déclencher elle-même un push vers KLASSCI ;
  le re-push est une action externe sur le SIS **soumise à décision de l'utilisateur**.
- **R5.3** Le plan de remédiation SHALL prendre en compte que les soumissions historiques
  ont `answers` au format **LISTE** (à convertir en map avant recalcul) et que certains 0
  sont **légitimes** (réponses réellement fausses) — donc **recalcul**, jamais mise à 20 aveugle.

## 4. Exigences non-fonctionnelles

- **N1 — Qualité (PRODUCTION_STANDARDS)** : fichier ≤300 lignes, méthodes ≤40 lignes,
  DI stricte, 0 erreur PHPStan (`--memory-limit=2G`), suite de tests impactée 100% verte.
- **N2 — TDD** : test **RED** prouvant `score=0` sur réponse correcte AVANT correctif,
  puis **GREEN** prouvant le score réel (assertion de **valeur**, pas seulement de forme).
- **N3 — Multi-tenant** : au moins un test couvrant l'isolation par institution sur le flux.
- **N4 — Pas de N+1 introduit** ; pas de `getMessage()` exposé au client.

## 5. Critères d'acceptation (Definition of Done)

1. `answers` au format map est accepté ; score réel calculé et **testé par valeur**.
2. Le format liste obsolète est rejeté en 422 (test dédié) — plus jamais de 0 silencieux.
3. Le 500 `array_merge` sur payload map a disparu (test dédié).
4. Tous les tests d'évaluation impactés réécrits au format map et verts ; suite verte.
5. PHPStan 0 erreur.
6. Plan de remédiation KLASSCI documenté ; **question posée à l'utilisateur** sur le mode
   opératoire du re-push (aucun push effectué par cette fenêtre).
7. PR ouverte vers `lms` ; n° reporté à l'orchestrateur. L'utilisateur merge.

## 6. Alternatives écartées (Q12 des 15 questions)

- **A. Changer le service pour lire une LISTE.** Rejetée : diverge du quiz (référence),
  casse le frontend qui envoie une map, et le service map est déjà correct. On corrige
  le maillon fautif (la request), pas les deux maillons sains.
- **B. Normaliser liste→map dans le contrôleur.** Rejetée : logique de transformation
  dans un contrôleur (interdit §5), et surtout inutile — le frontend n'envoie jamais de
  liste ; la seule source de liste était la request/les tests buggés.
- **C. Accepter les DEUX formats (liste ET map) pour compat.** Rejetée : aucun client réel
  n'envoie de liste (le frontend envoie une map ; la liste ne venait que des tests). Un
  contrat ambigu à double forme est une dette permanente pour zéro bénéfice.
