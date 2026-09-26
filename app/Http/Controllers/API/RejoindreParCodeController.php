<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\RejoindreParCodeRequest;
use App\Models\User;
use App\Services\Enrollment\Adhesion;
use App\Services\Enrollment\RejoindreParCodeService;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Rejoindre une classe avec son code, en étant déjà connecté (#885, ADR-803-03).
 *
 * 201 quand l'adhésion naît, 200 quand l'apprenant était déjà inscrit : un
 * double clic n'est pas une faute, et une erreur lui ferait croire qu'il n'est
 * pas dans sa classe.
 */
final class RejoindreParCodeController extends Controller
{
    use RespondsWithJson;

    public function __construct(private readonly RejoindreParCodeService $adhesions) {}

    public function store(RejoindreParCodeRequest $request): JsonResponse
    {
        $apprenant = $request->user();

        if (! $apprenant instanceof User) {
            return $this->errorResponse('Non authentifié', 401);
        }

        try {
            $rejointe = $this->adhesions->rejoindre($apprenant, $request->code());
        } catch (BusinessException $e) {
            // Le motif départage les 409 et les 429 de cette porte (#906, ADR-906-01).
            return $this->errorResponse($e->getMessage(), $this->statut($e), reason: $e->reason);
        }

        $nouvelle = $rejointe->adhesion === Adhesion::Nouvelle;

        return $this->successResponse(
            ['classe' => ['id' => $rejointe->classe->getKey(), 'libelle' => $rejointe->classe->libelle]],
            $nouvelle ? 'Vous avez rejoint la classe.' : 'Vous êtes déjà inscrit dans cette classe.',
            $nouvelle ? 201 : 200
        );
    }

    private function statut(Throwable $e): int
    {
        $code = $e->getCode();

        return is_int($code) && $code >= 400 && $code < 600 ? $code : 422;
    }
}
