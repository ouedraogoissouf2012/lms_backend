<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\API\Concerns\RendersClasseDetailsFailure;
use App\Http\Controllers\API\Concerns\RendersKlassciBackedErrors;
use App\Http\Controllers\AuthenticatedController;
use App\Services\Classe\ClasseLocalDetailsService;
use App\Services\Classe\ClasseLocalDoorReader;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * GET /api/lms/classes/local/{classeId} — détails d'une classe désignée par son
 * identifiant **local** (#760).
 *
 * Porte jumelle de {@see LMSClassesController::classeDetails()}, qui parle
 * l'espace KLASSCI dans une école créée depuis KLASSCI. Le pourquoi est dans
 * {@see ClasseLocalDetailsService}. Dans une école créée depuis le LMS, les deux
 * portes servent la même fiche locale (ADR-760-01, amendement EI-02).
 *
 * La source est reçue par la MÉTHODE : Laravel garde le contrôleur sur l'objet
 * Route, et une source reçue par le constructeur resterait celle de la première
 * école servie.
 *
 * Les échecs se rendent comme par la porte historique : une panne de base
 * journalisée en 500, un échec de KLASSCI par `RendersKlassciBackedErrors`. Toute
 * autre erreur va au gestionnaire global.
 */
final class LMSClasseLocalDetailsController extends AuthenticatedController
{
    use RendersClasseDetailsFailure;
    use RendersKlassciBackedErrors;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function show(int $classeId, Request $request, ClasseLocalDoorReader $details): JsonResponse
    {
        try {
            return $this->relayResponse(
                $details->detailsForLocalId($classeId, $this->authenticatedUser($request)),
            );
        } catch (QueryException $e) {
            return $this->echecDetailsClasse($this->logger, $classeId, $e);
        } catch (RuntimeException $e) {
            return $this->renderKlassciFailure($e);
        }
    }
}
