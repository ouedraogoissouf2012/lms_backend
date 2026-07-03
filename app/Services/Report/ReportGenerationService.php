<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Classe;
use App\Models\ESBTPAttendance;
use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use Barryvdh\DomPDF\PDF;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Génération des rapports PDF (présences, notes, activité système).
 * Extrait du `ReportController` — split §5/§1.1, DI strict §1.6 D.
 * Convention de retour : `status === 200` → `payload` est la {@see Response}
 * PDF de `Pdf::download()` ; sinon tableau JSON pour `response()->json(...)`.
 *
 * @phpstan-type ServiceResult array{status: int, payload: Response|array<string, mixed>}
 */
final class ReportGenerationService
{
    public function __construct(
        private readonly PDF $pdf,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Rapport de présences (filtre classe via `Classe.klassci_id` ↔ `seance.klassci_classe_id`).
     * Filtre `present`/`absent`/`late` : aucune conversion depuis {@see \App\Models\ESBTPAttendance::$status} (`connected`/`disconnected`) n'existe, `$presents` etc. valent toujours 0 — voir #391.
     * @param array<string, mixed> $data Payload `GenerateAttendanceReportRequest`.
     * @return ServiceResult
     */
    public function generateAttendance(array $data, User $user): array
    {
        try {
            $dateStart = Carbon::parse($this->stringField($data, 'date_start'));
            $dateEnd = Carbon::parse($this->stringField($data, 'date_end'));
            $classeId = isset($data['classe_id']) ? (int) $data['classe_id'] : null;

            $klassciClasseId = $this->resolveKlassciClasseId($classeId);

            $query = ESBTPAttendance::whereHas('seance', function ($q) use ($dateStart, $dateEnd): void {
                $q->whereBetween('date_seance', [$dateStart, $dateEnd->copy()->endOfDay()]);
            });

            if ($klassciClasseId !== null) {
                $query->whereHas('seance', function ($q) use ($klassciClasseId): void {
                    $q->where('klassci_classe_id', $klassciClasseId);
                });
            }

            /** @var \Illuminate\Database\Eloquent\Collection<int, ESBTPAttendance> $attendances */
            $attendances = $query->with(['user', 'seance'])->get();

            $totalAttendances = $attendances->count();
            $presents = $attendances->where('status', 'present')->count();
            $absents = $attendances->where('status', 'absent')->count();
            $retards = $attendances->where('status', 'late')->count();

            $tauxPresence = $totalAttendances > 0
                ? round(($presents / $totalAttendances) * 100, 2)
                : 0.0;

            $attendancesByStudent = $attendances->groupBy('user_id')->map(function ($items): array {
                /** @var \Illuminate\Database\Eloquent\Collection<int, ESBTPAttendance> $items */
                $first = $items->first();
                $student = $first?->user;
                $count = $items->count();
                $studentPresents = $items->where('status', 'present')->count();

                return [
                    'name' => $student->name ?? 'Inconnu',
                    'email' => $student->email ?? '',
                    'total' => $count,
                    'presents' => $studentPresents,
                    'absents' => $items->where('status', 'absent')->count(),
                    'retards' => $items->where('status', 'late')->count(),
                    'taux' => $count > 0 ? round(($studentPresents / $count) * 100, 2) : 0.0,
                ];
            })->values();

            $context = [
                'title' => 'Rapport de Présences',
                'date_start' => $dateStart->format('d/m/Y'),
                'date_end' => $dateEnd->format('d/m/Y'),
                'generated_at' => Carbon::now()->format('d/m/Y H:i'),
                'stats' => [
                    'total' => $totalAttendances,
                    'presents' => $presents,
                    'absents' => $absents,
                    'retards' => $retards,
                    'taux_presence' => $tauxPresence,
                ],
                'students' => $attendancesByStudent,
            ];

            return $this->renderPdf('reports.attendance', $context, 'rapport-presences');
        } catch (Throwable $e) {
            return $this->errorPayload($e, 'attendance', $user);
        }
    }

    /**
     * Rapport de notes (soumissions avec `note_sur_20`).
     * @param array<string, mixed> $data Payload `GenerateGradesReportRequest`.
     * @return ServiceResult
     */
    public function generateGrades(array $data, User $user): array
    {
        try {
            $dateStart = Carbon::parse($this->stringField($data, 'date_start'));
            $dateEnd = Carbon::parse($this->stringField($data, 'date_end'));
            $evaluationId = isset($data['evaluation_id']) ? (int) $data['evaluation_id'] : null;

            $query = EvaluationSubmission::whereBetween('submitted_at', [$dateStart, $dateEnd])
                ->whereNotNull('note_sur_20')
                ->with(['evaluation', 'student']);

            if ($evaluationId !== null) {
                $query->where('evaluation_id', $evaluationId);
            }

            /** @var \Illuminate\Database\Eloquent\Collection<int, EvaluationSubmission> $submissions */
            $submissions = $query->get();

            $totalSubmissions = $submissions->count();
            $averageGrade = (float) ($submissions->avg('note_sur_20') ?? 0);
            $highestGrade = (float) ($submissions->max('note_sur_20') ?? 0);
            $lowestGrade = (float) ($submissions->min('note_sur_20') ?? 0);

            $gradesByStudent = $submissions->groupBy('student_id')->map(function ($items): array {
                /** @var \Illuminate\Database\Eloquent\Collection<int, EvaluationSubmission> $items */
                $first = $items->first();
                $student = $first?->student;

                return [
                    'name' => $student->name ?? 'Inconnu',
                    'email' => $student->email ?? '',
                    'total_evaluations' => $items->count(),
                    'average' => round((float) ($items->avg('note_sur_20') ?? 0), 2),
                    'highest' => round((float) ($items->max('note_sur_20') ?? 0), 2),
                    'lowest' => round((float) ($items->min('note_sur_20') ?? 0), 2),
                    'evaluations' => $items->map(static function (EvaluationSubmission $submission): array {
                        return [
                            'title' => $submission->evaluation->titre ?? 'Sans titre',
                            'note' => $submission->note_sur_20,
                            'date' => $submission->submitted_at?->format('d/m/Y'),
                        ];
                    }),
                ];
            })->values();

            $context = [
                'title' => 'Rapport de Notes',
                'date_start' => $dateStart->format('d/m/Y'),
                'date_end' => $dateEnd->format('d/m/Y'),
                'generated_at' => Carbon::now()->format('d/m/Y H:i'),
                'stats' => [
                    'total_submissions' => $totalSubmissions,
                    'average' => round($averageGrade, 2),
                    'highest' => round($highestGrade, 2),
                    'lowest' => round($lowestGrade, 2),
                ],
                'students' => $gradesByStudent,
            ];

            return $this->renderPdf('reports.grades', $context, 'rapport-notes');
        } catch (Throwable $e) {
            return $this->errorPayload($e, 'grades', $user);
        }
    }

    /**
     * Rapport d'activité système (vue globale, pas de filtrage classe).
     * @param array<string, mixed> $data Payload `GenerateActivityReportRequest`.
     * @return ServiceResult
     */
    public function generateActivity(array $data, User $user): array
    {
        try {
            $dateStart = Carbon::parse($this->stringField($data, 'date_start'));
            $dateEnd = Carbon::parse($this->stringField($data, 'date_end'));

            $newUsers = User::whereBetween('created_at', [$dateStart, $dateEnd])->count();
            $totalUsers = User::count();
            $usersByRole = User::selectRaw('role, COUNT(*) as count')
                ->groupBy('role')
                ->get()
                ->pluck('count', 'role');

            $newEvaluations = Evaluation::whereBetween('created_at', [$dateStart, $dateEnd])->count();
            $totalEvaluations = Evaluation::count();
            $evaluationSubmissions = EvaluationSubmission::whereBetween('submitted_at', [$dateStart, $dateEnd])->count();

            $context = [
                'title' => 'Rapport d\'Activité Système',
                'date_start' => $dateStart->format('d/m/Y'),
                'date_end' => $dateEnd->format('d/m/Y'),
                'generated_at' => Carbon::now()->format('d/m/Y H:i'),
                'users' => [
                    'new' => $newUsers,
                    'total' => $totalUsers,
                    'by_role' => $usersByRole,
                ],
                'evaluations' => [
                    'new' => $newEvaluations,
                    'total' => $totalEvaluations,
                    'submissions' => $evaluationSubmissions,
                ],
            ];

            return $this->renderPdf('reports.activity', $context, 'rapport-activite');
        } catch (Throwable $e) {
            return $this->errorPayload($e, 'activity', $user);
        }
    }

    /**
     * Résout `Classe.klassci_id` à partir de l'id interne. `null` si pas de
     * filtre ou classe introuvable — comportement identique au pré-split
     * (silencieux, pas de 404).
     */
    private function resolveKlassciClasseId(?int $classeId): ?int
    {
        if ($classeId === null) {
            return null;
        }

        /** @var Classe|null $classe */
        $classe = Classe::find($classeId);
        if ($classe === null) {
            return null;
        }

        /** @var int|null $klassciId */
        $klassciId = $classe->klassci_id;

        return $klassciId;
    }

    /**
     * @param array<string, mixed> $context
     * @return ServiceResult
     */
    private function renderPdf(string $view, array $context, string $filenamePrefix): array
    {
        $filename = $filenamePrefix . '-' . Carbon::now()->format('Y-m-d') . '.pdf';
        $response = $this->pdf->loadView($view, $context)->download($filename);

        return [
            'status' => 200,
            'payload' => $response,
        ];
    }

    /**
     * Coerce un champ validé en `string` pour `Carbon::parse` (les Requests
     * garantissent `date_format:Y-m-d`, on sécurise PHPStan).
     *
     * @param array<string, mixed> $data
     */
    private function stringField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : (string) $value;
    }

    /**
     * Payload d'erreur 500 homogène — message générique côté client,
     * cause détaillée loguée via PSR-3.
     *
     * @return ServiceResult
     */
    private function errorPayload(Throwable $e, string $reportType, User $user): array
    {
        $this->logger->error('Échec génération rapport', [
            'report_type' => $reportType,
            'user_id' => $user->id,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        return [
            'status' => 500,
            'payload' => [
                'success' => false,
                'error' => 'Erreur génération rapport',
                'message' => 'Une erreur est survenue.',
            ],
        ];
    }
}
