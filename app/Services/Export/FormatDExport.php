<?php

declare(strict_types=1);

namespace App\Services\Export;

/**
 * Les formats d'export d'une liste (#1656) : CSV et PDF. Pas de XLSX (décision du 28/11, comme #683) : le CSV, en
 * UTF-8 avec BOM et `;`, s'ouvre dans Excel sans nouvelle dépendance de production.
 */
enum FormatDExport: string
{
    case Csv = 'csv';
    case Pdf = 'pdf';

    public function typeDeContenu(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=UTF-8',
            self::Pdf => 'application/pdf',
        };
    }
}
