<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateAttendanceReportRequest;
use App\Http\Requests\GenerateGradesReportRequest;
use App\Http\Requests\GenerateActivityReportRequest;
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
    /**
     * Génère un rapport de présences (PDF)
     *
     * @param GenerateAttendanceReportRequest $request
     * @return \Illuminate\Http\Response
     */
    public function generateAttendanceReport(GenerateAttendanceReportRequest $request)
    {
        try {
            $dateStart = Carbon::parse($request->date_start);
            $dateEnd = Carbon::parse($request->date_end);
            $classeId = $request->classe_id;

            // Récupérer les données de présence
            $query = ESBTPAttendance::whereBetween('date', [$dateStart, $dateEnd]);

            if ($classeId) {
                $query->where('classe_id', $classeId);
            }

            $attendances = $query->with(['user'])->get();

            // Calculer les statistiques
            $totalAttendances = $attendances->count();
            $presents = $attendances->where('status', 'present')->count();
            $absents = $attendances->where('status', 'absent')->count();
            $retards = $attendances->where('status', 'late')->count();

            $tauxPresence = $totalAttendances > 0
                ? round(($presents / $totalAttendances) * 100, 2)
                : 0;

            // Grouper par étudiant
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

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Erreur génération rapport',
                'message' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * Génère un rapport de notes (PDF)
     *
     * @param GenerateGradesReportRequest $request
     * @return \Illuminate\Http\Response
     */
    public function generateGradesReport(GenerateGradesReportRequest $request)
    {
        try {
            $dateStart = Carbon::parse($request->date_start);
            $dateEnd = Carbon::parse($request->date_end);

            // Récupérer les soumissions
            $query = EvaluationSubmission::whereBetween('submitted_at', [$dateStart, $dateEnd])
                ->whereNotNull('note_sur_20')
                ->with(['evaluation', 'student']);

            // Filtrer par évaluation si fournie
            if ($request->evaluation_id) {
                $query->where('evaluation_id', $request->evaluation_id);
            }

            $submissions = $query->get();

            // Calculer les statistiques
            $totalSubmissions = $submissions->count();
            $averageGrade = $submissions->avg('note_sur_20');
            $highestGrade = $submissions->max('note_sur_20');
            $lowestGrade = $submissions->min('note_sur_20');

            // Grouper par étudiant
            $gradesByStudent = $submissions->groupBy('student_id')->map(function ($items, $studentId) {
                $student = $items->first()->student;
                return [
                    'name' => $student->name ?? 'Inconnu',
                    'email' => $student->email ?? '',
                    'total_evaluations' => $items->count(),
                    'average' => round($items->avg('note_sur_20'), 2),
                    'highest' => round($items->max('note_sur_20'), 2),
                    'lowest' => round($items->min('note_sur_20'), 2),
                    'evaluations' => $items->map(function ($submission) {
                        return [
                            'title' => $submission->evaluation->titre ?? 'Sans titre',
                            'note' => $submission->note_sur_20,
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

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Erreur génération rapport',
                'message' => 'Une erreur est survenue.'
            ], 500);
        }
    }

    /**
     * Génère un rapport d'activité système (PDF)
     *
     * @param GenerateActivityReportRequest $request
     * @return \Illuminate\Http\Response
     */
    public function generateActivityReport(GenerateActivityReportRequest $request)
    {
        try {
            $dateStart = Carbon::parse($request->date_start);
            $dateEnd = Carbon::parse($request->date_end);

            // Statistiques utilisateurs
            $newUsers = User::whereBetween('created_at', [$dateStart, $dateEnd])->count();
            $totalUsers = User::count();
            $usersByRole = User::selectRaw('role, COUNT(*) as count')
                ->groupBy('role')
                ->get()
                ->pluck('count', 'role');

            // Statistiques évaluations
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

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Erreur génération rapport',
                'message' => 'Une erreur est survenue.'
            ], 500);
        }
    }
}
