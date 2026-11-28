<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Evaluation;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\ReleaseEvaluationRequest;
use App\Models\Evaluation;
use App\Services\Evaluation\Student\OuvertureDuCorrige;
use App\Services\Evaluation\Student\ResultatsVisibles;
use Illuminate\Http\JsonResponse;

/**
 * `PATCH /api/evaluations/{id}/release` (#1699) : l'enseignant publie les notes d'une évaluation
 * et choisit quand son corrigé s'ouvre. C'est un geste distinct de la saisie des notes, permis
 * après les remises.
 *
 * La visibilité se lit ensuite par deux règles, une par objet : {@see ResultatsVisibles} pour la
 * note et le commentaire, {@see OuvertureDuCorrige} pour le corrigé. L'écriture est journalisée
 * par `Auditable` (qui, quand, quelle valeur).
 */
final class EvaluationReleaseController extends AuthenticatedController
{
    public function update(ReleaseEvaluationRequest $request, int $id): JsonResponse
    {
        // Autorisée par la requête : l'évaluation existe dans l'école de l'appelant.
        $evaluation = Evaluation::query()->findOrFail($id);
        $evaluation->update($request->reglage());

        return $this->successResponse($evaluation->fresh(), 'Diffusion de l\'évaluation mise à jour');
    }
}
