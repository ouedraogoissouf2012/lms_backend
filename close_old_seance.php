<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "═══════════════════════════════════════════════════════════════════\n";
echo "   FERMETURE AUTOMATIQUE SÉANCE #61\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$seance = DB::table('seances')->where('klassci_seance_id', 61)->first();

echo "AVANT:\n";
echo "  visio_started_at: {$seance->visio_started_at}\n";
echo "  visio_ended_at: " . ($seance->visio_ended_at ?? 'NULL') . "\n\n";

// Trouver le dernier left_at des participants
$lastLeft = DB::table('esbtp_attendance')
    ->where('seance_id', $seance->id)
    ->whereNotNull('left_at')
    ->orderBy('left_at', 'desc')
    ->first();

if ($lastLeft) {
    $endTime = $lastLeft->left_at;

    echo "Dernier participant sorti: {$lastLeft->nom}\n";
    echo "Heure de sortie: {$endTime}\n\n";

    // Mettre à jour visio_ended_at
    DB::table('seances')
        ->where('id', $seance->id)
        ->update(['visio_ended_at' => $endTime]);

    echo "✅ Séance fermée automatiquement\n\n";

    // Calculer la durée de la séance
    $start = new DateTime($seance->visio_started_at);
    $end = new DateTime($endTime);
    $diff = $start->diff($end);
    $totalMinutes = ($diff->h * 60) + $diff->i + ($diff->days * 24 * 60);

    echo "APRÈS:\n";
    echo "  visio_started_at: {$seance->visio_started_at}\n";
    echo "  visio_ended_at: {$endTime}\n";
    echo "  Durée totale: {$totalMinutes} minutes ({$diff->h}h {$diff->i}min)\n\n";

    // Afficher les nouveaux statuts
    echo "═══════════════════════════════════════════════════════════════════\n";
    echo "NOUVEAUX STATUTS (basés sur % de participation):\n";
    echo "═══════════════════════════════════════════════════════════════════\n\n";

    $attendances = DB::table('esbtp_attendance')
        ->leftJoin('users', 'esbtp_attendance.user_id', '=', 'users.id')
        ->where('esbtp_attendance.seance_id', $seance->id)
        ->whereIn('users.role', ['etudiant'])
        ->select('esbtp_attendance.*', 'users.role')
        ->get();

    foreach ($attendances as $att) {
        if ($att->duration_minutes && $totalMinutes > 0) {
            $percentage = ($att->duration_minutes / $totalMinutes) * 100;

            if ($percentage >= 80) {
                $status = '✅ Présent';
                $color = 'vert';
            } elseif ($percentage >= 50) {
                $status = '🟡 Partiel';
                $color = 'orange';
            } elseif ($percentage >= 20) {
                $status = '🟠 Faible';
                $color = 'rouge clair';
            } else {
                $status = '❌ Absent';
                $color = 'rouge';
            }

            printf("%-25s %4.1f%% (%d min / %d min) → %s (%s)\n",
                $att->nom,
                $percentage,
                round($att->duration_minutes),
                $totalMinutes,
                $status,
                $color
            );
        }
    }

    echo "\n═══════════════════════════════════════════════════════════════════\n";
    echo "✅ MAINTENANT, rafraîchis la page pour voir les vrais statuts!\n";
    echo "═══════════════════════════════════════════════════════════════════\n";

} else {
    echo "❌ Aucun participant avec left_at trouvé\n";
}
