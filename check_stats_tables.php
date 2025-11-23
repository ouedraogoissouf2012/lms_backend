<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  VÉRIFICATION DES TABLES POUR STATISTIQUES                       ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

// Liste des tables qui peuvent contenir des stats
$tables = [
    'esbtp_seances',
    'esbtp_lessons',
    'esbtp_evaluations',
    'esbtp_evaluation_submissions'
];

foreach ($tables as $table) {
    if (Schema::hasTable($table)) {
        echo "✅ Table: $table\n";

        // Compter les enregistrements
        $count = DB::table($table)->count();
        echo "   Total enregistrements: $count\n";

        // Afficher quelques colonnes
        $columns = DB::select("SHOW COLUMNS FROM $table");
        echo "   Colonnes clés: ";
        $keyColumns = [];
        foreach ($columns as $col) {
            if (in_array($col->Field, ['id', 'matiere_id', 'classe_id', 'status', 'type', 'created_at'])) {
                $keyColumns[] = $col->Field;
            }
        }
        echo implode(', ', $keyColumns) . "\n";

        // Si matiere_id existe, compter par matière
        if (in_array('matiere_id', $keyColumns)) {
            echo "   Par matière:\n";
            $stats = DB::table($table)
                ->select('matiere_id', DB::raw('COUNT(*) as count'))
                ->groupBy('matiere_id')
                ->get();

            foreach ($stats as $stat) {
                echo "      Matière {$stat->matiere_id}: {$stat->count} enregistrement(s)\n";
            }
        }

        echo "\n";
    } else {
        echo "❌ Table: $table (n'existe pas)\n\n";
    }
}

echo "──────────────────────────────────────────────────────────────────────\n\n";

echo "🔍 EXEMPLE: Matière ID 1 (Marketing digital)\n\n";

// Séances
if (Schema::hasTable('esbtp_seances')) {
    $seances = DB::table('esbtp_seances')
        ->where('matiere_id', 1)
        ->count();
    echo "   Séances: $seances\n";
}

// Leçons
if (Schema::hasTable('esbtp_lessons')) {
    $lessons = DB::table('esbtp_lessons')
        ->where('matiere_id', 1)
        ->count();
    echo "   Leçons: $lessons\n";
}

// Évaluations
if (Schema::hasTable('esbtp_evaluations')) {
    $evaluations = DB::table('esbtp_evaluations')
        ->where('matiere_id', 1)
        ->count();
    echo "   Évaluations: $evaluations\n";
}

echo "\n╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  FIN                                                             ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
