<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Eleve;

use App\Exceptions\BusinessException;
use App\Exceptions\DoublonDEleve;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\EcrireDossierRequest;
use App\Services\Eleve\DossierDeLEleve;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le dossier d'un élève (#1657) : `GET` (lecture selon le lecteur) et `PUT` (gestion d'une école autonome). Les règles
 * vivent dans {@see DossierDeLEleve} ; ce contrôleur traduit leurs refus.
 */
final class DossierEleveController extends AuthenticatedController
{
    public function __construct(private readonly DossierDeLEleve $dossiers) {}

    public function show(Request $request, int $eleve): JsonResponse
    {
        try {
            return $this->successResponse($this->dossiers->lire($this->authenticatedUser($request), $eleve));
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
    }

    public function update(EcrireDossierRequest $request, int $eleve): JsonResponse
    {
        $acteur = $this->authenticatedUser($request);

        try {
            ['cree' => $cree, 'dossier' => $dossier] = $this->dossiers->ecrire($acteur, $eleve, $request->identite(), $request->boolean('confirmer_doublon'));
        } catch (DoublonDEleve $e) {
            return response()->json([
                'success' => false, 'message' => DoublonDEleve::MESSAGE, 'reason' => DoublonDEleve::REASON, 'candidats' => $e->candidats,
            ], 409);
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse(['eleve_id' => $eleve, 'source' => 'lms', 'dossier' => $dossier], $cree ? 'Dossier créé' : 'Dossier mis à jour', $cree ? 201 : 200);
    }
}
