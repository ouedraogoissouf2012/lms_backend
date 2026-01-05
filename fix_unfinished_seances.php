<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Seance;
use App\Models\ESBTPAttendance;
use Carbon\Carbon;

echo "\n========================================\n";
echo "CORRECTION DES SÉANCES NON TERMINÉES\n";
echo "========================================\n\n";

// 1. Trouver toutes les séances avec visio démarrée mais pas terminée
$seancesNonTerminees = Seance::whereNotNull('visio_started_at')
    ->whereNull('visio_ended_at')
    ->get();

echo "Séances non terminées trouvées : " . $seancesNonTerminees->count() . "\n\n";

foreach ($seancesNonTerminees as $seance) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Séance ID {$seance->id} - KLASSCI ID {$seance->klassci_seance_id}\n";
    echo "Titre: {$seance->titre}\n";
    echo "Démarrée: {$seance->visio_started_at}\n";
    echo "Durée écoulée: " . $seance->visio_started_at->diffInMinutes(now()) . " minutes\n";

    // Trouver le dernier heartbeat de n'importe quel participant
    $dernierHeartbeat = ESBTPAttendance::where('seance_id', $seance->id)
        ->whereNotNull('last_seen_at')
        ->orderBy('last_seen_at', 'desc')
        ->first();

    $visioEndedAt = null;

    if ($dernierHeartbeat && $dernierHeartbeat->last_seen_at) {
        // Utiliser le dernier heartbeat + 5 minutes comme heure de fin
        $visioEndedAt = $dernierHeartbeat->last_seen_at->copy()->addMinutes(5);
        echo "Dernier heartbeat: {$dernierHeartbeat->last_seen_at}\n";
        echo "Heure de fin proposée: {$visioEndedAt} (dernier heartbeat + 5 min)\n";
    } else {
        // Pas de heartbeat, utiliser started_at + 30 minutes par défaut
        $visioEndedAt = $seance->visio_started_at->copy()->addMinutes(30);
        echo "⚠️  Aucun heartbeat trouvé\n";
        echo "Heure de fin proposée: {$visioEndedAt} (début + 30 min par défaut)\n";
    }

    // Terminer la séance
    $seance->visio_ended_at = $visioEndedAt;
    $seance->visio_active = false;
    $seance->save();

    echo "✅ Séance terminée\n";

    // Finaliser les présences des participants encore connectés
    $participantsActifs = ESBTPAttendance::where('seance_id', $seance->id)
        ->where('status', 'connected')
        ->get();

    if ($participantsActifs->count() > 0) {
        echo "\nFinalisation de {$participantsActifs->count()} participant(s) encore connecté(s)...\n";

        foreach ($participantsActifs as $attendance) {
            // Utiliser last_seen_at ou visio_ended_at
            $leftAt = $attendance->last_seen_at ?? $visioEndedAt;

            // S'assurer que left_at n'est pas après visio_ended_at
            if ($leftAt->gt($visioEndedAt)) {
                $leftAt = $visioEndedAt;
            }

            $attendance->status = 'disconnected';
            $attendance->left_at = $leftAt;

            if ($attendance->joined_at) {
                $attendance->duration_minutes = $attendance->joined_at->diffInMinutes($leftAt);
            }

            $attendance->save();

            echo "  - User ID {$attendance->user_id}: {$attendance->duration_minutes} minutes\n";
        }

        echo "✅ Participants finalisés\n";
    } else {
        echo "ℹ️  Aucun participant actif à finaliser\n";
    }

    echo "\n";
}

echo "========================================\n";
echo "✅ CORRECTION TERMINÉE\n";
echo "========================================\n\n";

echo "PROCHAINES ÉTAPES:\n";
echo "1. Lancez le scheduler Laravel pour éviter que ça se reproduise:\n";
echo "   → Dans un terminal: php artisan schedule:work\n\n";
echo "2. Vérifiez que le scheduler tourne:\n";
echo "   → Les jobs s'exécuteront automatiquement toutes les 2-10 minutes\n\n";
