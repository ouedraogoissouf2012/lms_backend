<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * L'export CSV du rapport des heures (#1682) : séparateur `;` et virgule décimale (Excel en français), BOM UTF-8 pour
 * les accents des noms, colonnes stables. Les heures sont les minutes comptées, en heures à deux décimales.
 */
final class HeuresEnseignantsCsv
{
    public const EN_TETES = ['enseignant_id', 'nom', 'seances', 'comptees', 'heures'];

    private const BOM = "\u{FEFF}";

    /** @param  array{du: string, au: string, enseignants: list<array{enseignant_id: int, nom: ?string, seances: int, comptees: int, minutes: int}>}  $rapport */
    public static function reponse(array $rapport): StreamedResponse
    {
        $nom = sprintf('heures-enseignants-%s-%s.csv', $rapport['du'], $rapport['au']);

        return new StreamedResponse(static function () use ($rapport): void {
            $sortie = fopen('php://output', 'wb');
            if ($sortie === false) {
                return;
            }
            fwrite($sortie, self::BOM);
            fputcsv($sortie, self::EN_TETES, ';', '"', '');
            foreach ($rapport['enseignants'] as $ligne) {
                fputcsv($sortie, [
                    $ligne['enseignant_id'],
                    $ligne['nom'] ?? '',
                    $ligne['seances'],
                    $ligne['comptees'],
                    number_format($ligne['minutes'] / 60, 2, ',', ''),
                ], ';', '"', '');
            }
            fclose($sortie);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$nom.'"',
        ]);
    }
}
