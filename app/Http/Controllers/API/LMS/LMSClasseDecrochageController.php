<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Services\Evaluation\Classe\AccesAuBilanDeClasse;
use App\Services\Suivi\Decrochage\DecrochageDeLaClasse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * #1704 : `GET /api/lms/classes/{classe}/decrochage`, les élèves de la classe à suivre et pourquoi.
 * La classe par l'id de l'ESPACE SOURCE de l'école, comme `/classes/{classe}/resultats`, et le même
 * accès ({@see AccesAuBilanDeClasse}) : le coordinateur et l'admin de l'école, l'enseignant désigné
 * formateur dans la classe. Un élève n'y lit rien, pas même sa ligne : la route porte `permission:classes.eleves` (le personnel).
 */
final class LMSClasseDecrochageController extends AuthenticatedController
{
    public function __construct(
        private readonly DecrochageDeLaClasse $decrochage,
        private readonly AccesAuBilanDeClasse $acces,
    ) {}

    public function show(Request $request, int $classe): JsonResponse
    {
        $lecteur = $this->authenticatedUser($request);

        try {
            $lue = $this->acces->classe($classe, $lecteur);
            if ($this->acces->portee($lue, $lecteur) !== AccesAuBilanDeClasse::COMPLET) {
                throw new BusinessException('Accès refusé.', 403);
            }

            return $this->successResponse($this->decrochage->de($lue));
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
    }
}
