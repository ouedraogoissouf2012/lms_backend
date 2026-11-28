<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Reinscription;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\CorrigerPassageRequest;
use App\Http\Requests\EcrireReglesDePassageRequest;
use App\Services\Reinscription\PropositionsDePassage;
use App\Services\Reinscription\ReglesDePassage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Les règles de passage et les propositions d'une classe (#1663, PR 1), gestion d'une école autonome. Les règles
 * vivent dans {@see ReglesDePassage} et {@see PropositionsDePassage}.
 */
final class ReglesDePassageController extends AuthenticatedController
{
    public function __construct(
        private readonly ReglesDePassage $regles,
        private readonly PropositionsDePassage $propositions,
    ) {}

    public function ecole(Request $request): JsonResponse
    {
        return $this->traduire(fn (): JsonResponse => $this->successResponse($this->regles->deLEcole($this->authenticatedUser($request))));
    }

    public function ecrireEcole(EcrireReglesDePassageRequest $request): JsonResponse
    {
        return $this->traduire(fn (): JsonResponse => $this->successResponse(
            $this->regles->ecrireEcole($this->authenticatedUser($request), $request->validated()),
            'Règles de passage enregistrées',
        ));
    }

    public function classe(Request $request, int $classe): JsonResponse
    {
        return $this->traduire(fn (): JsonResponse => $this->successResponse($this->regles->deLaClasse($this->authenticatedUser($request), $classe)));
    }

    public function surcharger(EcrireReglesDePassageRequest $request, int $classe): JsonResponse
    {
        return $this->traduire(fn (): JsonResponse => $this->successResponse(
            $this->regles->surchargerClasse($this->authenticatedUser($request), $classe, $request->validated()),
            'Règles de la classe enregistrées',
        ));
    }

    public function retirer(Request $request, int $classe): JsonResponse
    {
        return $this->traduire(fn (): JsonResponse => $this->successResponse(
            $this->regles->retirerSurcharge($this->authenticatedUser($request), $classe),
            'La classe suit les règles de l\'établissement',
        ));
    }

    public function passages(Request $request, int $classe): JsonResponse
    {
        return $this->traduire(fn (): JsonResponse => $this->successResponse($this->propositions->de($this->authenticatedUser($request), $classe)));
    }

    public function corriger(CorrigerPassageRequest $request, int $classe, int $eleve): JsonResponse
    {
        return $this->traduire(fn (): JsonResponse => $this->successResponse(
            $this->propositions->corriger($this->authenticatedUser($request), $classe, $eleve, $request->decision(), $request->motif()),
            'Décision enregistrée',
        ));
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
