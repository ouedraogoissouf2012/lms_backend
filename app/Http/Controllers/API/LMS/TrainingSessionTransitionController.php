<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Session\TrainingSessionTransitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Faire avancer une Periode dans son cycle de vie (#845, ADR-845-01).
 *
 * Quatre operations NOMMEES, et non un `PATCH status` : un statut libre
 * laisserait passer `brouillon -> archivee` ou le retour d une periode annulee.
 *
 * Aucun corps de requete : l operation EST dans l URL. Un `{"status": "..."}`
 * redonnerait a l appelant le choix de la cible, ce que ces routes existent
 * precisement pour lui retirer.
 */
final class TrainingSessionTransitionController extends AuthenticatedController
{
    public function __construct(private readonly TrainingSessionTransitionService $transitions) {}

    public function publier(Request $request, int $trainingSession): JsonResponse
    {
        return $this->transitionner($request, fn ($acteur) => $this->transitions->publier($acteur, $trainingSession), 'Periode publiee.');
    }

    public function annuler(Request $request, int $trainingSession): JsonResponse
    {
        return $this->transitionner($request, fn ($acteur) => $this->transitions->annuler($acteur, $trainingSession), 'Periode annulee. Les inscriptions deja faites sont conservees.');
    }

    public function cloturer(Request $request, int $trainingSession): JsonResponse
    {
        return $this->transitionner($request, fn ($acteur) => $this->transitions->cloturer($acteur, $trainingSession), 'Periode cloturee.');
    }

    public function archiver(Request $request, int $trainingSession): JsonResponse
    {
        return $this->transitionner($request, fn ($acteur) => $this->transitions->archiver($acteur, $trainingSession), 'Periode archivee.');
    }

    /**
     * @param  callable(User): TrainingSession  $operation
     */
    private function transitionner(Request $request, callable $operation, string $message): JsonResponse
    {
        try {
            $periode = $operation($this->authenticatedUser($request));
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $this->statut($e));
        }

        return $this->successResponse([
            'id' => $periode->getKey(),
            'libelle' => $periode->libelle,
            'status' => $periode->status->value,
            'phase' => $periode->phase->value,
        ], $message);
    }

    private function statut(Throwable $e): int
    {
        $code = $e->getCode();

        return is_int($code) && $code >= 400 && $code < 600 ? $code : 422;
    }
}
