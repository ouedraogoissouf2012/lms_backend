<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClasseRequest;
use App\Services\Catalogue\LocalClasseCreator;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Créer une classe sans KLASSCI (#848).
 *
 * Avant cette route, le seul créateur d'une `Classe` du dépôt était la
 * synchronisation KLASSCI : une école autonome n'avait aucune classe, jamais, et
 * l'import CSV produisait des apprenants qui n'appartenaient à rien.
 *
 * Aucune lecture du mode d'établissement ici : le service consulte une CAPACITÉ
 * et le contrôleur relaie son refus (contrat OCP, #697 article 1).
 */
final class LMSClasseWriteController extends Controller
{
    use RespondsWithJson;

    public function __construct(private readonly LocalClasseCreator $classes) {}

    public function store(StoreClasseRequest $request): JsonResponse
    {
        try {
            $classe = $this->classes->creer($request->donnees());
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $this->statut($e));
        }

        return $this->successResponse([
            'id' => $classe->getKey(),
            'libelle' => $classe->libelle,
            'code' => $classe->code,
            'effectif' => $classe->effectif,
        ], 'Classe créée.', 201);
    }

    private function statut(Throwable $e): int
    {
        $code = $e->getCode();

        return is_int($code) && $code >= 400 && $code < 600 ? $code : 422;
    }
}
