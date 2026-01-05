<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "═══════════════════════════════════════════════════════════════════\n";
echo "   TEST SYSTÈME STATUT INTELLIGENT\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// Fonction pour calculer le statut (même logique que le contrôleur)
function calculateAttendanceStatus($studentDuration, $seanceDuration, $isSeanceFinished)
{
    if (!$isSeanceFinished) {
        return [
            'status' => 'En cours',
            'level' => 'ongoing',
            'percentage' => null,
        ];
    }

    if (!$studentDuration || !$seanceDuration || $seanceDuration <= 0) {
        return [
            'status' => 'Absent',
            'level' => 'absent',
            'percentage' => 0,
        ];
    }

    $percentage = ($studentDuration / $seanceDuration) * 100;

    if ($percentage >= 80) {
        return ['status' => 'Présent', 'level' => 'present', 'percentage' => round($percentage, 1)];
    } elseif ($percentage >= 50) {
        return ['status' => 'Partiel', 'level' => 'partial', 'percentage' => round($percentage, 1)];
    } elseif ($percentage >= 20) {
        return ['status' => 'Faible', 'level' => 'low', 'percentage' => round($percentage, 1)];
    } else {
        return ['status' => 'Absent', 'level' => 'absent', 'percentage' => round($percentage, 1)];
    }
}

// Tester avec différentes séances
$seances = DB::table('seances')
    ->whereNotNull('visio_started_at')
    ->orderBy('klassci_seance_id', 'desc')
    ->limit(3)
    ->get();

foreach ($seances as $seance) {
    echo "═══════════════════════════════════════════════════════════════════\n";
    echo "SÉANCE #{$seance->klassci_seance_id} - {$seance->matiere_nom}\n";
    echo "═══════════════════════════════════════════════════════════════════\n";

    // Calculer durée séance
    $seanceDurationMinutes = null;
    $isSeanceFinished = false;

    if ($seance->visio_started_at && $seance->visio_ended_at) {
        $start = new DateTime($seance->visio_started_at);
        $end = new DateTime($seance->visio_ended_at);
        $diff = $start->diff($end);
        $seanceDurationMinutes = ($diff->h * 60) + $diff->i + ($diff->days * 24 * 60);
        $isSeanceFinished = true;
    }

    echo "\nINFORMATIONS SÉANCE:\n";
    echo "  Début: {$seance->visio_started_at}\n";
    echo "  Fin: " . ($seance->visio_ended_at ?? '[EN COURS]') . "\n";
    echo "  Durée totale: " . ($seanceDurationMinutes ? "{$seanceDurationMinutes} min" : '[EN COURS]') . "\n";
    echo "  Statut: " . ($isSeanceFinished ? '✅ Terminée' : '🔵 En cours') . "\n";

    // Récupérer l'enseignant
    $teacherName = $seance->enseignant_nom;
    if (!$teacherName) {
        $teacher = DB::table('esbtp_attendance')
            ->where('seance_id', $seance->id)
            ->where('is_observer', 1)
            ->first();
        $teacherName = $teacher ? $teacher->nom : 'Non spécifié';
    }
    echo "  Enseignant: {$teacherName}\n";

    // Récupérer les participations (étudiants uniquement)
    $attendances = DB::table('esbtp_attendance')
        ->where('seance_id', $seance->id)
        ->where(function($query) {
            $query->where('is_observer', false)
                  ->orWhereNull('is_observer');
        })
        ->where(function($query) {
            $query->where('duration_minutes', '>', 0)
                  ->orWhereNull('duration_minutes');
        })
        ->orderBy('joined_at', 'desc')
        ->get();

    echo "\n📋 LISTE DES PARTICIPANTS ({$attendances->count()}):\n";
    echo str_repeat('─', 100) . "\n";
    printf("%-25s %-15s %-15s %-20s\n", "Nom", "Durée", "Pourcentage", "Statut");
    echo str_repeat('─', 100) . "\n";

    foreach ($attendances as $att) {
        $statusData = calculateAttendanceStatus(
            $att->duration_minutes,
            $seanceDurationMinutes,
            $isSeanceFinished
        );

        $durationStr = $att->duration_minutes ? round($att->duration_minutes) . ' min' : 'NULL';
        $percentageStr = $statusData['percentage'] !== null ? $statusData['percentage'] . '%' : '-';

        // Emoji pour le statut
        $emoji = match($statusData['level']) {
            'present' => '✅',
            'partial' => '🟡',
            'low' => '🟠',
            'absent' => '❌',
            'ongoing' => '🔵',
            default => '⚪',
        };

        printf("%-25s %-15s %-15s %s %s\n",
            substr($att->nom, 0, 25),
            $durationStr,
            $percentageStr,
            $emoji,
            $statusData['status']
        );
    }

    echo str_repeat('─', 100) . "\n\n";
}

echo "═══════════════════════════════════════════════════════════════════\n";
echo "LÉGENDE DES STATUTS:\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "✅ Présent  : ≥ 80% de la durée de la séance\n";
echo "🟡 Partiel  : 50-79% de la durée de la séance\n";
echo "🟠 Faible   : 20-49% de la durée de la séance\n";
echo "❌ Absent   : < 20% de la durée de la séance\n";
echo "🔵 En cours : Séance non terminée (visio_ended_at = NULL)\n";
echo "═══════════════════════════════════════════════════════════════════\n";
