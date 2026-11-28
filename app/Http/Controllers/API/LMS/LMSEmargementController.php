<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Exceptions\EssaisDeCodeEpuises;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\GenererCodeEmargementRequest;
use App\Http\Requests\ListerCodesEmargementRequest;
use App\Http\Requests\SignerParCodeRequest;
use App\Services\Attendances\Emargement\EmargementParCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * #1680 — l'émargement par code d'une séance en ligne :
 * - `POST /lms/codes-emargement`, `GET /lms/codes-emargement`, `DELETE /lms/codes-emargement/{codeId}` (personnel) ;
 * - `POST /lms/emargement` (élève).
 *
 * La source de l'école ({@see EmargementParCode}) est reçue par la MÉTHODE, jamais par le constructeur (ADR-EI-01).
 */
final class LMSEmargementController extends AuthenticatedController
{
    public function store(GenererCodeEmargementRequest $request, EmargementParCode $emargement): JsonResponse
    {
        try {
            return $this->successResponse($emargement->generer(
                $request->seanceId(),
                $this->authenticatedUser($request),
                $request->dureeMinutes(),
                $request->retardApresMinutes(),
            ), 'Code d\'émargement généré', 201);
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
    }

    public function index(ListerCodesEmargementRequest $request, EmargementParCode $emargement): JsonResponse
    {
        try {
            return $this->successResponse($emargement->lister($request->seanceId(), $this->authenticatedUser($request)));
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
    }

    public function destroy(Request $request, int $codeId, EmargementParCode $emargement): JsonResponse
    {
        try {
            $emargement->revoquer($codeId, $this->authenticatedUser($request));

            return $this->successResponse(null, 'Code révoqué');
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
    }

    public function sign(SignerParCodeRequest $request, EmargementParCode $emargement): JsonResponse
    {
        try {
            return $this->successResponse($emargement->signer($this->authenticatedUser($request), $request->code()));
        } catch (EssaisDeCodeEpuises $e) {
            return $this->errorResponse(EssaisDeCodeEpuises::MESSAGE, 429, reason: EssaisDeCodeEpuises::REASON)
                ->header('Retry-After', (string) max(1, $e->secondesAvantNouvelEssai));
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
    }
}
