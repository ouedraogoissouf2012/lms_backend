<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

echo "=== TEST DE PERFORMANCE - HISTORIQUE DES SÉANCES ===\n\n";

// 1. Vérifier les séances avec données en cache
echo "1. Séances avec données de cache:\n";
$seancesAvecCache = DB::table('seances')
    ->whereNotNull('visio_started_at')
    ->select('id', 'klassci_seance_id', 'matiere_nom', 'classe_nom', 'titre', 'date_seance')
    ->get();

echo "   Total séances avec visio: " . $seancesAvecCache->count() . "\n";

$avecCache = $seancesAvecCache->filter(function($s) {
    return $s->matiere_nom && $s->classe_nom && $s->titre;
})->count();

$sansCache = $seancesAvecCache->count() - $avecCache;

echo "   - Avec cache complet: $avecCache\n";
echo "   - Sans cache: $sansCache\n\n";

// 2. Afficher quelques exemples
echo "2. Exemples de séances:\n";
foreach ($seancesAvecCache->take(5) as $seance) {
    echo "   Séance #{$seance->klassci_seance_id}:\n";
    echo "     - Matière: " . ($seance->matiere_nom ?? '[VIDE]') . "\n";
    echo "     - Classe: " . ($seance->classe_nom ?? '[VIDE]') . "\n";
    echo "     - Titre: " . ($seance->titre ?? '[VIDE]') . "\n";
    echo "     - Date: " . ($seance->date_seance ?? '[VIDE]') . "\n\n";
}

// 3. Test de performance simulé
echo "3. Test de performance (simulation):\n";
$start = microtime(true);

$seances = DB::table('seances')
    ->whereNotNull('visio_started_at')
    ->orderBy('visio_started_at', 'desc')
    ->limit(10)
    ->get();

$end = microtime(true);
$queryTime = round(($end - $start) * 1000, 2);

echo "   Temps de requête SQL: {$queryTime}ms\n";
echo "   Séances récupérées: " . $seances->count() . "\n\n";

// 4. Statistiques de présences
echo "4. Statistiques de présences:\n";
foreach ($seances->take(3) as $seance) {
    $attendances = DB::table('esbtp_attendance')
        ->where('seance_id', $seance->id)
        ->count();

    echo "   Séance #{$seance->klassci_seance_id}: {$attendances} participations\n";
}

echo "\n✅ Test terminé\n";
