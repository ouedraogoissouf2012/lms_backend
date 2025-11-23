<?php

/**
 * Réinitialiser toutes les séances actives à 'programmee'
 * Version utilisant PDO directement
 */

echo "🔄 Réinitialisation des séances actives...\n\n";

// Configuration MySQL (même que dans diagnostic_enseignants.php qui a fonctionné)
$pdo = null;

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    // Forcer la connexion MySQL
    config(['database.default' => 'mysql']);

    // Configuration MySQL par défaut
    config([
        'database.connections.mysql' => [
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => '3306',
            'database' => 'esbtp_lms',
            'username' => 'root',
            'password' => 'ESBTP2024',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ]
    ]);

    echo "✅ Configuration MySQL chargée\n\n";

    // Test de connexion
    $test = DB::connection('mysql')->select('SELECT 1 as test');
    echo "✅ Connexion MySQL établie\n\n";

    // Mettre toutes les séances 'active' à 'programmee'
    $updated = DB::connection('mysql')->table('esbtp_seances')
        ->where('visio_status', 'active')
        ->update([
            'visio_status' => 'programmee',
            'visio_active' => false,
            'visio_started_at' => null
        ]);

    echo "✅ $updated séance(s) réinitialisée(s) à 'programmee'\n\n";

    // Afficher les séances
    $seances = DB::connection('mysql')->table('esbtp_seances')
        ->where('visio_enabled', true)
        ->select('id', 'klassci_seance_id', 'matiere_nom', 'visio_status', 'visio_enabled')
        ->orderBy('id', 'desc')
        ->get();

    echo "📋 État des séances avec visio activée:\n";
    foreach ($seances as $seance) {
        echo sprintf(
            "  ID:%d | Séance#%d | %s | Status:%s\n",
            $seance->id,
            $seance->klassci_seance_id ?? 'N/A',
            $seance->matiere_nom ?? 'N/A',
            $seance->visio_status
        );
    }

    echo "\n✅ TERMINÉ!\n\n";
    echo "Maintenant:\n";
    echo "1. Rechargez le navigateur (Ctrl+Shift+R)\n";
    echo "2. Enseignant clique 'Démarrer maintenant'\n";
    echo "3. Étudiant pourra alors 'Rejoindre'\n";

} catch (Exception $e) {
    echo "❌ ERREUR: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
