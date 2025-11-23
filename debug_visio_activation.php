<?php

/**
 * Script de debug pour vérifier l'état de visio après activation
 */

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\DB;

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "═══════════════════════════════════════════════════════════════\n";
echo "   DEBUG : État Visio après Activation Coordinateur\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// Récupérer une séance récente
$seance = \App\Models\Seance::orderBy('updated_at', 'desc')->first();

if (!$seance) {
    echo "❌ Aucune séance trouvée\n";
    exit(1);
}

echo "📋 Séance ID : {$seance->klassci_seance_id}\n";
echo "   Matière : " . ($seance->matiere_nom ?? 'N/A') . "\n";
echo "   Enseignant : " . ($seance->enseignant_nom ?? 'N/A') . "\n\n";

echo "🔍 État des champs visio :\n";
echo "   visio_enabled    : " . ($seance->visio_enabled ? '✅ TRUE' : '❌ FALSE') . "\n";
echo "   visio_active     : " . ($seance->visio_active ? '✅ TRUE' : '❌ FALSE') . "\n";
echo "   visio_status     : " . ($seance->visio_status ?? 'NULL') . "\n";
echo "   visio_room_id    : " . ($seance->visio_room_id ?? 'NULL') . "\n\n";

echo "🎯 Condition pour bouton 'Démarrer la visio' :\n";
echo "   v-if=\"seance.visio_enabled && !seance.visio_active\"\n\n";

$canStart = $seance->visio_enabled && !$seance->visio_active;
if ($canStart) {
    echo "   ✅ Bouton 'Démarrer' DOIT être visible\n";
} else {
    echo "   ❌ Bouton 'Démarrer' N'EST PAS visible\n\n";

    if (!$seance->visio_enabled) {
        echo "   Raison : visio_enabled = false\n";
    }
    if ($seance->visio_active) {
        echo "   Raison : visio_active = true (déjà démarrée)\n";
    }
}

echo "\n";

// Chercher toutes les séances avec visio_enabled=true
echo "📊 Toutes les séances avec visio activée :\n";
$seancesWithVisio = \App\Models\Seance::where('visio_enabled', true)
    ->orderBy('updated_at', 'desc')
    ->limit(5)
    ->get();

if ($seancesWithVisio->isEmpty()) {
    echo "   ℹ️  Aucune séance avec visio activée\n";
} else {
    echo "   " . str_pad("ID", 5) . " | ";
    echo str_pad("Enabled", 10) . " | ";
    echo str_pad("Active", 10) . " | ";
    echo str_pad("Status", 15) . " | ";
    echo "Bouton visible ?\n";
    echo "   " . str_repeat("-", 70) . "\n";

    foreach ($seancesWithVisio as $s) {
        $canStartBtn = $s->visio_enabled && !$s->visio_active;
        $btnStatus = $canStartBtn ? '✅ OUI' : '❌ NON';

        echo "   " . str_pad($s->klassci_seance_id, 5) . " | ";
        echo str_pad($s->visio_enabled ? 'TRUE' : 'FALSE', 10) . " | ";
        echo str_pad($s->visio_active ? 'TRUE' : 'FALSE', 10) . " | ";
        echo str_pad($s->visio_status ?? 'NULL', 15) . " | ";
        echo $btnStatus . "\n";
    }
}

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
