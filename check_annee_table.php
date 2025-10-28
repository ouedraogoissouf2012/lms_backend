<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "Recherche de la table année universitaire...\n\n";

// Lister toutes les tables (SQLite)
try {
    $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
    echo "Toutes les tables de la base de données:\n\n";
    foreach ($tables as $table) {
        $tableName = $table->name;
        echo "  - $tableName\n";
    }

    echo "\n\nTables contenant 'teacher' ou 'enseignant':\n";
    foreach ($tables as $table) {
        $tableName = $table->name;
        if (stripos($tableName, 'teacher') !== false || stripos($tableName, 'enseignant') !== false) {
            echo "  - $tableName\n";
            $count = DB::table($tableName)->count();
            echo "    Nombre d'enregistrements: $count\n";
        }
    }

    echo "\n";

    // Test direct du nom utilisé dans le code
    if (Schema::hasTable('esbtp_annee_universitaires')) {
        echo "✓ Table 'esbtp_annee_universitaires' existe\n";
    } else {
        echo "✗ Table 'esbtp_annee_universitaires' n'existe PAS\n";
    }

} catch (\Exception $e) {
    echo "Erreur: " . $e->getMessage() . "\n";
}
