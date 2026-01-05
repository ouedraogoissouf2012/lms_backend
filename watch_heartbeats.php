<?php

/**
 * Script de monitoring des heartbeats en temps réel
 *
 * Utilisation :
 *   php watch_heartbeats.php
 *
 * Affiche les participants connectés et leurs derniers heartbeats
 * Se rafraîchit automatiquement toutes les 5 secondes
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ESBTPAttendance;
use App\Models\User;
use App\Models\Seance;
use Carbon\Carbon;

// Fonction pour effacer l'écran
function clearScreen() {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        system('cls');
    } else {
        system('clear');
    }
}

// Fonction pour colorer le texte
function colorText($text, $status) {
    // Pas de couleur sur Windows par défaut
    return $text;
}

// Boucle de monitoring
$iteration = 0;

while (true) {
    clearScreen();

    $now = Carbon::now();

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "🔍 MONITORING HEARTBEATS EN TEMPS RÉEL\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    echo "Heure actuelle : " . $now->format('H:i:s') . "\n";
    echo "Itération : " . (++$iteration) . "\n\n";

    // Récupérer toutes les séances actives
    $activeSeances = Seance::where('visio_active', true)
        ->whereNotNull('visio_started_at')
        ->with('attendances')
        ->get();

    if ($activeSeances->isEmpty()) {
        echo "📭 Aucune séance active pour le moment\n\n";
        echo "En attente de séances actives...\n";
    } else {
        echo "📊 Séances actives : " . $activeSeances->count() . "\n\n";

        foreach ($activeSeances as $seance) {
            echo "┌─────────────────────────────────────────────────────────────┐\n";
            echo "│ SÉANCE ID: {$seance->id} (KLASSCI: {$seance->klassci_seance_id})\n";
            echo "│ Démarrée: {$seance->visio_started_at->format('H:i:s')}\n";
            echo "│ Durée: " . $seance->visio_started_at->diffInMinutes($now) . " minutes\n";
            echo "└─────────────────────────────────────────────────────────────┘\n\n";

            // Récupérer les participants
            $participants = ESBTPAttendance::where('seance_id', $seance->id)
                ->where('is_observer', false)
                ->orderBy('status', 'desc')
                ->orderBy('last_seen_at', 'desc')
                ->get();

            if ($participants->isEmpty()) {
                echo "  Aucun participant\n\n";
                continue;
            }

            foreach ($participants as $att) {
                $user = User::find($att->user_id);

                if (!$user) continue;

                $statusEmoji = $att->status === 'connected' ? '🟢' : '🔴';
                $roleEmoji = $user->role === 'enseignant' ? '👨‍🏫' : '👨‍🎓';

                echo "  {$statusEmoji} {$roleEmoji} {$user->name}\n";
                echo "      Rôle : {$user->role}\n";
                echo "      Status : {$att->status}\n";

                if ($att->joined_at) {
                    echo "      Rejoint : {$att->joined_at->format('H:i:s')}\n";
                }

                if ($att->last_seen_at) {
                    $secondsSince = $att->last_seen_at->diffInSeconds($now);
                    $minutesSince = floor($secondsSince / 60);
                    $remainingSeconds = $secondsSince % 60;

                    $heartbeatStatus = '';
                    if ($att->status === 'connected') {
                        if ($secondsSince < 60) {
                            $heartbeatStatus = "✅ OK";
                        } elseif ($secondsSince < 180) {
                            $heartbeatStatus = "⚠️  ATTENTION";
                        } else {
                            $heartbeatStatus = "❌ CRITIQUE";
                        }
                    }

                    echo "      Dernier heartbeat : {$att->last_seen_at->format('H:i:s')}";
                    if ($minutesSince > 0) {
                        echo " (il y a {$minutesSince}m {$remainingSeconds}s) {$heartbeatStatus}\n";
                    } else {
                        echo " (il y a {$remainingSeconds}s) {$heartbeatStatus}\n";
                    }
                }

                if ($att->left_at) {
                    echo "      Quitté : {$att->left_at->format('H:i:s')}\n";
                    if ($att->duration_minutes) {
                        echo "      Durée : {$att->duration_minutes} minutes\n";
                    }
                }

                echo "\n";
            }
        }
    }

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "🔄 Rafraîchissement automatique dans 5 secondes...\n";
    echo "   Appuyez sur Ctrl+C pour arrêter\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    // Attendre 5 secondes
    sleep(5);
}
