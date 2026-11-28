<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Presenters\ClasseEnVue;
use App\Http\Requests\StoreClasseRequest;
use App\Http\Requests\UpdateClasseRequest;
use App\Services\Catalogue\LocalClasseCreator;
use App\Services\Catalogue\LocalClasseEditor;
use Illuminate\Http\JsonResponse;

/**
 * Créer une classe sans KLASSCI (#848), puis la modifier (#1652).
 *
 * Avant cette route, le seul créateur d'une `Classe` du dépôt était la
 * synchronisation KLASSCI : une école autonome n'avait aucune classe, jamais, et
 * l'import CSV produisait des apprenants qui n'appartenaient à rien.
 *
 * Aucune lecture du mode d'établissement ici : le service consulte une CAPACITÉ
 * et le contrôleur relaie son refus (contrat OCP, #697 article 1).
 */
final class LMSClasseWriteController extends AuthenticatedController
{
    use RespondsWithJson;

    public function __construct(
        private readonly LocalClasseCreator $classes,
        private readonly LocalClasseEditor $edition,
    ) {}

    public function store(StoreClasseRequest $request): JsonResponse
    {
        try {
            $classe = $this->classes->creer($request->donnees());
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse(ClasseEnVue::de($classe), 'Classe créée.', 201);
    }

    public function update(UpdateClasseRequest $request, int $classe): JsonResponse
    {
        try {
            $modifiee = $this->edition->modifier($this->authenticatedUser($request), $classe, $request->modifications());
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse(ClasseEnVue::de($modifiee), 'Classe modifiée.');
    }
}
