<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Seance;

echo "🔍 ANALYSE: Pourquoi les heures sont invalides?\n";
echo "=" . str_repeat("=", 70) . "\n\n";

// Séance 36 qui est affichée
$seance = Seance::where('klassci_seance_id', 36)->first();

if (!$seance) {
    echo "❌ Séance #36 non trouvée\n";
    exit(1);
}

echo "📋 SÉANCE #36 - DONNÉES BDD LOCALE:\n";
echo str_repeat("-", 70) . "\n";
echo "ID local: {$seance->id}\n";
echo "KLASSCI Seance ID: {$seance->klassci_seance_id}\n";
echo "Matière: {$seance->matiere_nom}\n";
echo "Enseignant: {$seance->enseignant_nom}\n";
echo "Classe ID: {$seance->klassci_classe_id}\n\n";

echo "⏰ COLONNES DE DATES/HEURES DANS LA TABLE:\n";
echo str_repeat("-", 70) . "\n";

// Vérifier toutes les colonnes de la table
$columns = DB::select("PRAGMA table_info(seances)");
$dateColumns = [];
foreach ($columns as $col) {
    if (stripos($col->name, 'date') !== false || stripos($col->name, 'heure') !== false || stripos($col->name, 'time') !== false) {
        $dateColumns[] = $col->name;
    }
}

if (empty($dateColumns)) {
    echo "❌ PROBLÈME: Aucune colonne de date/heure dans la table seances!\n\n";
    echo "La table seances n'a PAS de colonnes pour stocker:\n";
    echo "  - date de la séance\n";
    echo "  - heure de début\n";
    echo "  - heure de fin\n\n";
    
    echo "📊 Colonnes disponibles:\n";
    foreach ($columns as $col) {
        echo "  - {$col->name} ({$col->type})\n";
    }
    
    echo "\n🔧 SOLUTION:\n";
    echo "Les données de programmation (date, heures) viennent UNIQUEMENT de l'API KLASSCI.\n";
    echo "Comme l'API KLASSCI est DOWN, on n'a PAS ces informations.\n";
    echo "C'est pourquoi on voit '--:-- - --:-- (0 min)'\n\n";
} else {
    echo "✅ Colonnes trouvées:\n";
    foreach ($dateColumns as $colName) {
        echo "  - {$colName}\n";
    }
}

echo "\n";
echo "🎯 CONCLUSION:\n";
echo str_repeat("-", 70) . "\n";
echo "Le backend retourne les séances depuis la BDD LOCALE (lignes 480-500)\n";
echo "avec des valeurs PAR DÉFAUT pour programmation:\n";
echo "  - date: now()->format('Y-m-d')  [aujourd'hui]\n";
echo "  - heure_debut: '08:00'  [valeur fixe]\n";
echo "  - heure_fin: '10:00'  [valeur fixe]\n\n";

echo "MAIS le frontend ne reçoit PAS ces valeurs car l'API est DOWN\n";
echo "et le code tombe sur un CATCH qui retourne un tableau vide.\n";
