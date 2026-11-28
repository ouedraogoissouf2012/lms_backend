<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\DeposerJustificatifRequest;
use App\Http\Requests\ListerJustificatifsRequest;
use App\Http\Requests\RejeterJustificatifRequest;
use App\Services\Attendances\Justification\DepotDeJustificatif;
use App\Services\Attendances\Justification\JustificationDAbsence;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * #1681 — l'élève justifie une absence, l'école tranche :
 * - élève : `GET /lms/mes-absences`, `GET /lms/mes-justificatifs`, `POST /lms/justificatifs` ;
 * - gestion : `GET /lms/justificatifs`, `POST /lms/justificatifs/{id}/valider`, `POST …/rejeter` ;
 * - la pièce : `GET /lms/justificatifs/{id}/piece` (l'élève déposant, la gestion de son école).
 *
 * La source de l'école ({@see JustificationDAbsence}) est reçue par la MÉTHODE, jamais par le constructeur
 * (ADR-EI-01).
 */
final class LMSJustificatifController extends AuthenticatedController
{
    public function mesAbsences(Request $request, JustificationDAbsence $justification): JsonResponse
    {
        return $this->repondre(fn () => $this->successResponse($justification->mesAbsences($this->authenticatedUser($request))));
    }

    public function mesJustificatifs(Request $request, JustificationDAbsence $justification): JsonResponse
    {
        return $this->repondre(fn () => $this->successResponse($justification->mesJustificatifs($this->authenticatedUser($request))));
    }

    public function store(DeposerJustificatifRequest $request, JustificationDAbsence $justification): JsonResponse
    {
        return $this->repondre(fn () => $this->successResponse($justification->deposer(
            $this->authenticatedUser($request),
            $request->motif(),
            $request->seanceIds(),
            $request->du(),
            $request->au(),
            $request->file('piece') instanceof UploadedFile ? $request->file('piece') : null,
        ), 'Justificatif déposé', 201));
    }

    public function index(ListerJustificatifsRequest $request, JustificationDAbsence $justification): JsonResponse
    {
        return $this->repondre(fn () => $this->successResponse($justification->lister($this->authenticatedUser($request), $request->filtres())));
    }

    public function valider(Request $request, int $justificatifId, JustificationDAbsence $justification): JsonResponse
    {
        return $this->repondre(fn () => $this->successResponse(
            $justification->valider($justificatifId, $this->authenticatedUser($request)),
            'Justificatif validé',
        ));
    }

    public function rejeter(RejeterJustificatifRequest $request, int $justificatifId, JustificationDAbsence $justification): JsonResponse
    {
        return $this->repondre(fn () => $this->successResponse(
            $justification->rejeter($justificatifId, $this->authenticatedUser($request), $request->motif()),
            'Justificatif rejeté',
        ));
    }

    public function piece(Request $request, int $justificatifId, JustificationDAbsence $justification, FilesystemFactory $disques): StreamedResponse|JsonResponse
    {
        try {
            $piece = $justification->piece($justificatifId, $this->authenticatedUser($request));
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
        $disque = $disques->disk(DepotDeJustificatif::DISQUE);
        if (! $disque->exists($piece->chemin)) {
            return $this->errorResponse('Pièce introuvable', 404);
        }

        return $disque->download($piece->chemin, $piece->nom, ['Content-Type' => $piece->mime]);
    }

    /** @param  callable(): JsonResponse  $action */
    private function repondre(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
    }
}
