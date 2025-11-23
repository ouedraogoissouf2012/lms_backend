<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ESBTPAttendance;
use App\Models\User;

echo "=== TEST BEACON API - Détection fermeture ===\n\n";

// Trouver Marcel
$marcel = User::where('name', 'LIKE', '%MARCEL%')->first();

if (!$marcel) {
    echo "❌ Marcel non trouvé\n";
    exit;
}

echo "👤 Marcel trouvé : ID = {$marcel->id}\n\n";

// Trouver sa dernière participation
$lastParticipation = ESBTPAttendance::where('user_id', $marcel->id)
    ->orderBy('joined_at', 'desc')
    ->first();

if (!$lastParticipation) {
    echo "❌ Aucune participation trouvée\n";
    exit;
}

echo "📊 Dernière participation:\n";
echo "   Status: {$lastParticipation->status}\n";
echo "   Joined at: " . $lastParticipation->joined_at->format('H:i:s') . "\n";
echo "   Last seen at: " . ($lastParticipation->last_seen_at ? $lastParticipation->last_seen_at->format('H:i:s') : 'NULL') . "\n";
echo "   Left at: " . ($lastParticipation->left_at ? $lastParticipation->left_at->format('H:i:s') : 'En cours') . "\n\n";

// Test 1 : Vérifier si left_at a été rempli
if ($lastParticipation->status === 'connected' && !$lastParticipation->left_at) {
    echo "✅ TEST 1 : Marcel est toujours connecté\n";
    echo "   → Attendez qu'il ferme son onglet/navigateur\n";
    echo "   → Le Beacon API devrait envoyer leaveVisio\n";
    echo "   → Réexécutez ce script après pour voir si left_at est rempli\n\n";
} else if ($lastParticipation->left_at) {
    $duration = $lastParticipation->joined_at->diffInMinutes($lastParticipation->left_at);
    echo "✅ TEST 1 : left_at est rempli !\n";
    echo "   → Durée totale : {$duration} minutes\n";
    echo "   → Le Beacon API a fonctionné OU le timeout l'a déconnecté\n\n";
}

// Test 2 : Vérifier si le heartbeat fonctionne
if ($lastParticipation->last_seen_at) {
    $secondsSinceLastHeartbeat = now()->diffInSeconds($lastParticipation->last_seen_at);

    echo "✅ TEST 2 : Heartbeat fonctionne !\n";
    echo "   → Dernier heartbeat : {$secondsSinceLastHeartbeat} secondes ago\n";

    if ($secondsSinceLastHeartbeat < 60) {
        echo "   → ✅ EXCELLENT : Heartbeat récent (< 1 min)\n";
    } else if ($secondsSinceLastHeartbeat < 180) {
        echo "   → ⚠️  Heartbeat en retard (devrait être toutes les 30s)\n";
    } else {
        echo "   → ❌ Heartbeat trop ancien (> 3 min) - Timeout imminent\n";
    }
} else {
    echo "❌ TEST 2 : Aucun heartbeat reçu\n";
    echo "   → Le Web Worker ne fonctionne pas\n";
    echo "   → Vérifiez la console navigateur\n";
}

echo "\n=== INSTRUCTIONS ===\n";
echo "1. Si Marcel est toujours dans la visio :\n";
echo "   → Demandez-lui de fermer son onglet/navigateur\n";
echo "   → Attendez 5 secondes\n";
echo "   → Réexécutez ce script\n\n";

echo "2. Si left_at est rempli :\n";
echo "   → ✅ Le système fonctionne (Beacon API ou timeout)\n\n";

echo "3. Si last_seen_at se met à jour :\n";
echo "   → ✅ Le Web Worker heartbeat fonctionne\n";
echo "   → Marcel ne sera plus déconnecté après 3 minutes\n\n";

echo "=== FIN TEST ===\n";
