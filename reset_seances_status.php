<?php

/**
 * Réinitialiser toutes les séances actives à 'programmee'
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "🔄 Réinitialisation des séances actives...\n\n";

// Mettre toutes les séances 'active' à 'programmee'
$updated = DB::table('esbtp_seances')
    ->where('visio_status', 'active')
    ->update([
        'visio_status' => 'programmee',
        'visio_active' => false,
        'visio_started_at' => null
    ]);

echo "✅ $updated séance(s) réinitialisée(s) à 'programmee'\n\n";

// Afficher les séances
$seances = DB::table('esbtp_seances')
    ->where('visio_enabled', true)
    ->select('id', 'klassci_seance_id', 'matiere_nom', 'visio_status', 'visio_enabled')
    ->get();

echo "📋 État des séances:\n";
foreach ($seances as $seance) {
    echo sprintf(
        "  ID:%d | Séance#%d | %s | Status:%s\n",
        $seance->id,
        $seance->klassci_seance_id,
        $seance->matiere_nom ?? 'N/A',
        $seance->visio_status
    );
}

echo "\n✅ TERMINÉ!\n";
echo "Maintenant:\n";
echo "1. Rechargez le navigateur (Ctrl+Shift+R)\n";
echo "2. Enseignant clique 'Démarrer maintenant'\n";
echo "3. Étudiant pourra alors 'Rejoindre'\n";
