<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Classe;
use App\Models\ESBTPAttendance;
use App\Models\Seance;
use Carbon\Carbon;

final class AttendanceReportContextBuilder
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    public function build(array $data): array
    {
        $dateStart = Carbon::parse($this->stringField($data, 'date_start'));
        $dateEnd = Carbon::parse($this->stringField($data, 'date_end'));
        $classeId = $this->integerField($data, 'classe_id');
        $klassciClasseId = $this->resolveKlassciClasseId($classeId);

        $seances = Seance::query()
            ->whereBetween('date_seance', [$dateStart, $dateEnd->copy()->endOfDay()]);

        if ($klassciClasseId !== null) {
            $seances->where('klassci_classe_id', $klassciClasseId);
        }

        $attendances = ESBTPAttendance::query()
            ->whereIn('seance_id', $seances->select('id'))
            ->with(['user', 'seance'])
            ->get();
        $total = $attendances->count();
        $presents = $attendances->whereIn('status', ['connected', 'disconnected'])->count();
        $absents = $attendances->where('status', 'kicked')->count();

        $students = $attendances->groupBy('user_id')->map(function ($items): array {
            $first = $items->first();
            $student = $first?->user;
            $count = $items->count();
            $presentCount = $items->whereIn('status', ['connected', 'disconnected'])->count();

            return [
                'name' => $student->name ?? 'Inconnu',
                'email' => $student->email ?? '',
                'total' => $count,
                'presents' => $presentCount,
                'absents' => $items->where('status', 'kicked')->count(),
                'retards' => 0,
                'taux' => $count > 0 ? round(($presentCount / $count) * 100, 2) : 0.0,
            ];
        })->values();

        return [
            'title' => 'Rapport de Présences',
            'date_start' => $dateStart->format('d/m/Y'),
            'date_end' => $dateEnd->format('d/m/Y'),
            'generated_at' => Carbon::now()->format('d/m/Y H:i'),
            'stats' => [
                'total' => $total,
                'presents' => $presents,
                'absents' => $absents,
                'retards' => 0,
                'taux_presence' => $total > 0 ? round(($presents / $total) * 100, 2) : 0.0,
            ],
            'students' => $students,
        ];
    }

    private function resolveKlassciClasseId(?int $classeId): ?int
    {
        if ($classeId === null) {
            return null;
        }

        $klassciId = Classe::query()->whereKey($classeId)->value('klassci_id');

        return is_numeric($klassciId) ? (int) $klassciId : null;
    }

    /** @param array<string, mixed> $data */
    private function stringField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /** @param array<string, mixed> $data */
    private function integerField(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
