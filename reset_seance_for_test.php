<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Seance;

echo "====================================\n";
echo "RÉINITIALISER SÉANCE POUR TESTS\n";
echo "====================================\n\n";

// Réinitialiser la séance 19
$seanceId = 19;

$visio = Seance::where('klassci_seance_id', $seanceId)->first();

if (!$visio) {
    echo "❌ Séance {$seanceId} introuvable dans la BDD\n";
    exit(1);
}

echo "🎯 Séance ID: {$seanceId}\n";
echo "État actuel:\n";
echo "  - visio_enabled: " . ($visio->visio_enabled ? 'OUI' : 'NON') . "\n";
echo "  - visio_status: {$visio->visio_status}\n";
echo "  - visio_active: " . ($visio->visio_active ? 'OUI' : 'NON') . "\n\n";

// Réinitialiser au statut "programmee"
$visio->update([
    'visio_status' => 'programmee',
    'visio_active' => false,
    'visio_started_at' => null,
    'visio_ended_at' => null,
    'visio_participants_count' => 0
]);

$visio->refresh();

echo "✅ Séance réinitialisée:\n";
echo "  - visio_enabled: " . ($visio->visio_enabled ? 'OUI' : 'NON') . "\n";
echo "  - visio_status: {$visio->visio_status}\n";
echo "  - visio_active: " . ($visio->visio_active ? 'OUI' : 'NON') . "\n\n";

echo "👉 Maintenant tu peux:\n";
echo "   1. Rafraîchir le navigateur (CTRL+SHIFT+R)\n";
echo "   2. Aller sur http://localhost:5173/matieres/1\n";
echo "   3. Cliquer sur 'Démarrer la visio' (devrait être BLEU maintenant)\n";
