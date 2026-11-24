<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReglerCapaciteDeClasseRequest;
use App\Services\Catalogue\CapaciteDeClasse;
use Illuminate\Http\JsonResponse;

/**
 * Régler la capacité d'une classe (#1358) : un geste NOMMÉ, sur le modèle du rattachement
 * à une Période (#1208). Aucune lecture du mode ici : le service passe par la porte du
 * catalogue, et le contrôleur relaie son refus.
 */
final class LMSClasseCapaciteController extends Controller
{
    use RespondsWithJson;

    public function __construct(private readonly CapaciteDeClasse $capacites) {}

    public function update(ReglerCapaciteDeClasseRequest $request, int $classe): JsonResponse
    {
        try {
            $reglee = $this->capacites->regler($classe, $request->capacite());
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        $capacite = CapaciteDeClasse::lue($reglee);

        return $this->successResponse(
            ['id' => $reglee->getKey(), 'effectif' => $capacite],
            $capacite === null
                ? 'Capacité retirée : la classe reçoit sans limite.'
                : 'Capacité de la classe enregistrée. Les inscriptions déjà faites sont conservées.',
        );
    }
}
