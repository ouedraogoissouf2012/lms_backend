<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Seances\KlassciPayload;
use App\Support\Logging\CaughtExceptionLog;

/**
 * École créée depuis KLASSCI : la fenêtre telle que KLASSCI la programme.
 *
 * Elle se lit dans la liste KLASSCI DE L'ÉLÈVE, par son jeton. Elle ne dit
 * donc rien d'une évaluation qui n'y figure pas (`window = null`) : ce n'est
 * PAS un contrôle d'accès. L'accès est décidé avant, par
 * {@see LearnerEvaluationScope}.
 *
 * Extrait de `EvaluationWindowGate` (D72, E2b), sans changement de
 * comportement.
 */
final class KlassciEvaluationWindowSource implements EvaluationWindowSource
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly CaughtExceptionLog $journal,
    ) {}

    public function refusAvantLecture(User $eleve): ?array
    {
        return $this->jeton($eleve) === null ? ['status' => 'no_token'] : null;
    }

    public function fenetre(User $eleve, Evaluation $evaluation): array
    {
        $jeton = $this->jeton($eleve);
        if ($jeton === null) {
            return ['fetched' => false, 'window' => null];
        }

        try {
            $response = $this->klassciService->requestWithUserToken($jeton, 'evaluations', 'GET');
            $rawWindow = $this->rawWindowOf($response['data'] ?? null, $evaluation->klassci_evaluation_id);

            return [
                'fetched' => true,
                'window' => is_array($rawWindow) ? KlassciPayload::asArray($rawWindow) : null,
            ];
        } catch (\Exception $e) {
            $this->journal->warning('Window check failed (KLASSCI)', $e);

            return ['fetched' => false, 'window' => null];
        }
    }

    private function jeton(User $eleve): ?string
    {
        $jeton = $eleve->klassci_token;

        return is_string($jeton) && $jeton !== '' ? $jeton : null;
    }

    /**
     * La fenêtre brute de cette évaluation dans la liste KLASSCI de l'élève, ou
     * null. Chaque niveau du payload est vérifié avant d'être lu : une forme
     * inattendue donne « pas de fenêtre », comme le faisait l'accès d'origine.
     */
    private function rawWindowOf(mixed $evaluations, ?int $klassciEvaluationId): mixed
    {
        if (! is_array($evaluations)) {
            return null;
        }

        $klassciEval = collect($evaluations)->firstWhere('id', $klassciEvaluationId);
        $programmation = is_array($klassciEval) ? ($klassciEval['programmation'] ?? null) : null;

        return is_array($programmation) ? ($programmation['window'] ?? null) : null;
    }
}
