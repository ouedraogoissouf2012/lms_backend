<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMatiereRequest;
use App\Services\Catalogue\LocalMatiereCreator;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Créer une matière sans KLASSCI (#797, #848).
 *
 * Contrôleur distinct de celui des classes plutôt qu'une seconde méthode :
 * chacun reste au ras de son service, et la route des classes livrée par #860
 * n'a pas à être réécrite pour accueillir un voisin.
 *
 * Aucune lecture du mode d'établissement : le service consulte une CAPACITÉ et
 * le contrôleur relaie son refus (contrat OCP, #697 article 1).
 */
final class LMSMatiereWriteController extends Controller
{
    use RespondsWithJson;

    public function __construct(private readonly LocalMatiereCreator $matieres) {}

    public function store(StoreMatiereRequest $request): JsonResponse
    {
        try {
            $matiere = $this->matieres->creer($request->donnees());
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $this->statut($e));
        }

        return $this->successResponse([
            'id' => $matiere->getKey(),
            'libelle' => $matiere->libelle,
            'code' => $matiere->code,
            'coefficient' => $matiere->coefficient,
            'credit' => $matiere->credit,
        ], 'Matière créée.', 201);
    }

    private function statut(Throwable $e): int
    {
        $code = $e->getCode();

        return is_int($code) && $code >= 400 && $code < 600 ? $code : 422;
    }
}
