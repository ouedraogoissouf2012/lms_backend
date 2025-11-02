<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "🔍 VÉRIFICATION: Structure table seances\n";
echo str_repeat('=', 80) . "\n\n";

// Récupérer les colonnes de la table
$columns = DB::select('PRAGMA table_info(seances)');

echo "📋 Colonnes de la table seances:\n";
foreach ($columns as $column) {
    echo sprintf("   - %-25s | Type: %-15s | Null: %s | Default: %s\n",
        $column->name,
        $column->type,
        $column->notnull ? 'NO' : 'YES',
        $column->dflt_value ?? 'NULL'
    );
}

echo "\n" . str_repeat('=', 80) . "\n";
echo "📊 Échantillon de données (5 premières séances):\n";
echo str_repeat('=', 80) . "\n\n";

$seances = DB::table('seances')
    ->select('id', 'klassci_seance_id', 'matiere_id', 'matiere_nom', 'enseignant_nom', 'visio_status')
    ->limit(5)
    ->get();

foreach ($seances as $s) {
    echo "Séance #{$s->klassci_seance_id}:\n";
    echo "   - ID local: {$s->id}\n";
    echo "   - matiere_id: " . ($s->matiere_id ?? 'NULL') . "\n";
    echo "   - matiere_nom: " . ($s->matiere_nom ?? 'NULL') . "\n";
    echo "   - enseignant: " . ($s->enseignant_nom ?? 'NULL') . "\n";
    echo "   - status: " . ($s->visio_status ?? 'NULL') . "\n";
    echo "\n";
}

echo str_repeat('=', 80) . "\n";
echo "📈 Statistiques:\n";

$total = DB::table('seances')->count();
$avecMatiereId = DB::table('seances')->whereNotNull('matiere_id')->where('matiere_id', '!=', '')->count();
$sansMatiereId = $total - $avecMatiereId;

echo "   - Total séances: {$total}\n";
echo "   - Avec matiere_id: {$avecMatiereId}\n";
echo "   - Sans matiere_id (NULL ou vide): {$sansMatiereId}\n";

echo "\n✅ Vérification terminée!\n";
