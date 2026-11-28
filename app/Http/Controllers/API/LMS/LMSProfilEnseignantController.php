<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\ExceptionDeDisponibiliteRequest;
use App\Http\Requests\ProfilEnseignantRequest;
use App\Http\Requests\SemaineDeDisponibilitesRequest;
use App\Models\User;
use App\Services\Enseignant\Profil\DisponibilitesEnseignant;
use App\Services\Enseignant\Profil\EnseignantDeLEcole;
use App\Services\Enseignant\Profil\ProfilEnseignantSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * #1666 — le profil et les disponibilités d'un enseignant, désigné par son id LOCAL dans les deux volets. La gestion
 * écrit (middleware `gestion`) ; l'enseignant lit les siens ({@see EnseignantDeLEcole}).
 *
 * Le profil dépend du mode de l'école : sa source est reçue par la MÉTHODE (ADR-EI-01), jamais par le constructeur
 * mémoïsé. Les disponibilités sont locales dans les deux volets : leur service ne dépend d'aucune source.
 */
final class LMSProfilEnseignantController extends AuthenticatedController
{
    public function __construct(
        private readonly EnseignantDeLEcole $enseignants,
        private readonly DisponibilitesEnseignant $disponibilites,
    ) {}

    public function profil(Request $request, int $enseignantId, ProfilEnseignantSource $profils): JsonResponse
    {
        return $this->repondre(fn () => $this->successResponse($profils->lire($this->designer($request, $enseignantId))));
    }

    public function ecrireProfil(ProfilEnseignantRequest $request, int $enseignantId, ProfilEnseignantSource $profils): JsonResponse
    {
        return $this->repondre(fn () => $this->successResponse($profils->ecrire($this->designer($request, $enseignantId), $request->champs())));
    }

    public function disponibilites(Request $request, int $enseignantId): JsonResponse
    {
        return $this->repondre(fn () => $this->successResponse($this->disponibilites->lire($this->designer($request, $enseignantId))));
    }

    public function semaine(SemaineDeDisponibilitesRequest $request, int $enseignantId): JsonResponse
    {
        return $this->repondre(function () use ($request, $enseignantId): JsonResponse {
            $enseignant = $this->designer($request, $enseignantId);
            $this->disponibilites->remplacerSemaine($enseignant, $request->semaine());

            return $this->successResponse($this->disponibilites->lire($enseignant));
        });
    }

    public function ajouterException(ExceptionDeDisponibiliteRequest $request, int $enseignantId): JsonResponse
    {
        return $this->repondre(fn () => $this->successResponse(
            $this->disponibilites->ajouterException($this->designer($request, $enseignantId), $request->champs()),
            'Exception ajoutée',
            201,
        ));
    }

    public function retirerException(Request $request, int $enseignantId, int $exceptionId): JsonResponse
    {
        return $this->repondre(function () use ($request, $enseignantId, $exceptionId): JsonResponse {
            $this->disponibilites->retirerException($this->designer($request, $enseignantId), $exceptionId);

            return $this->successResponse(null, 'Exception retirée');
        });
    }

    private function designer(Request $request, int $enseignantId): User
    {
        return $this->enseignants->designer($enseignantId, $this->authenticatedUser($request));
    }

    /** @param  callable(): JsonResponse  $action */
    private function repondre(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
    }
}
