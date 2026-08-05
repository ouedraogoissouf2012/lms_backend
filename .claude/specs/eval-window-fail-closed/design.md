# Design — Fenêtre d'évaluation fail-closed sur échec KLASSCI (#499)

## 1. Distinguer « échec » de « pas de fenêtre » (REQ-1)

Aujourd'hui `fetchWindowSafe(): ?array` écrase les 2 cas sur `null`. On le
remplace par une méthode qui retourne un **résultat explicite** :

```php
/**
 * Récupère la fenêtre temporelle KLASSCI d'une évaluation.
 *
 * @return array{fetched: bool, window: ?array<string, mixed>}
 *   - fetched=false : l'appel KLASSCI a échoué (panne/timeout) → la fenêtre
 *     n'a PAS pu être vérifiée (le caller doit fail-closed hors entraînement).
 *   - fetched=true, window=null : appel réussi, aucune fenêtre configurée
 *     (évaluation toujours ouverte) → démarrage légitime.
 *   - fetched=true, window=[...] : fenêtre récupérée, à évaluer par checkWindow.
 */
private function fetchWindow(string $klassciToken, ?int $klassciEvaluationId): array
{
    try {
        $response = $this->klassciService->requestWithUserToken($klassciToken, 'evaluations', 'GET');
        $klassciEval = collect($response['data'] ?? [])->firstWhere('id', $klassciEvaluationId);

        return ['fetched' => true, 'window' => $klassciEval['programmation']['window'] ?? null];
    } catch (\Exception $e) {
        $this->logger->warning('Window check failed (KLASSCI)', ['error' => $e->getMessage()]);

        return ['fetched' => false, 'window' => null];
    }
}
```

## 2. Fail-closed via un helper `resolveWindowGate` (REQ-2/6)

⚠️ `startAttempt` fait déjà ~70 lignes (`:43-113`) — **au-dessus de la limite
§1.1 ≤40**. Plutôt que d'aggraver, on **extrait** tout le bloc fenêtre (fetch +
fail-closed + checkWindow) dans un helper, ce qui **réduit** `startAttempt` ET
loge proprement la nouvelle logique.

```php
/**
 * Résout la fenêtre temporelle et renvoie une erreur de gate si le démarrage
 * doit être bloqué, sinon [null, $window] pour poursuivre.
 *
 * @return array{0: ?array{status: string, window?: ?array<string, mixed>, message?: string}, 1: ?array<string, mixed>}
 *   [erreur|null, window|null]
 */
private function resolveWindowGate(string $klassciToken, Evaluation $evaluation, bool $isPracticeMode, int $evaluationId, ?int $klassciEtudiantId): array
{
    $windowResult = $this->fetchWindow($klassciToken, $evaluation->klassci_evaluation_id);

    // Fail-closed : fenêtre non vérifiable (KLASSCI indisponible) hors entraînement (#499).
    if (! $windowResult['fetched'] && ! $isPracticeMode) {
        $this->logger->warning('Démarrage refusé : fenêtre non vérifiable (KLASSCI indisponible)', [
            'evaluation_id' => $evaluationId,
            'student_id' => $klassciEtudiantId,
        ]);

        return [[
            'status' => 'window_check_failed',
            'message' => "Impossible de vérifier la disponibilité de l'évaluation pour le moment. Veuillez réessayer dans un instant.",
        ], null];
    }

    $window = $windowResult['window'];
    $windowError = $this->checkWindow($window, $isPracticeMode, $evaluationId, $klassciEtudiantId);

    return [$windowError, $window];
}
```

Dans `startAttempt`, le bloc `:60-66` (fetch + checkWindow) devient :

```php
$isPracticeMode = $evaluation->isTerminee();

[$windowError, $window] = $this->resolveWindowGate(
    $klassciToken, $evaluation, $isPracticeMode, $evaluationId, $klassciEtudiantId
);
if ($windowError !== null) {
    return $windowError;
}
```

- Mode entraînement : `!$isPracticeMode` faux → pas de fail-closed (REQ-6) ; puis
  `checkWindow` court-circuite sur `$isPracticeMode`.
- `checkWindow` reste **inchangé** (le cas « échec » ne l'atteint plus).
- **Bénéfice** : `startAttempt` perd des lignes (bloc extrait) et repasse plus
  près de la limite §1.1.

## 3. `getTimeStatus` (REQ-7)

Remplacer `$window = $this->fetchWindowSafe(...)` par
`$window = $this->fetchWindow(...)['window']`. Comportement identique (informatif,
`null` toléré sur échec — l'endpoint n'est pas un gate).

## 4. Mapping HTTP (REQ-3)

`EvaluationStudentAttemptController::startEvaluation` — ajouter un bras au `match` :

```php
'window_check_failed' => $this->errorResponse($result['message'], 503),
```

**503 Service Unavailable** : l'échec vient de l'indisponibilité de KLASSCI
(dépendance), transitoire → le client doit réessayer. Sémantiquement plus juste
qu'un 403 (« interdit », qui suggère un refus permanent). Placé avant le
`default => 500`.

## 5. Décisions & justifications

| Décision | Pourquoi |
|---|---|
| Résultat `{fetched, window}` | Sépare explicitement les 2 causes du `null` (REQ-1, Q15). |
| Fail-closed uniquement hors entraînement | L'entraînement n'a pas de fenêtre (REQ-6) ; ne pas sur-corriger. |
| Nouveau statut `window_check_failed` | N'écrase pas `window_closed` (403) qui garde son sens « fenêtre fermée » (REQ-5). |
| HTTP 503 (pas 403) | Indisponibilité transitoire, pas un refus d'accès (Q15). |
| `checkWindow` inchangé | Le cas échec est retiré en amont ; sa sémantique `null=absence` reste valide. |
| `getTimeStatus` null-tolérant | Endpoint informatif, pas un gate (REQ-7). |

## 6. Fichiers touchés

| Fichier | Nature |
|---|---|
| `app/Services/Evaluation/Student/EvaluationAttemptStateService.php` | `fetchWindowSafe` → `fetchWindow` (résultat explicite), fail-closed dans `startAttempt`, `getTimeStatus` adapté |
| `app/Http/Controllers/API/Evaluation/Student/EvaluationStudentAttemptController.php` | + bras `window_check_failed` → 503 |
| `tests/Feature/Evaluation/Student/EvaluationStudentAttemptResponseTest.php` (ou nouveau) | panne KLASSCI → 503 + aucune submission ; entraînement → 200 ; non-régression window_closed / no-window / open |

`startAttempt` : ajout de ~8 lignes → vérifier qu'elle reste ≤40 (sinon extraire).
