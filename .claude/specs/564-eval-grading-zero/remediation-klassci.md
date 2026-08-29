# Plan de remédiation des données KLASSCI — #564

> **Ce document est un PLAN. Aucune action n'est exécutée par la PR #564.**
> Le re-push vers KLASSCI (SIS officiel) est une **action externe sensible** qui
> requiert une décision explicite de l'utilisateur (§R5.2 des requirements).

## 1. Problème de données

Avant le correctif, la notation des évaluations renvoyait 0 (bug de format
requête↔service). Ces 0 ont pu être **poussés dans KLASSCI** via
`EvaluationKlassciSyncController::syncToKlassci` (qui envoie `note_sur_20`).
Il faut donc, en plus du correctif de code :
1. **recenser** les soumissions notées 0 **à tort** ;
2. **recalculer** leur score à partir des réponses stockées ;
3. **re-pousser** les notes corrigées dans KLASSCI (décision utilisateur).

## 2. Contraintes & pièges (à respecter impérativement)

- **Ne pas présumer que tout 0 est faux.** Un 0 peut être légitime (l'étudiant a
  réellement tout faux, ou a rendu blanc). Le seul critère fiable est le
  **recalcul** : on ne modifie une note que si `recalcul ≠ note stockée`.
- **Deux formats de `answers` coexistent en base :**
  - soumissions **historiques** : format **LISTE** `[{question_id, answer}]`
    (celui que l'ancienne request/les tests produisaient) — c'est justement ce qui
    causait le 0 ;
  - soumissions **post-correctif** : format **MAP** `{question_id: answer}`.
  Le recalcul doit **normaliser liste→map** avant d'appeler
  `EvaluationGradingService::calculateScore` (qui lit une map).
- **Idempotence** : le recalcul doit pouvoir être relancé sans effet de bord ;
  un dry-run par défaut, écriture seulement en `--apply`.
- **Traçabilité** : produire un rapport avant/après (CSV/log) horodaté et audité.
- **Multi-tenant** : traiter par institution ; ne jamais mélanger les tenants.

## 3. Étapes proposées

### Étape 1 — Recensement (lecture seule, sans risque)
Requête d'inventaire des candidats :
```sql
-- Soumissions comptabilisables (hors entraînement) potentiellement mal notées.
SELECT s.id, s.evaluation_id, s.klassci_etudiant_id, s.note_sur_20,
       s.synced_to_klassci, e.klassci_evaluation_id
FROM evaluation_submissions s
JOIN evaluations e ON e.id = s.evaluation_id
WHERE s.status IN ('soumis', 'corrige')
  AND (s.feedback IS NULL OR s.feedback NOT LIKE '[PRACTICE]%')
  AND s.answers IS NOT NULL
  AND s.answers <> '[]';
```
→ pour chaque ligne, recalculer (Étape 2) et ne garder que celles où la note change.

### Étape 2 — Commande de recalcul (LIVRÉE — locale, idempotente, dry-run par défaut)
Commande artisan dédiée **livrée** (PR de suivi de #564) :
`php artisan evaluations:recompute-scores [--apply] [--evaluation=ID] [--institution=ID]`

> Implémentée par `EvaluationScoreRecomputationService` (logique, réutilise
> `EvaluationGradingService`) + `RecomputeEvaluationScores` (commande fine).
> **Dry-run par défaut** (aucune écriture sans `--apply`). Normalise liste→map,
> recalcule, ne modifie une note que si elle diffère (jamais de mise à 20 aveugle),
> **skippe** les évaluations à correction manuelle (dissertation, #588). Ne pousse
> **rien** vers KLASSCI.

Pseudo-algorithme :
```
pour chaque soumission candidate :
    answers = normaliserEnMap(soumission.answers)   # liste→map si nécessaire
    (scoreAvant, noteAvant) = (soumission.score, soumission.note_sur_20)
    calculateScore(soumission)                        # via EvaluationGradingService
    si (soumission.note_sur_20 != noteAvant) :
        journaliser(id, avant, après)
        si --apply : soumission.save()  sinon rollback (dry-run)
```
`normaliserEnMap` : si `answers` est une liste `[{question_id, answer}]`,
reconstruire `{question_id: answer}` ; si déjà une map, inchangé.

> **Note** : `EvaluationGradingService` et `calculateScore` restent inchangés par
> #564 ; la commande les **réutilise** (DRY, pas de logique de notation dupliquée).

### Étape 3 — Re-push KLASSCI (EXTERNE — décision utilisateur)
Pour les évaluations avec `klassci_evaluation_id` dont des notes ont changé,
re-pousser via le flux de sync existant (`syncToKlassci`) après recalcul appliqué.
**C'est l'action sensible sur le SIS.** À cadrer avec l'utilisateur :
- **qui** déclenche (rôle/personne) ;
- **quand** (fenêtre de maintenance ? période de notes ouverte côté KLASSCI ?) ;
- **quel environnement** (prod cPanel uniquement, après validation) ;
- **quel contrôle** (échantillon vérifié manuellement avant push massif ?).

## 4. Questions ouvertes à l'utilisateur (posées en fin d'implémentation)

- **(a)** La **commande de recalcul** (Étapes 1-2, 100% locale, aucun push) :
  la livrer dans **cette PR #564**, dans une **PR de suivi dédiée**, ou **pas du tout** ?
- **(b)** Le **re-push KLASSCI** (Étape 3) : qui / quand / quel environnement /
  quel niveau de contrôle ? (La fenêtre #564 ne pousse **rien** vers KLASSCI.)

## 5. Statut

- ✅ **Types auto-corrigeables** (qcm, qcm_multiple, vrai_faux, reponse_courte) :
  notation corrigée (format map) → plus de nouveaux 0 de format. Livré dans #564.
- ✅ **Dissertation (correction manuelle)** : **fail-closed** — la synchronisation
  KLASSCI d'une évaluation contenant une dissertation est bloquée (409) tant qu'aucune
  notation manuelle n'existe → plus de note déflatée/0 poussée. Livré dans #564.
- ⏳ **Endpoint de notation manuelle enseignant** pour les dissertations (afin de
  finaliser puis pousser ces notes) : **issue de suivi #588** (débordement du hotfix).
- ⏳ Recensement / recalcul / re-push des 0 **historiques** : **en attente de décision
  utilisateur** (§4).

> Correction d'une affirmation initiale trop optimiste : le correctif de format seul ne
> suffisait pas à « stopper tous les nouveaux 0 » — les dissertations continuaient d'en
> produire. C'est le fail-closed du sync qui ferme ce flanc côté KLASSCI.
