<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\InscrireUnEleveRequest;
use App\Services\Enrollment\InscriptionParLAdministration;
use Illuminate\Http\JsonResponse;

/**
 * L'administration d'une école autonome inscrit un élève existant dans une classe, et l'en retire (#1619). Les règles
 * vivent dans {@see InscriptionParLAdministration}.
 */
final class LMSClasseEleveController extends Controller
{
    use RespondsWithJson;

    public function __construct(private readonly InscriptionParLAdministration $inscriptions) {}

    public function store(InscrireUnEleveRequest $request, int $classe): JsonResponse
    {
        try {
            $inscription = $this->inscriptions->inscrire($classe, $request->eleve());
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse($inscription, 'L\'élève est inscrit dans la classe.', 201);
    }

    public function destroy(int $classe, int $eleve): JsonResponse
    {
        try {
            $retrait = $this->inscriptions->retirer($classe, $eleve);
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse($retrait, 'L\'élève est retiré de la classe. Son dossier demeure.');
    }
}
