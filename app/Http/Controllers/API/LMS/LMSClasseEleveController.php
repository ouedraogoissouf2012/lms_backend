<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Requests\InscrireUnEleveRequest;
use App\Services\Enrollment\InscriptionParLAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * L'administration d'une école autonome inscrit un élève existant dans une classe, et l'en retire (#1619). Les règles
 * vivent dans {@see InscriptionParLAdministration}.
 */
final class LMSClasseEleveController extends AuthenticatedController
{
    use RespondsWithJson;

    public function __construct(private readonly InscriptionParLAdministration $inscriptions) {}

    public function store(InscrireUnEleveRequest $request, int $classe): JsonResponse
    {
        try {
            $inscription = $this->inscriptions->inscrire($this->authenticatedUser($request), $classe, $request->eleve());
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse($inscription, 'L\'élève est inscrit dans la classe.', 201);
    }

    public function destroy(Request $request, int $classe, int $eleve): JsonResponse
    {
        try {
            $retrait = $this->inscriptions->retirer($this->authenticatedUser($request), $classe, $eleve);
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse($retrait, 'L\'élève est retiré de la classe. Son dossier demeure.');
    }
}
