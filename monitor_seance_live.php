<?php

/**
 * Monitoring LIVE d'une séance en cours
 * Rafraîchit toutes les 3 secondes pour voir les heartbeats en temps réel
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Seance;
use App\Models\ESBTPAttendance;
use Carbon\Carbon;

// Trouver la séance active la plus récente
$seance = Seance::where('visio_active', true)
    ->whereNotNull('visio_started_at')
    ->whereNull('visio_ended_at')
    ->orderBy('visio_started_at', 'desc')
    ->first();

if (!$seance) {
    echo "❌ Aucune séance active trouvée\n";
    echo "\nCréez une séance et démarrez-la d'abord.\n";
    exit(1);
}

echo "🔴 MONITORING LIVE - Séance #{$seance->id} (KLASSCI #{$seance->klassci_seance_id})\n";
echo "Appuyez sur Ctrl+C pour arrêter\n";
echo str_repeat('═', 100) . "\n\n";

$iteration = 0;

while (true) {
    $iteration++;
    $now = Carbon::now();

    // Recharger la séance
    $seance->refresh();

    // Clear screen (Windows)
    echo "\033[2J\033[H";

    echo "🔴 MONITORING LIVE - Itération #$iteration - " . $now->format('H:i:s') . "\n";
    echo str_repeat('═', 100) . "\n\n";

    // Info séance
    echo "📊 SÉANCE #{$seance->id} (KLASSCI #{$seance->klassci_seance_id})\n";
    echo "   Démarrée : " . $seance->visio_started_at->format('H:i:s') . "\n";
    echo "   Statut   : " . ($seance->visio_active ? '🟢 ACTIVE' : '🔴 TERMINÉE') . "\n";

    if ($seance->visio_ended_at) {
        echo "   Terminée : " . $seance->visio_ended_at->format('H:i:s') . "\n";
    }

    echo "\n" . str_repeat('─', 100) . "\n\n";

    // Récupérer les participants
    $attendances = ESBTPAttendance::where('seance_id', $seance->id)
        ->with('user')
        ->orderBy('joined_at')
        ->get();

    if ($attendances->isEmpty()) {
        echo "⚠️  Aucun participant\n\n";
    } else {
        echo "👥 PARTICIPANTS ({$attendances->count()})\n\n";

        foreach ($attendances as $att) {
            $userName = $att->user ? $att->user->name : "User #{$att->user_id}";
            $role = $att->user ? $att->user->role : 'N/A';

            // Statut avec emoji
            $statusEmoji = $att->status === 'connected' ? '🟢' : '🔴';

            // Calculer le temps depuis le dernier heartbeat
            $heartbeatAge = null;
            $heartbeatWarning = '';
            if ($att->last_seen_at) {
                $heartbeatAge = $att->last_seen_at->diffInSeconds($now);
                if ($heartbeatAge > 60) {
                    $heartbeatWarning = ' ⚠️  INACTIF';
                } elseif ($heartbeatAge > 35) {
                    $heartbeatWarning = ' ⚡ Bientôt inactif';
                }
            }

            echo "$statusEmoji $userName ($role)\n";
            echo "   Rejoint     : " . ($att->joined_at ? $att->joined_at->format('H:i:s') : 'N/A') . "\n";

            if ($att->status === 'disconnected') {
                echo "   Quitté      : " . ($att->left_at ? $att->left_at->format('H:i:s') : 'N/A') . "\n";
                echo "   Durée       : " . ($att->duration_minutes ?? 0) . " min\n";
            } else {
                echo "   Heartbeat   : ";
                if ($att->last_seen_at) {
                    echo $att->last_seen_at->format('H:i:s') . " (il y a {$heartbeatAge}s){$heartbeatWarning}\n";
                } else {
                    echo "JAMAIS ❌\n";
                }
            }

            echo "\n";
        }
    }

    echo str_repeat('─', 100) . "\n\n";

    // Statistiques
    $connected = $attendances->where('status', 'connected')->count();
    $disconnected = $attendances->where('status', 'disconnected')->count();

    echo "📈 STATISTIQUES\n";
    echo "   🟢 Connectés    : $connected\n";
    echo "   🔴 Déconnectés  : $disconnected\n";
    echo "   📊 Total        : " . $attendances->count() . "\n";

    echo "\n" . str_repeat('═', 100) . "\n";
    echo "⏱️  Prochaine mise à jour dans 3 secondes... (Ctrl+C pour arrêter)\n";

    sleep(3);
}
