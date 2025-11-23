<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "🔄 Réinitialisation des séances actives...\n\n";

// Mettre toutes les séances 'active' à 'programmee'
$updated = DB::table('seances')
    ->where('visio_status', 'active')
    ->update([
        'visio_status' => 'programmee',
        'visio_active' => 0,
        'visio_started_at' => null
    ]);

echo "✅ $updated séance(s) réinitialisée(s)\n\n";

// Afficher l'état
$seances = DB::table('seances')
    ->where('visio_enabled', 1)
    ->select('id', 'klassci_seance_id', 'matiere_nom', 'visio_status')
    ->orderBy('id', 'desc')
    ->get();

echo "📋 Séances avec visio:\n";
foreach ($seances as $seance) {
    echo sprintf(
        "  #%d | %s | Status: %s\n",
        $seance->id,
        $seance->matiere_nom ?? 'N/A',
        $seance->visio_status
    );
}

echo "\n✅ TERMINÉ! Rechargez le navigateur (Ctrl+Shift+R)\n";
