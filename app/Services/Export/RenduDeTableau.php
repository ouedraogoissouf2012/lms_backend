<?php

declare(strict_types=1);

namespace App\Services\Export;

use Barryvdh\DomPDF\PDF;
use League\Csv\EscapeFormula;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rend un {@see Tableau} dans un format (#1656).
 *
 * - CSV : UTF-8 avec BOM (Excel l'ouvre sans casser les accents), séparateur `;` (celui qu'Excel attend en
 *   français). Une cellule qui commencerait une formule (`=`, `+`, `-`, `@`, tabulation) est préfixée d'une
 *   apostrophe (`EscapeFormula`) : un nom d'élève ne s'exécute jamais dans le tableur de l'école.
 * - PDF : la vue `exports.tableau`, par dompdf (ressources distantes coupées, #549).
 */
final class RenduDeTableau
{
    private const BOM = "\u{FEFF}";

    public function __construct(private readonly PDF $pdf) {}

    public function rendre(Tableau $tableau, FormatDExport $format): Response
    {
        $nom = sprintf('%s-%s.%s', $tableau->fichier, now()->format('Y-m-d'), $format->value);

        $corps = match ($format) {
            FormatDExport::Csv => $this->csv($tableau),
            FormatDExport::Pdf => $this->pdf->loadView('exports.tableau', [
                'tableau' => $tableau,
                'edite' => now()->format('d/m/Y'),
            ])->output(),
        };

        return response($corps, 200, [
            'Content-Type' => $format->typeDeContenu(),
            'Content-Disposition' => sprintf('attachment; filename="%s"', $nom),
        ]);
    }

    private function csv(Tableau $tableau): string
    {
        $csv = Writer::createFromString();
        $csv->setDelimiter(';');
        $csv->setEscape('');
        // $csv->addFormatter(new EscapeFormula);
        $csv->insertOne($tableau->entetes);
        $csv->insertAll($tableau->lignes);

        return self::BOM.$csv->toString();
    }
}
