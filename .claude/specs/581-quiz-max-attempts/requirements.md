# #581 — Quota `max_attempts` contournable et course sur `attempt_number`

> Sous-issue de #563 · P1 (intégrité de la notation) · Branche `fix/581-max-attempts`
> Empilée sur `fix/540-attempt-quota-race` : réutilise `AttemptConflictGuard`.

## 1. Constat vérifié

### 1.1 Le quota se contourne sans aucune concurrence

`QuizAccessService::attemptsCountForUser()` ne compte que `submitted` et `graded`
(`app/Services/Quiz/QuizAccessService.php:58-64`), et rien dans
`QuizAttemptStartSubmitService::startAttempt()` ne traite la tentative précédente.

**Preuve exécutée** (sonde, `max_attempts = 1`) :

```
[P3] start #1 status=201
[P3] start #2 status=201
[P3] start #3 status=201
[P3] attempts persisted=3 (max_attempts=1)
```

Trois onglets, trois tentatives ouvertes, trois soumissions possibles — et
`bestAttemptForUser()` retient la meilleure des trois notes.

### 1.2 Lire → vérifier → écrire sans atomicité

`nextAttemptNumberForUser()` calcule `max + 1` puis `startAttempt()` insère. Deux
démarrages simultanés calculent le **même** numéro et violent l'unique
`(quiz_id, user_id, attempt_number)` — exception non rattrapée, **500** sur un
double-clic.

### 1.3 Défaut hérité, non mentionné dans l'issue

`quiz_attempts_quiz_id_user_id_attempt_number_unique` **ignore `institution_id`**,
alors que `$quiz->attempts()` porte le global scope `institution`. Comme pour #540,
toute tentative antérieure à février 2026 (`institution_id = NULL`, colonne ajoutée
nullable et sans backfill) est invisible au calcul mais bien vue par l'index →
`max + 1` re-propose un numéro pris → **409 définitif**.

## 2. Décision produit tranchée avec l'utilisateur

L'issue se contredit : son critère 2 demande d'**abandonner** la tentative en cours
au démarrage, son critère 4 veut que la **reprise** reste possible.

Abandonner soustrait la tentative au janitor `quiz:expire-attempts` et repart d'un
`started_at` neuf : sur un quiz chronométré à `max_attempts = 1`, l'étudiant obtient
un temps **illimité** en redémarrant — ce qui ruine l'objectif même de l'issue
(« intégrité de la notation »).

**Retenu : la tentative en cours est REPRISE**, chrono compris. Fermer son onglet ne
redonne pas de temps ; le quota est respecté ; le quiz se comporte comme
l'évaluation (même code, même garde de conflit).

## 3. Exigences (EARS)

- **R1.1** WHEN un étudiant démarre alors qu'une tentative `in_progress` existe,
  THEN le système SHALL la lui **rendre** (200) avec son temps restant réel, et
  SHALL PAS en créer une seconde.
- **R1.2** THE `started_at` d'une tentative reprise SHALL rester inchangé.
- **R2.1** THE comptage de quota SHALL inclure tous les statuts **sauf `abandoned`**.
- **R2.2** THE indicateur `user_can_attempt` SHALL être vrai lorsqu'une tentative est
  reprenable, même si le quota nominal est atteint — sinon l'interface annoncerait
  « plus de tentative » à un étudiant qui peut légitimement continuer la sienne.
- **R3.1** IF l'insertion viole l'unique, THEN le système SHALL rendre la tentative
  gagnante en reprise (200), ou SHALL répondre 409 — jamais 500.
- **R4.1** THE calcul du numéro de tentative et du quota SHALL interroger l'espace de
  clés de l'index unique, **sans** le global scope `institution` (cf. §1.3).
- **R5.1** THE correctif SHALL PAS introduire de nouveau statut de tentative.
- **R5.2** THE contrat JSON des réponses existantes SHALL rester inchangé pour les
  cas déjà couverts par les tests de caractérisation.

## 4. Critères de fermeture de l'issue

| Critère de l'issue | Traitement |
|---|---|
| `max_attempts=1`, start → start → submit → submit : la 2ᵉ soumission refusée | ✅ testé — le 2ᵉ start rend la MÊME tentative, la 2ᵉ soumission tombe en 422 |
| Démarrer abandonne la précédente `in_progress` | ⚠️ **écarté volontairement** (§2), remplacé par la reprise — décision utilisateur |
| Concurrence : une seule tentative créée, l'autre refusée proprement, jamais 500 | ✅ testé par entrelacement déterministe |
| La reprise après fermeture d'onglet reste possible | ✅ testé |
| `php artisan test` 100 %, PHPStan level 9 vert | ✅ |
