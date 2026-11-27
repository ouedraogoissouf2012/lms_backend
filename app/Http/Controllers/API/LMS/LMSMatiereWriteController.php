<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Presenters\MatiereEnVue;
use App\Http\Requests\StoreMatiereRequest;
use App\Http\Requests\UpdateMatiereRequest;
use App\Services\Catalogue\LocalMatiereCreator;
use App\Services\Catalogue\LocalMatiereEditor;
use Illuminate\Http\JsonResponse;

/**
 * Créer une matière sans KLASSCI (#797, #848), puis la modifier (#1653).
 *
 * Contrôleur distinct de celui des classes plutôt qu'une seconde méthode :
 * chacun reste au ras de son service, et la route des classes livrée par #860
 * n'a pas à être réécrite pour accueillir un voisin.
 *
 * Aucune lecture du mode d'établissement : le service consulte une CAPACITÉ et
 * le contrôleur relaie son refus (contrat OCP, #697 article 1).
 */
final class LMSMatiereWriteController extends AuthenticatedController
{
    use RespondsWithJson;

    public function __construct(
        private readonly LocalMatiereCreator $matieres,
        private readonly LocalMatiereEditor $edition,
    ) {}

    public function store(StoreMatiereRequest $request): JsonResponse
    {
        try {
            $matiere = $this->matieres->creer($request->donnees());
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse(MatiereEnVue::de($matiere), 'Matière créée.', 201);
    }

    public function update(UpdateMatiereRequest $request, int $matiere): JsonResponse
    {
        try {
            $modifiee = $this->edition->modifier($this->authenticatedUser($request), $matiere, $request->modifications());
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse(MatiereEnVue::de($modifiee), 'Matière modifiée.');
    }
}
