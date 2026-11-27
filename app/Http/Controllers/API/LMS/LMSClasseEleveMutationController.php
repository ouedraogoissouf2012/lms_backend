<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\MuterEleveRequest;
use App\Services\Enrollment\MutationDEleve;
use Illuminate\Http\JsonResponse;

/**
 * Changer un élève de classe dans son école (#1652, PR C) : un geste NOMMÉ. Aucune lecture du
 * mode ici : le service passe par la porte du catalogue, et le contrôleur relaie son refus.
 */
final class LMSClasseEleveMutationController extends Controller
{
    use RespondsWithJson;

    public function __construct(private readonly MutationDEleve $mutations) {}

    public function store(MuterEleveRequest $request, int $classe, int $eleve): JsonResponse
    {
        try {
            $mutation = $this->mutations->muter($classe, $eleve, $request->cible());
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse($mutation, 'L\'élève a changé de classe. Son ancienne inscription est close.');
    }
}
