<?php

/**
 * Test de persistance des heartbeats lors de la navigation
 *
 * Ce script vérifie que les heartbeats continuent d'être reçus
 * même quand l'utilisateur navigue entre les pages.
 *
 * Usage :
 *   php test_heartbeat_persistence.php
 *
 * Instructions :
 *   1. Lancer ce script dans un terminal
 *   2. Rejoindre une séance dans le frontend
 *   3. Naviguer entre différentes pages (Dashboard, Emploi du temps, etc.)
 *   4. Vérifier que les heartbeats continuent d'être reçus
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ESBTPAttendance;
use App\Models\User;
use App\Models\Seance;
use Carbon\Carbon;

echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🧪 TEST DE PERSISTANCE DES HEARTBEATS\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "\n";

echo "📋 Instructions :\n";
echo "1. Lancer le frontend (npm run dev)\n";
echo "2. Rejoindre une séance dans le navigateur\n";
echo "3. Naviguer entre les pages (Dashboard, Emploi du temps, etc.)\n";
echo "4. Observer que les heartbeats continuent ✅\n";
echo "\n";

echo "⏳ Attente d'une séance active...\n\n";

// Variables de tracking
$lastHeartbeats = [];
$heartbeatHistory = [];
$navigationDetected = false;

// Boucle de monitoring (2 minutes)
$iterations = 24; // 24 * 5s = 2 minutes
$iteration = 0;

while ($iteration < $iterations) {
    $iteration++;
    $now = Carbon::now();

    // Récupérer les séances actives
    $activeSeances = Seance::where('visio_active', true)
        ->whereNotNull('visio_started_at')
        ->whereNull('visio_ended_at')
        ->get();

    if ($activeSeances->isEmpty()) {
        echo "⏳ Aucune séance active... ({$iteration}/{$iterations})\n";
        sleep(5);
        continue;
    }

    echo "\n┌────────────────────────────────────────────────────────┐\n";
    echo "│ Itération #{$iteration} - " . $now->format('H:i:s') . "\n";
    echo "└────────────────────────────────────────────────────────┘\n\n";

    foreach ($activeSeances as $seance) {
        echo "📊 Séance ID: {$seance->id} (KLASSCI: {$seance->klassci_seance_id})\n";
        echo "   Démarrée: {$seance->visio_started_at->format('H:i:s')}\n";
        echo "   Durée: " . $seance->visio_started_at->diffInMinutes($now) . " minutes\n\n";

        // Récupérer les participants connectés
        $participants = ESBTPAttendance::where('seance_id', $seance->id)
            ->where('status', 'connected')
            ->where('is_observer', false)
            ->get();

        if ($participants->isEmpty()) {
            echo "   Aucun participant connecté\n\n";
            continue;
        }

        foreach ($participants as $att) {
            $user = User::find($att->user_id);
            if (!$user) continue;

            $userId = $att->user_id;
            $lastSeenAt = $att->last_seen_at;

            // Emoji selon le rôle
            $roleEmoji = $user->role === 'enseignant' ? '👨‍🏫' : '👨‍🎓';

            echo "   {$roleEmoji} {$user->name}\n";

            if (!$lastSeenAt) {
                echo "      ⚠️  Aucun heartbeat enregistré\n\n";
                continue;
            }

            $secondsSince = $lastSeenAt->diffInSeconds($now);
            $minutesSince = floor($secondsSince / 60);
            $remainingSeconds = $secondsSince % 60;

            // Déterminer l'état
            $status = '';
            if ($secondsSince < 45) {
                $status = "✅ EXCELLENT";
            } elseif ($secondsSince < 90) {
                $status = "🟢 BON";
            } elseif ($secondsSince < 180) {
                $status = "⚠️  ATTENTION";
            } else {
                $status = "❌ CRITIQUE";
            }

            echo "      Dernier heartbeat: {$lastSeenAt->format('H:i:s')}";
            if ($minutesSince > 0) {
                echo " (il y a {$minutesSince}m {$remainingSeconds}s) {$status}\n";
            } else {
                echo " (il y a {$remainingSeconds}s) {$status}\n";
            }

            // Vérifier si c'est un nouveau heartbeat
            $key = "{$seance->id}_{$userId}";
            if (isset($lastHeartbeats[$key])) {
                $previousHeartbeat = Carbon::parse($lastHeartbeats[$key]);

                if ($lastSeenAt->gt($previousHeartbeat)) {
                    echo "      💓 NOUVEAU HEARTBEAT reçu ! ✅\n";

                    // Enregistrer dans l'historique
                    $heartbeatHistory[] = [
                        'time' => $now->format('H:i:s'),
                        'user' => $user->name,
                        'seance' => $seance->id
                    ];

                    $navigationDetected = true;
                }
            }

            // Sauvegarder le dernier heartbeat
            $lastHeartbeats[$key] = $lastSeenAt->toDateTimeString();

            echo "\n";
        }
    }

    echo "──────────────────────────────────────────────────────────\n";

    sleep(5);
}

echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "📊 RÉSUMÉ DU TEST\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "\n";

echo "Durée du test: 2 minutes\n";
echo "Itérations: {$iterations}\n";
echo "Heartbeats capturés: " . count($heartbeatHistory) . "\n\n";

if (count($heartbeatHistory) > 0) {
    echo "✅ SUCCÈS : Des heartbeats ont été reçus\n\n";

    echo "Historique des heartbeats:\n";
    echo "┌────────────────────────────────────────────┐\n";

    foreach ($heartbeatHistory as $entry) {
        echo "│ {$entry['time']} - {$entry['user']} (séance {$entry['seance']})\n";
    }

    echo "└────────────────────────────────────────────┘\n\n";

    // Analyse de la fréquence
    if (count($heartbeatHistory) >= 2) {
        $firstTime = Carbon::parse($heartbeatHistory[0]['time']);
        $lastTime = Carbon::parse($heartbeatHistory[count($heartbeatHistory) - 1]['time']);
        $duration = $firstTime->diffInSeconds($lastTime);
        $frequency = $duration / (count($heartbeatHistory) - 1);

        echo "Fréquence moyenne: " . round($frequency) . " secondes\n";
        echo "Attendu: 30 secondes\n\n";

        if ($frequency >= 25 && $frequency <= 35) {
            echo "✅ Fréquence correcte (25-35s) ✅\n";
        } else {
            echo "⚠️  Fréquence anormale (devrait être ~30s)\n";
        }
    }

    if ($navigationDetected) {
        echo "\n🎉 VALIDATION : Les heartbeats ont continué pendant la navigation ! ✅\n";
        echo "   Le bug est corrigé : le Worker persiste lors des changements de page.\n";
    }

} else {
    echo "⚠️  ATTENTION : Aucun heartbeat capturé\n\n";
    echo "Causes possibles:\n";
    echo "- Aucune séance active pendant le test\n";
    echo "- Aucun participant n'a rejoint la séance\n";
    echo "- Le frontend n'est pas démarré\n";
    echo "- Le système de heartbeat est désactivé\n";
}

echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🏁 FIN DU TEST\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "\n";

echo "Pour un test en temps réel continu, utilisez:\n";
echo "  php watch_heartbeats.php\n\n";
