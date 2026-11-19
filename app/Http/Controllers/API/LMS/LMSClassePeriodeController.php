<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\RattacherClasseRequest;
use App\Services\Catalogue\RattachementDeClasse;
use Illuminate\Http\JsonResponse;

/**
 * Rattacher une classe existante à une Période de formation, ou l'en détacher
 * (#1208, point 1). Un geste NOMMÉ, sur le modèle des transitions (#845), et non un
 * `PATCH /classes` général qui ouvrirait d'autres champs sans décision.
 */
final class LMSClassePeriodeController extends Controller
{
    use RespondsWithJson;

    public function __construct(private readonly RattachementDeClasse $rattachement) {}

    public function update(RattacherClasseRequest $request, int $classe): JsonResponse
    {
        try {
            $rattachee = $this->rattachement->rattacher($classe, $request->periode());
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse(
            ['id' => $rattachee->getKey(), 'training_session_id' => $rattachee->training_session_id],
            $rattachee->training_session_id === null
                ? 'Classe détachée de sa période. Les inscriptions déjà faites sont conservées.'
                : 'Classe rattachée à la période.',
        );
    }
}
