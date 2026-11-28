<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Requests\EmettreUnDocumentRequest;
use App\Services\Documents\EmissionDeDocument;
use App\Services\Documents\VerificationDeDocument;
use Barryvdh\DomPDF\PDF;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les documents de scolarité (#1662) : les émettre (gestion de l'école) et les vérifier (page PUBLIQUE). Aucune
 * lecture du mode ici : le service passe par la porte du catalogue, et le contrôleur relaie son refus.
 */
final class LMSDocumentsController extends AuthenticatedController
{
    use RespondsWithJson;

    public function store(EmettreUnDocumentRequest $request, int $eleve, EmissionDeDocument $emission, PDF $pdf): Response
    {
        try {
            $document = $emission->emettre($this->authenticatedUser($request), $eleve, $request->typeDeDocument());
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return response($pdf->loadView('documents.scolarite', ['document' => $document])->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s.pdf"', $document->numero),
        ]);
    }

    /** Public : la même réponse pour un code inconnu et un code mal formé. */
    public function verifier(string $code, VerificationDeDocument $verification): JsonResponse
    {
        $donnees = $verification->verifier($code);

        if ($donnees === null) {
            return $this->errorResponse('Aucun document ne porte ce code.', 404);
        }

        return $this->successResponse($donnees);
    }
}
