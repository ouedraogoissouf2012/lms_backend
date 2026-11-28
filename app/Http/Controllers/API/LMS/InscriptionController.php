<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\GesteSurUneInscriptionRequest;
use App\Services\Enrollment\ValidationDesInscriptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Les gestes de l'école sur une inscription (#1658) : valider, annuler, réserve et sa levée, fiche, réglage. Les règles
 * vivent dans {@see ValidationDesInscriptions}.
 */
final class InscriptionController extends AuthenticatedController
{
    public function __construct(private readonly ValidationDesInscriptions $validations) {}

    public function valider(Request $request, int $inscription): JsonResponse
    {
        return $this->traduire(fn (): JsonResponse => $this->successResponse(
            $this->validations->valider($this->authenticatedUser($request), $inscription), 'Inscription validée',
        ));
    }

    public function annuler(GesteSurUneInscriptionRequest $request, int $inscription): JsonResponse
    {
        return $this->traduire(fn (): JsonResponse => $this->successResponse(
            $this->validations->annuler($this->authenticatedUser($request), $inscription, (string) $request->texte('motif')), 'Inscription annulée',
        ));
    }

    public function reserver(GesteSurUneInscriptionRequest $request, int $inscription): JsonResponse
    {
        return $this->traduire(fn (): JsonResponse => $this->successResponse(
            $this->validations->reserver($inscription, (string) $request->texte('condition'), $request->texte('piece')), 'Réserve posée',
        ));
    }

    public function leverReserve(Request $request, int $inscription): JsonResponse
    {
        return $this->traduire(fn (): JsonResponse => $this->successResponse(
            $this->validations->leverReserve($this->authenticatedUser($request), $inscription), 'Réserve levée',
        ));
    }

    public function fiche(GesteSurUneInscriptionRequest $request, int $inscription): JsonResponse
    {
        return $this->traduire(fn (): JsonResponse => $this->successResponse(
            $this->validations->fiche($inscription, $request->validated()), 'Inscription mise à jour',
        ));
    }

    public function regler(GesteSurUneInscriptionRequest $request): JsonResponse
    {
        return $this->traduire(fn (): JsonResponse => $this->successResponse(
            $this->validations->regler($request->boolean('a_valider')), 'Réglage enregistré',
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
