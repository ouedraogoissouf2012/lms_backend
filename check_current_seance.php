<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "\n=== ÉTAT DE LA SÉANCE EN COURS ===\n\n";

// Trouver la dernière séance démarrée
$seance = App\Models\Seance::whereNotNull('visio_started_at')
    ->orderBy('visio_started_at', 'desc')
    ->first();

if (!$seance) {
    echo "❌ Aucune séance trouvée\n";
    exit(1);
}

echo "Séance ID: {$seance->id}\n";
echo "KLASSCI ID: {$seance->klassci_seance_id}\n";
echo "Titre: {$seance->titre}\n";
echo "Visio démarrée: {$seance->visio_started_at}\n";
echo "Visio terminée: " . ($seance->visio_ended_at ?? 'EN COURS') . "\n";
echo "Visio active: " . ($seance->visio_active ? 'OUI' : 'NON') . "\n\n";

// Vérifier les participants
$participants = App\Models\ESBTPAttendance::where('seance_id', $seance->id)
    ->orderBy('joined_at', 'desc')
    ->get();

echo "=== PARTICIPANTS ({$participants->count()}) ===\n\n";

foreach ($participants as $p) {
    $user = App\Models\User::find($p->user_id);
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "User: " . ($user ? $user->name : "ID {$p->user_id}") . "\n";
    echo "Status: {$p->status}\n";
    echo "Joined: {$p->joined_at}\n";
    echo "Left: " . ($p->left_at ?? 'NULL') . "\n";
    echo "Last seen: " . ($p->last_seen_at ?? 'NULL') . "\n";

    if ($p->last_seen_at) {
        $minutesInactif = now()->diffInMinutes($p->last_seen_at);
        echo "Inactivité: {$minutesInactif} minutes\n";
    }

    echo "Duration: " . ($p->duration_minutes ?? 'NULL') . " minutes\n";
    echo "\n";
}

// Vérifier le problème
echo "=== DIAGNOSTIC ===\n\n";

$connected = $participants->where('status', 'connected')->count();
$disconnected = $participants->where('status', 'disconnected')->count();

echo "Connectés: {$connected}\n";
echo "Déconnectés: {$disconnected}\n\n";

if ($seance->visio_ended_at && $connected > 0) {
    echo "⚠️  PROBLÈME: La séance est marquée terminée mais il y a {$connected} participant(s) connecté(s)\n";
} elseif ($disconnected > 0 && !$seance->visio_ended_at) {
    echo "⚠️  PROBLÈME: La séance est EN COURS mais {$disconnected} participant(s) sont marqués déconnectés\n";

    // Vérifier si c'est le script de correction qui a causé ça
    $recentDisconnects = $participants->where('status', 'disconnected')
        ->filter(function($p) {
            return $p->left_at && $p->left_at->isAfter(now()->subMinutes(10));
        });

    if ($recentDisconnects->count() > 0) {
        echo "   → {$recentDisconnects->count()} participant(s) déconnecté(s) dans les 10 dernières minutes\n";
        echo "   → Possible cause: Script de correction automatique\n";
    }
}

echo "\n";
