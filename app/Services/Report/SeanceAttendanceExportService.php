<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\ESBTPAttendance;
use App\Models\Seance;
use App\Models\User;
use Barryvdh\DomPDF\PDF;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * Export d'une séance par `seances.id` (#726). PDF ou Excel, synchrone.
 */
final class SeanceAttendanceExportService
{
    public function __construct(
        private readonly PDF $pdf,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{status: int, payload: Response|array<string, mixed>}
     */
    public function export(array $data, User $user): array
    {
        $seanceId = isset($data['seance_id']) ? (int) $data['seance_id'] : 0;
        $seance = Seance::query()->find($seanceId);
        if ($seance === null || $seance->institution_id !== $user->institution_id) {
            return [
                'status' => 404,
                'payload' => ['success' => false, 'message' => 'Séance introuvable'],
            ];
        }

        $rows = ESBTPAttendance::query()
            ->where('seance_id', $seance->id)
            ->orderBy('id')
            ->get();

        $format = is_string($data['format'] ?? null) ? strtolower($data['format']) : 'pdf';
        if ($format === 'excel' || $format === 'xlsx') {
            return $this->renderExcel($seance, $rows);
        }

        return $this->renderPdf($seance, $rows);
    }

    /**
     * @param  Collection<int, ESBTPAttendance>  $rows
     * @return array{status: int, payload: Response}
     */
    private function renderPdf(Seance $seance, Collection $rows): array
    {
        $html = view('reports.seance-attendance', [
            'seance' => $seance,
            'rows' => $rows,
        ])->render();
        $filename = 'presences-seance-'.$seance->id.'.pdf';

        return [
            'status' => 200,
            'payload' => $this->pdf->loadHTML($html)->download($filename),
        ];
    }

    /**
     * @param  Collection<int, ESBTPAttendance>  $rows
     * @return array{status: int, payload: Response}
     */
    private function renderExcel(Seance $seance, Collection $rows): array
    {
        $body = $rows->map(function (ESBTPAttendance $row): string {
            $cells = [
                $row->nom ?? '',
                $row->prenom ?? '',
                $row->email ?? '',
                (string) $row->status,
                $row->joined_at?->toDateTimeString() ?? '',
                $row->left_at?->toDateTimeString() ?? '',
            ];

            return '<Row>'.collect($cells)->map(
                static fn (string $v): string => '<Cell><Data ss:Type="String">'.e($v).'</Data></Cell>',
            )->implode('').'</Row>';
        })->implode('');

        $xml = '<?xml version="1.0"?><?mso-application progid="Excel.Sheet"?>'
            .'<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            .' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Presences">'
            .'<Table>'.$body.'</Table></Worksheet></Workbook>';

        $response = response($xml, 200, [
            'Content-Type' => 'application/vnd.ms-excel',
            'Content-Disposition' => 'attachment; filename="presences-seance-'.$seance->id.'.xls"',
        ]);

        return ['status' => 200, 'payload' => $response];
    }
}
