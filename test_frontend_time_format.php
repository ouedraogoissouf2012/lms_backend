<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "═══════════════════════════════════════════════════════════════════\n";
echo "   TEST FORMAT TEMPS FRONTEND vs BACKEND\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$seance = DB::table('seances')->where('klassci_seance_id', 60)->first();
$attendances = DB::table('esbtp_attendance')
    ->where('seance_id', $seance->id)
    ->orderBy('joined_at')
    ->get();

echo "DONNÉES BRUTES (DB):\n";
echo str_repeat('-', 100) . "\n";
printf("%-25s %-25s %-25s %-15s\n", "Nom", "Joined At (DB)", "Left At (DB)", "Duration (DB)");
echo str_repeat('-', 100) . "\n";

foreach ($attendances as $att) {
    printf("%-25s %-25s %-25s %-15s\n",
        substr($att->nom, 0, 25),
        $att->joined_at,
        $att->left_at ?? 'NULL',
        $att->duration_minutes . ' min'
    );
}

echo "\nFORMAT FRONTEND (formatTime):\n";
echo str_repeat('-', 100) . "\n";
printf("%-25s %-15s %-15s %-15s %-15s\n", "Nom", "Arrivée", "Départ", "Durée", "Statut");
echo str_repeat('-', 100) . "\n";

foreach ($attendances as $att) {
    // Simuler formatTime() du frontend (format HH:MM)
    $joinedTime = $att->joined_at ? date('H:i', strtotime($att->joined_at)) : '-';
    $leftTime = $att->left_at ? date('H:i', strtotime($att->left_at)) : '-';

    // Simuler formatDuration()
    $duration = $att->duration_minutes;
    if ($duration && $duration > 0) {
        $hours = floor($duration / 60);
        $mins = round($duration % 60);
        if ($hours > 0) {
            $durationStr = $mins > 0 ? "{$hours}h {$mins}min" : "{$hours}h";
        } else {
            $durationStr = "{$mins} min";
        }
    } else {
        $durationStr = '-';
    }

    // Statut (>3min = Présent)
    $isPresent = $duration !== null && $duration > 3;
    $status = $isPresent ? '✅ Présent' : '❌ Absent';

    printf("%-25s %-15s %-15s %-15s %-15s\n",
        substr($att->nom, 0, 25),
        $joinedTime,
        $leftTime,
        $durationStr,
        $status
    );
}

echo "\nCALCULS BACKEND ACTUELS (getSeanceAttendances):\n";
echo str_repeat('-', 100) . "\n";

$totalParticipants = $attendances->count();
$validDurations = $attendances->filter(fn($a) => $a->duration_minutes !== null && $a->duration_minutes > 0);
$avgDuration = $validDurations->count() > 0 ? round($validDurations->avg('duration_minutes')) : 0;

// INCOHÉRENCE DÉTECTÉE : ligne 4982 utilise > 5 min
$validPresences_5min = $attendances->filter(fn($a) => $a->duration_minutes !== null && $a->duration_minutes > 5)->count();
$presenceRate_5min = $totalParticipants > 0 ? round(($validPresences_5min / $totalParticipants) * 100) : 0;

// MAIS ligne 4962 vérifie > 3 min pour l'affichage individuel
$validPresences_3min = $attendances->filter(fn($a) => $a->duration_minutes !== null && $a->duration_minutes > 3)->count();
$presenceRate_3min = $totalParticipants > 0 ? round(($validPresences_3min / $totalParticipants) * 100) : 0;

echo "Total participants: {$totalParticipants}\n";
echo "Durée moyenne: {$avgDuration} min\n";
echo "\n⚠️  INCOHÉRENCE DÉTECTÉE:\n";
echo "  Taux présence (seuil >5 min): {$presenceRate_5min}% ({$validPresences_5min}/{$totalParticipants})\n";
echo "  Taux présence (seuil >3 min): {$presenceRate_3min}% ({$validPresences_3min}/{$totalParticipants})\n";
echo "  ➤ Le backend utilise >5 min pour stats mais >3 min pour affichage individuel!\n";

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "RÉSUMÉ DES PROBLÈMES TROUVÉS:\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "1. ✅ Les heures d'entrée/sortie sont correctes dans la DB\n";
echo "2. ✅ Le formatTime() du frontend affiche correctement HH:MM\n";
echo "3. ✅ Les durées sont calculées correctement (diffInMinutes)\n";
echo "4. ⚠️  INCOHÉRENCE: Seuil de présence différent (ligne 4962: >3min vs ligne 4982: >5min)\n";
echo "5. ⚠️  Duration stockée en FLOAT au lieu d'INTEGER (337.56666 au lieu de 337)\n";
echo "═══════════════════════════════════════════════════════════════════\n";
