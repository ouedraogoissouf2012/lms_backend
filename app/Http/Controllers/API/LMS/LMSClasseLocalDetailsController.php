<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Services\Classe\ClasseLocalDetailsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/lms/classes/local/{classeId} — détails d'une classe désignée par son
 * identifiant **local** (#760).
 *
 * Porte jumelle de {@see LMSClassesController::classeDetails()}, qui parle
 * l'espace KLASSCI. Les deux servent le même service ; seul le seuil diffère.
 * Le pourquoi est dans {@see ClasseLocalDetailsService}.
 */
final class LMSClasseLocalDetailsController extends AuthenticatedController
{
    public function __construct(
        private readonly ClasseLocalDetailsService $details,
    ) {}

    public function show(int $classeId, Request $request): JsonResponse
    {
        return $this->relayResponse(
            $this->details->detailsForLocalId($classeId, $this->authenticatedUser($request)),
        );
    }
}
