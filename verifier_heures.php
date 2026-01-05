<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Seance;
use App\Models\ESBTPAttendance;

// Trouver la dernière séance fermée
$seance = Seance::where('visio_active', false)
    ->orderBy('visio_ended_at', 'desc')
    ->first();

if (!$seance) {
    echo "❌ Aucune séance terminée trouvée\n";
    exit;
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "   VÉRIFICATION DES HEURES DE DÉPART\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

echo "📊 Séance #{$seance->id} (KLASSCI #{$seance->klassci_seance_id})\n";
echo "   Démarrée  : " . $seance->visio_started_at->format('H:i:s') . "\n";
echo "   Terminée  : " . $seance->visio_ended_at->format('H:i:s') . " ← Heure où vous avez cliqué 'Terminer'\n\n";

echo "───────────────────────────────────────────────────────────────\n\n";

$attendances = ESBTPAttendance::where('seance_id', $seance->id)
    ->with('user')
    ->orderBy('left_at')
    ->get();

echo "👥 PARTICIPANTS ET LEURS HEURES DE DÉPART\n\n";

$prevLeftAt = null;
$allSameTime = true;

foreach ($attendances as $att) {
    $userName = $att->user ? $att->user->name : "User #{$att->user_id}";
    $role = $att->user ? $att->user->role : 'N/A';

    $leftAt = $att->left_at ? $att->left_at->format('H:i:s') : 'N/A';
    $lastSeen = $att->last_seen_at ? $att->last_seen_at->format('H:i:s') : 'N/A';

    // Vérifier si tous ont la même heure
    if ($prevLeftAt && $att->left_at) {
        if ($prevLeftAt->format('H:i:s') !== $att->left_at->format('H:i:s')) {
            $allSameTime = false;
        }
    }
    $prevLeftAt = $att->left_at;

    $roleEmoji = $role === 'enseignant' || $role === 'teacher' ? '👨‍🏫' : '👨‍🎓';

    echo "$roleEmoji $userName ($role)\n";
    echo "   Rejoint        : " . ($att->joined_at ? $att->joined_at->format('H:i:s') : 'N/A') . "\n";
    echo "   Quitté         : $leftAt";

    // Comparer avec last_seen_at
    if ($att->last_seen_at && $att->left_at) {
        $diff = $att->left_at->diffInSeconds($att->last_seen_at);
        if ($diff < 5) {
            echo " ✅ (utilise last_seen_at)\n";
        } else {
            echo " ⚠️  (différence de {$diff}s avec last_seen_at)\n";
        }
    } else {
        echo "\n";
    }

    echo "   Last heartbeat : $lastSeen\n";
    echo "   Durée          : " . ($att->duration_minutes ?? 0) . " min\n\n";
}

echo "───────────────────────────────────────────────────────────────\n\n";

// Verdict
echo "🎯 VERDICT :\n\n";

if ($allSameTime) {
    echo "❌ PROBLÈME : Tous les participants ont la MÊME heure de départ\n";
    echo "   → La correction n'a PAS fonctionné\n";
    echo "   → Les participants devraient avoir des heures différentes\n\n";
} else {
    echo "✅ SUCCÈS : Les participants ont des heures de départ DIFFÉRENTES\n";
    echo "   → La correction fonctionne !\n";
    echo "   → Chaque participant a son heure réelle (last_seen_at)\n\n";
}

// Vérifier si les left_at correspondent aux last_seen_at
$allUsingHeartbeat = true;
foreach ($attendances as $att) {
    if ($att->last_seen_at && $att->left_at) {
        $diff = abs($att->left_at->diffInSeconds($att->last_seen_at));
        if ($diff > 5) {
            $allUsingHeartbeat = false;
            break;
        }
    }
}

if ($allUsingHeartbeat) {
    echo "✅ BONUS : Toutes les heures de départ utilisent last_seen_at (heartbeat)\n";
    echo "   → Précision à 30 secondes près !\n\n";
} else {
    echo "⚠️  ATTENTION : Certaines heures n'utilisent pas last_seen_at\n";
    echo "   → Vérifier que les heartbeats fonctionnent correctement\n\n";
}

echo "═══════════════════════════════════════════════════════════════\n";
