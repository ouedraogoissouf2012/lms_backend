<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\API\Concerns\RendersKlassciBackedErrors;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\VoirLeBilanDeClasseRequest;
use App\Services\Evaluation\Classe\AccesAuBilanDeClasse;
use App\Services\Evaluation\Classe\BilanDeClasse;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * #1676 : `GET /api/lms/classes/{classe}/resultats` — moyennes, rangs et réussite d'une classe,
 * par son id LOCAL (`classes.id`), sur une période `?du=&au=`.
 *
 * Un refus métier (classe hors école, non enseignée, élève hors classe) est rendu avec son statut
 * et son motif ; un refus ou une panne du roster KLASSCI, par {@see RendersKlassciBackedErrors}
 * (jamais une classe vide fabriquée).
 */
final class LMSClasseBilanController extends AuthenticatedController
{
    use RendersKlassciBackedErrors;

    public function __construct(
        private readonly BilanDeClasse $bilan,
        private readonly AccesAuBilanDeClasse $acces,
    ) {}

    public function show(VoirLeBilanDeClasseRequest $request, int $classe): JsonResponse
    {
        $lecteur = $this->authenticatedUser($request);

        try {
            $lue = $this->acces->classe($classe, $lecteur);
            $portee = $this->acces->portee($lue, $lecteur);
            $bilan = $this->bilan->de($lue, $lecteur, $request->du(), $request->au());

            return $this->successResponse($portee === AccesAuBilanDeClasse::ELEVE ? $this->acces->pourLEleve($bilan, $lue, $lecteur) : $bilan);
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        } catch (RuntimeException $e) {
            return $this->renderKlassciFailure($e);
        }
    }
}
