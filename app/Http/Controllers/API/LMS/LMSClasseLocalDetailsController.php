<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\API\Concerns\RendersKlassciBackedErrors;
use App\Http\Controllers\AuthenticatedController;
use App\Services\Classe\ClasseLocalDetailsService;
use App\Services\Classe\ClasseLocalDoorReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
 * Un échec de KLASSCI se rend comme par la porte historique, par
 * `RendersKlassciBackedErrors` ; toute autre `RuntimeException`, panne de base
 * comprise, y est rapportée (#948) et rendue en 500 générique. Les autres
 * erreurs vont au gestionnaire global, qui les rapporte.
 */
final class LMSClasseLocalDetailsController extends AuthenticatedController
{
    use RendersKlassciBackedErrors;

    public function show(int $classeId, Request $request, ClasseLocalDoorReader $details): JsonResponse
    {
        try {
            return $this->relayResponse(
                $details->detailsForLocalId($classeId, $this->authenticatedUser($request)),
            );
        } catch (RuntimeException $e) {
            return $this->renderKlassciFailure($e);
        }
    }
}
