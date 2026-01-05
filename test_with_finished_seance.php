<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "═══════════════════════════════════════════════════════════════════\n";
echo "   TEST AVEC SÉANCE TERMINÉE (SIMULATION)\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// Fonction pour calculer le statut
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

// Prendre la séance #60 et SIMULER sa fermeture
$seance = DB::table('seances')->where('klassci_seance_id', 60)->first();

echo "SÉANCE #60 - {$seance->matiere_nom}\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// Simuler la fin de séance: visio_started_at + 6 heures (360 minutes)
$start = new DateTime($seance->visio_started_at);
$simulatedEnd = clone $start;
$simulatedEnd->modify('+6 hours');

$seanceDurationMinutes = 360; // 6 heures

echo "SIMULATION:\n";
echo "  Début réel: {$seance->visio_started_at}\n";
echo "  Fin simulée: " . $simulatedEnd->format('Y-m-d H:i:s') . "\n";
echo "  Durée simulée: {$seanceDurationMinutes} minutes (6 heures)\n";
echo "  Enseignant: LOSSENI KABIROU COULIBALY\n\n";

// Récupérer les participations
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
    ->orderBy('duration_minutes', 'desc')
    ->get();

echo "📋 LISTE DES PARTICIPANTS (comme dans le modal):\n";
echo str_repeat('─', 120) . "\n";
printf("%-25s %-12s %-15s %-20s\n", "Nom", "Durée", "Participation", "Statut");
echo str_repeat('─', 120) . "\n";

foreach ($attendances as $att) {
    $statusData = calculateAttendanceStatus(
        $att->duration_minutes,
        $seanceDurationMinutes,
        true // Séance terminée (simulée)
    );

    $durationStr = $att->duration_minutes ? round($att->duration_minutes) . ' min' : 'NULL';
    $percentageStr = $statusData['percentage'] !== null ? $statusData['percentage'] . '%' : '-';

    // Emoji et couleur pour le statut
    $emoji = match($statusData['level']) {
        'present' => '✅',
        'partial' => '🟡',
        'low' => '🟠',
        'absent' => '❌',
        default => '⚪',
    };

    printf("%-25s %-12s %-15s %s %s\n",
        substr($att->nom, 0, 25),
        $durationStr,
        $percentageStr,
        $emoji,
        $statusData['status']
    );
}

echo str_repeat('─', 120) . "\n\n";

// Calculer les statistiques
$totalParticipants = $attendances->count();
$validDurations = $attendances->filter(fn($a) => $a->duration_minutes !== null && $a->duration_minutes > 0);
$avgDuration = $validDurations->count() > 0 ? round($validDurations->avg('duration_minutes')) : 0;

// Compter les présents (≥80%)
$presents = $attendances->filter(function($a) use ($seanceDurationMinutes) {
    if (!$a->duration_minutes || !$seanceDurationMinutes) return false;
    $percentage = ($a->duration_minutes / $seanceDurationMinutes) * 100;
    return $percentage >= 80;
})->count();

$presenceRate = $totalParticipants > 0 ? round(($presents / $totalParticipants) * 100) : 0;

echo "STATISTIQUES:\n";
echo "  Total participants: {$totalParticipants}\n";
echo "  Durée moyenne: {$avgDuration} min\n";
echo "  Présents (≥80%): {$presents}\n";
echo "  Taux de présence: {$presenceRate}%\n\n";

echo "═══════════════════════════════════════════════════════════════════\n";
echo "EXPLICATION DES RÉSULTATS:\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "Séance de 6h (360 min):\n";
echo "  • MARCEL (338 min) = 93.9% → ✅ Présent (≥80%)\n";
echo "  • Issouf (330 min) = 91.7% → ✅ Présent (≥80%)\n";
echo "  • Drissa (238 min) = 66.1% → 🟡 Partiel (50-79%)\n";
echo "  • BEDE (10 min)    = 2.8%  → ❌ Absent (<20%)\n";
echo "═══════════════════════════════════════════════════════════════════\n";
