<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ESBTPAttendance;
use App\Models\Seance;

echo "=== DIAGNOSTIC HEARTBEAT ===\n\n";

// 1. Trouver les participations actives
echo "1. Participations actives (status = 'connected'):\n";
$activeParticipations = ESBTPAttendance::where('status', 'connected')
    ->with(['user', 'seance'])
    ->orderBy('joined_at', 'desc')
    ->get();

if ($activeParticipations->isEmpty()) {
    echo "   ❌ Aucune participation active trouvée\n\n";
} else {
    foreach ($activeParticipations as $p) {
        echo sprintf(
            "   ✅ User #%d (%s) - Séance #%d (KLASSCI: %s)\n",
            $p->user_id,
            $p->user->name ?? 'N/A',
            $p->seance_id,
            $p->seance->klassci_seance_id ?? 'N/A'
        );
        echo sprintf(
            "      joined_at: %s\n",
            $p->joined_at ? $p->joined_at->format('Y-m-d H:i:s') : 'NULL'
        );
        echo sprintf(
            "      last_seen_at: %s\n",
            $p->last_seen_at ? $p->last_seen_at->format('Y-m-d H:i:s') : 'NULL'
        );

        // Calculer le temps écoulé depuis le dernier heartbeat
        if ($p->last_seen_at) {
            $minutesAgo = now()->diffInMinutes($p->last_seen_at);
            echo sprintf("      ⏱️  Dernier heartbeat il y a %d minutes\n", $minutesAgo);

            if ($minutesAgo > 3) {
                echo "      ⚠️  TIMEOUT! Plus de 3 minutes d'inactivité\n";
            }
        } else {
            $minutesAgo = now()->diffInMinutes($p->joined_at);
            echo sprintf("      ⏱️  Pas de heartbeat (connecté il y a %d minutes)\n", $minutesAgo);
        }

        echo "\n";
    }
}

// 2. Chercher les participations récentes (dernières 24h)
echo "\n2. Participations récentes (dernières 24h):\n";
$recentParticipations = ESBTPAttendance::where('joined_at', '>', now()->subDay())
    ->with(['user', 'seance'])
    ->orderBy('joined_at', 'desc')
    ->get();

if ($recentParticipations->isEmpty()) {
    echo "   ❌ Aucune participation récente\n";
} else {
    foreach ($recentParticipations as $p) {
        echo sprintf(
            "   User #%d (%s) - Status: %s - Joined: %s - Left: %s\n",
            $p->user_id,
            $p->user->name ?? 'N/A',
            $p->status,
            $p->joined_at?->format('H:i:s'),
            $p->left_at?->format('H:i:s') ?? 'N/A'
        );
    }
}

// 3. Simuler le timeout
echo "\n3. Test de la fonction disconnectInactiveParticipants():\n";
echo "   Seuil de timeout: 3 minutes\n";
echo "   Heure actuelle: " . now()->format('Y-m-d H:i:s') . "\n";
echo "   Seuil timeout: " . now()->subMinutes(3)->format('Y-m-d H:i:s') . "\n\n";

$timeoutThreshold = now()->subMinutes(3);

$inactiveCount = ESBTPAttendance::where('status', 'connected')
    ->where(function($query) use ($timeoutThreshold) {
        $query->where('last_seen_at', '<', $timeoutThreshold)
              ->orWhere(function($q) use ($timeoutThreshold) {
                  $q->whereNull('last_seen_at')
                    ->where('joined_at', '<', $timeoutThreshold);
              });
    })
    ->count();

echo "   📊 Nombre de participants inactifs détectés: $inactiveCount\n";

if ($inactiveCount > 0) {
    echo "   ⚠️  Ces participants seront déconnectés par le système de timeout\n";
} else {
    echo "   ✅ Aucun participant inactif (tous envoient des heartbeats)\n";
}

// 4. Vérifier les séances visio actives
echo "\n4. Séances visio actives:\n";
$activeVisios = Seance::where('visio_active', true)
    ->orWhere('visio_status', 'active')
    ->get();

if ($activeVisios->isEmpty()) {
    echo "   ❌ Aucune visio active\n";
} else {
    foreach ($activeVisios as $visio) {
        echo sprintf(
            "   ✅ Séance #%d (KLASSCI: %s) - Status: %s\n",
            $visio->id,
            $visio->klassci_seance_id,
            $visio->visio_status ?? 'N/A'
        );
    }
}

echo "\n=== FIN DIAGNOSTIC ===\n";
