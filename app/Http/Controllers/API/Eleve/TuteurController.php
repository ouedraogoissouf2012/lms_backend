<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Eleve;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\AjouterTuteurRequest;
use App\Http\Requests\ModifierTuteurRequest;
use App\Services\Eleve\TuteursDeLEleve;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Les tuteurs d'un élève (#1657), gestion d'une école autonome. Les règles vivent dans {@see TuteursDeLEleve}.
 */
final class TuteurController extends AuthenticatedController
{
    public function __construct(private readonly TuteursDeLEleve $tuteurs) {}

    public function store(AjouterTuteurRequest $request, int $eleve): JsonResponse
    {
        return $this->traduire(fn (): JsonResponse => $this->successResponse(
            $this->tuteurs->ajouter($this->authenticatedUser($request), $eleve, $request->validated()),
            'Tuteur lié à l\'élève',
            201,
        ));
    }

    public function update(ModifierTuteurRequest $request, int $tuteur): JsonResponse
    {
        return $this->traduire(fn (): JsonResponse => $this->successResponse(
            $this->tuteurs->modifier($tuteur, $request->validated()),
            'Tuteur mis à jour',
        ));
    }

    public function destroy(Request $request, int $eleve, int $tuteur): JsonResponse
    {
        return $this->traduire(function () use ($request, $eleve, $tuteur): JsonResponse {
            $this->tuteurs->retirer($this->authenticatedUser($request), $eleve, $tuteur);

            return $this->successResponse(null, 'Tuteur retiré de l\'élève');
        });
    }

    /** @param  callable(): JsonResponse  $acte */
    private function traduire(callable $acte): JsonResponse
    {
        try {
            return $acte();
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
    }
}
