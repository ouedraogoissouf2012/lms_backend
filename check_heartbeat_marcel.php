<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ESBTPAttendance;
use App\Models\User;

echo "=== VÉRIFICATION HEARTBEAT - MARCEL OUEDRAOGO ===\n\n";

// Trouver Marcel
$marcel = User::where('name', 'LIKE', '%MARCEL%')->orWhere('name', 'LIKE', '%OUEDRAOGO%')->first();

if (!$marcel) {
    echo "❌ Marcel non trouvé\n";
    exit;
}

echo "👤 Marcel trouvé : ID = {$marcel->id}, Name = {$marcel->name}\n\n";

// Trouver sa participation la plus récente
$participation = ESBTPAttendance::where('user_id', $marcel->id)
    ->orderBy('joined_at', 'desc')
    ->first();

if (!$participation) {
    echo "❌ Aucune participation trouvée pour Marcel\n";
    exit;
}

echo "📊 Participation la plus récente:\n";
echo "   Séance ID: {$participation->seance_id}\n";
echo "   Status: {$participation->status}\n";
echo "   Joined at: " . ($participation->joined_at ? $participation->joined_at->format('Y-m-d H:i:s') : 'NULL') . "\n";
echo "   Last seen at: " . ($participation->last_seen_at ? $participation->last_seen_at->format('Y-m-d H:i:s') : 'NULL') . "\n";
echo "   Left at: " . ($participation->left_at ? $participation->left_at->format('Y-m-d H:i:s') : 'NULL') . "\n\n";

// Calculer les délais
$now = now();
$joinedMinutesAgo = $participation->joined_at ? $now->diffInMinutes($participation->joined_at) : null;
$lastSeenMinutesAgo = $participation->last_seen_at ? $now->diffInMinutes($participation->last_seen_at) : null;
$lastSeenSecondsAgo = $participation->last_seen_at ? $now->diffInSeconds($participation->last_seen_at) : null;

echo "⏱️  Temps écoulé:\n";
echo "   Depuis joined_at: " . ($joinedMinutesAgo !== null ? "{$joinedMinutesAgo} minutes" : 'N/A') . "\n";
echo "   Depuis last_seen_at: " . ($lastSeenMinutesAgo !== null ? "{$lastSeenMinutesAgo} minutes ({$lastSeenSecondsAgo} secondes)" : 'N/A') . "\n\n";

// Analyser le problème
echo "🔍 DIAGNOSTIC:\n";

if ($participation->status === 'disconnected' && $participation->left_at) {
    echo "   ❌ PROBLÈME DÉTECTÉ : Marcel est marqué comme déconnecté\n";

    if (!$participation->last_seen_at) {
        echo "   ⚠️  CAUSE : Aucun heartbeat reçu (last_seen_at = NULL)\n";
        echo "   💡 SOLUTION : Vérifier que le Web Worker envoie bien les heartbeats\n";
    } else {
        echo "   ⚠️  CAUSE : Dernier heartbeat il y a {$lastSeenMinutesAgo} minutes\n";

        if ($lastSeenMinutesAgo > 3) {
            echo "   💡 Le timeout a fonctionné correctement (> 3 min sans heartbeat)\n";
            echo "   💡 SOLUTION : Vérifier pourquoi les heartbeats s'arrêtent\n";
        } else {
            echo "   ⚠️  ANOMALIE : Heartbeat récent mais quand même déconnecté!\n";
            echo "   💡 Peut-être déconnecté manuellement ou par un autre processus\n";
        }
    }
} else if ($participation->status === 'connected') {
    echo "   ✅ Marcel est connecté\n";

    if (!$participation->last_seen_at) {
        $timeSinceJoined = $joinedMinutesAgo;
        echo "   ⚠️  Aucun heartbeat reçu depuis la connexion ({$timeSinceJoined} minutes)\n";

        if ($timeSinceJoined > 3) {
            echo "   ⚠️  ATTENTION : Devrait être déconnecté par timeout!\n";
            echo "   💡 Le timeout n'a pas encore été exécuté\n";
        } else {
            echo "   ✅ OK : Connecté récemment, heartbeat attendu dans les 30s\n";
        }
    } else {
        echo "   ✅ Heartbeat reçu il y a {$lastSeenSecondsAgo} secondes\n";

        if ($lastSeenSecondsAgo > 180) {
            echo "   ⚠️  ATTENTION : Plus de 3 minutes sans heartbeat!\n";
            echo "   💡 Devrait être déconnecté au prochain appel de getVisioParticipants()\n";
        } else if ($lastSeenSecondsAgo > 90) {
            echo "   ⚠️  Heartbeat en retard (devrait être toutes les 30s)\n";
        } else {
            echo "   ✅ Heartbeat OK (moins de 90s)\n";
        }
    }
}

// Vérifier toutes les participations de Marcel
echo "\n\n📜 Historique des participations de Marcel (dernières 10):\n";
$allParticipations = ESBTPAttendance::where('user_id', $marcel->id)
    ->orderBy('joined_at', 'desc')
    ->limit(10)
    ->get();

foreach ($allParticipations as $p) {
    $duration = null;
    if ($p->joined_at && $p->left_at) {
        $duration = $p->joined_at->diffInMinutes($p->left_at);
    }

    echo sprintf(
        "   %s | Séance %d | %s → %s | Durée: %s | last_seen: %s\n",
        $p->status === 'connected' ? '🟢' : '🔴',
        $p->seance_id,
        $p->joined_at ? $p->joined_at->format('H:i:s') : 'NULL',
        $p->left_at ? $p->left_at->format('H:i:s') : 'actif',
        $duration !== null ? "{$duration}min" : 'N/A',
        $p->last_seen_at ? $p->last_seen_at->format('H:i:s') : 'NULL'
    );
}

echo "\n=== FIN DIAGNOSTIC ===\n";
