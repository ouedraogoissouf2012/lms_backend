<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Donnees;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\ExporterLesDonneesRequest;
use App\Services\Donnees\ExportDesDonneesDeLEleve;
use Barryvdh\DomPDF\PDF;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'export des données d'un élève (#1705), par l'administration de son école. Un téléchargement (`attachment`), jamais
 * mis en cache (`no-store`) : il porte toutes les données d'une personne. Les règles vivent dans
 * {@see ExportDesDonneesDeLEleve}.
 */
final class ExportDesDonneesController extends AuthenticatedController
{
    public function __construct(
        private readonly ExportDesDonneesDeLEleve $export,
        private readonly PDF $pdf,
    ) {}

    public function show(ExporterLesDonneesRequest $request, int $eleve): Response
    {
        $format = $request->formatDeLExport();
        try {
            $export = $this->export->exporter($this->authenticatedUser($request), $eleve, $format);
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
        $fichier = 'donnees-eleve-'.$export['eleve']->id.'-'.now()->format('Y-m-d').'.'.$format;

        $reponse = $format === 'pdf'
            ? $this->pdf->loadView('pdfs.donnees-eleve', $export + ['genere_le' => now()])->download($fichier)
            : new JsonResponse(['success' => true, 'data' => $export['donnees']], 200, [
                'Content-Disposition' => 'attachment; filename="'.$fichier.'"',
            ]);
        $reponse->headers->set('Cache-Control', 'no-store, private');

        return $reponse;
    }
}
