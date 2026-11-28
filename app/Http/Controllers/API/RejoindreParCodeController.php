<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Requests\RejoindreParCodeRequest;
use App\Services\Enrollment\Adhesion;
use App\Services\Enrollment\RejoindreParCodeService;
use Illuminate\Http\JsonResponse;

/**
 * Rejoindre une classe avec son code, en étant déjà connecté (#885, ADR-803-03).
 *
 * 201 quand l'adhésion naît, 200 quand l'apprenant était déjà inscrit : un
 * double clic n'est pas une faute, et une erreur lui ferait croire qu'il n'est
 * pas dans sa classe.
 */
final class RejoindreParCodeController extends AuthenticatedController
{
    use RespondsWithJson;

    public function __construct(private readonly RejoindreParCodeService $adhesions) {}

    public function store(RejoindreParCodeRequest $request): JsonResponse
    {
        try {
            $rejointe = $this->adhesions->rejoindre($this->authenticatedUser($request), $request->code());
        } catch (BusinessException $e) {
            // Le motif départage les 409 et les 429 de cette porte (#906, ADR-906-01).
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        $enAttente = $rejointe->adhesion === Adhesion::EnAttente;
        $nouvelle = $enAttente || $rejointe->adhesion === Adhesion::Nouvelle;
        $message = match (true) {
            $enAttente => 'Votre demande est enregistrée : elle attend la validation de votre établissement.',
            $nouvelle => 'Vous avez rejoint la classe.',
            default => 'Vous êtes déjà inscrit dans cette classe.',
        };

        return $this->successResponse(
            [
                'classe' => ['id' => $rejointe->classe->getKey(), 'libelle' => $rejointe->classe->libelle],
                // #1658 : l'élève sait si son accès est ouvert ou attend l'école.
                'validation' => $enAttente ? 'en_attente' : 'validee',
            ],
            $message,
            $nouvelle ? 201 : 200
        );
    }
}
