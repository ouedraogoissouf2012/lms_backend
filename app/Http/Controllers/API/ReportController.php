<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesApiExceptions;
use App\Models\User;
use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\ESBTPAttendance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Contrôleur pour la génération de rapports PDF
 */
class ReportController extends Controller
{
    use HandlesApiExceptions;
    /**
     * Génère un rapport de présences (PDF)
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function generateAttendanceReport(Request $request)
    {
        $request->validate([
            'date_start' => 'nullable|date',
            'date_end' => 'nullable|date|after_or_equal:date_start',
            'classe_id' => 'nullable|integer'
        ]);

        $dateStart = $request->date_start ? Carbon::parse($request->date_start) : Carbon::now()->subMonth();
        $dateEnd = $request->date_end ? Carbon::parse($request->date_end) : Carbon::now();
        $classeId = $request->classe_id;

        $query = ESBTPAttendance::whereBetween('date', [$dateStart, $dateEnd]);

        if ($classeId) {
            $query->where('classe_id', $classeId);
        }

        $attendances = $query->with(['user'])->get();

        $totalAttendances = $attendances->count();
        $presents = $attendances->where('status', 'present')->count();
        $absents = $attendances->where('status', 'absent')->count();
        $retards = $attendances->where('status', 'late')->count();

        $tauxPresence = $totalAttendances > 0
            ? round(($presents / $totalAttendances) * 100, 2)
            : 0;

        $attendancesByStudent = $attendances->groupBy('user_id')->map(function ($items, $userId) {
            $user = $items->first()->user;
            return [
                'name' => $user->name ?? 'Inconnu',
                'email' => $user->email ?? '',
                'total' => $items->count(),
                'presents' => $items->where('status', 'present')->count(),
                'absents' => $items->where('status', 'absent')->count(),
                'retards' => $items->where('status', 'late')->count(),
                'taux' => round(($items->where('status', 'present')->count() / $items->count()) * 100, 2)
            ];
        })->values();

        $data = [
            'title' => 'Rapport de Présences',
            'date_start' => $dateStart->format('d/m/Y'),
            'date_end' => $dateEnd->format('d/m/Y'),
            'generated_at' => Carbon::now()->format('d/m/Y H:i'),
            'stats' => [
                'total' => $totalAttendances,
                'presents' => $presents,
                'absents' => $absents,
                'retards' => $retards,
                'taux_presence' => $tauxPresence
            ],
            'students' => $attendancesByStudent
        ];

        $pdf = Pdf::loadView('reports.attendance', $data);

        return $pdf->download('rapport-presences-' . Carbon::now()->format('Y-m-d') . '.pdf');
    }

    /**
     * Génère un rapport de notes (PDF)
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function generateGradesReport(Request $request)
    {
        $request->validate([
            'date_start' => 'nullable|date',
            'date_end' => 'nullable|date|after_or_equal:date_start',
            'classe_id' => 'nullable|integer',
            'matiere_id' => 'nullable|integer'
        ]);

        $dateStart = $request->date_start ? Carbon::parse($request->date_start) : Carbon::now()->subMonth();
        $dateEnd = $request->date_end ? Carbon::parse($request->date_end) : Carbon::now();

        $query = EvaluationSubmission::whereBetween('submitted_at', [$dateStart, $dateEnd])
            ->whereNotNull('note')
            ->with(['evaluation', 'user']);

        $submissions = $query->get();

        $totalSubmissions = $submissions->count();
        $averageGrade = $submissions->avg('note');
        $highestGrade = $submissions->max('note');
        $lowestGrade = $submissions->min('note');

        $gradesByStudent = $submissions->groupBy('user_id')->map(function ($items, $userId) {
            $user = $items->first()->user;
            return [
                'name' => $user->name ?? 'Inconnu',
                'email' => $user->email ?? '',
                'total_evaluations' => $items->count(),
                'average' => round($items->avg('note'), 2),
                'highest' => round($items->max('note'), 2),
                'lowest' => round($items->min('note'), 2),
                'evaluations' => $items->map(function ($submission) {
                    return [
                        'title' => $submission->evaluation->titre ?? 'Sans titre',
                        'note' => $submission->note,
                        'date' => $submission->submitted_at->format('d/m/Y')
                    ];
                })
            ];
        })->values();

        $data = [
            'title' => 'Rapport de Notes',
            'date_start' => $dateStart->format('d/m/Y'),
            'date_end' => $dateEnd->format('d/m/Y'),
            'generated_at' => Carbon::now()->format('d/m/Y H:i'),
            'stats' => [
                'total_submissions' => $totalSubmissions,
                'average' => round($averageGrade, 2),
                'highest' => round($highestGrade, 2),
                'lowest' => round($lowestGrade, 2)
            ],
            'students' => $gradesByStudent
        ];

        $pdf = Pdf::loadView('reports.grades', $data);

        return $pdf->download('rapport-notes-' . Carbon::now()->format('Y-m-d') . '.pdf');
    }

    /**
     * Génère un rapport d'activité système (PDF)
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function generateActivityReport(Request $request)
    {
        $request->validate([
            'date_start' => 'nullable|date',
            'date_end' => 'nullable|date|after_or_equal:date_start'
        ]);

        $dateStart = $request->date_start ? Carbon::parse($request->date_start) : Carbon::now()->subMonth();
        $dateEnd = $request->date_end ? Carbon::parse($request->date_end) : Carbon::now();

        $newUsers = User::whereBetween('created_at', [$dateStart, $dateEnd])->count();
        $totalUsers = User::count();
        $usersByRole = User::selectRaw('role, COUNT(*) as count')
            ->groupBy('role')
            ->get()
            ->pluck('count', 'role');

        $newEvaluations = Evaluation::whereBetween('created_at', [$dateStart, $dateEnd])->count();
        $totalEvaluations = Evaluation::count();
        $evaluationSubmissions = EvaluationSubmission::whereBetween('submitted_at', [$dateStart, $dateEnd])->count();

        $data = [
            'title' => 'Rapport d\'Activité Système',
            'date_start' => $dateStart->format('d/m/Y'),
            'date_end' => $dateEnd->format('d/m/Y'),
            'generated_at' => Carbon::now()->format('d/m/Y H:i'),
            'users' => [
                'new' => $newUsers,
                'total' => $totalUsers,
                'by_role' => $usersByRole
            ],
            'evaluations' => [
                'new' => $newEvaluations,
                'total' => $totalEvaluations,
                'submissions' => $evaluationSubmissions
            ]
        ];

        $pdf = Pdf::loadView('reports.activity', $data);

        return $pdf->download('rapport-activite-' . Carbon::now()->format('Y-m-d') . '.pdf');
    }
}
