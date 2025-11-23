<?php

/**
 * Script pour créer des assignations de test enseignant ↔ matière
 */

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\MatiereEnseignant;

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  CRÉATION D'ASSIGNATIONS ENSEIGNANT ↔ MATIÈRE (TEST)            ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

// Enseignants disponibles (d'après notre diagnostic):
// ID 9  - BEDE ABEL TEST
// ID 10 - N'GUESSAN Marcel Jacques Patrick Djedje-li
// ID 11 - KOUAME ROGER

// Matières de la classe B2 COM:
// ID 1 - Marketing digital
// ID 2 - Algorithme
// ID 3 - Anglais

$assignations = [
    // Marketing digital → BEDE ABEL TEST
    ['matiere_id' => 1, 'enseignant_id' => 9, 'matiere_nom' => 'Marketing digital', 'enseignant_nom' => 'BEDE ABEL TEST'],

    // Algorithme → N'GUESSAN Marcel
    ['matiere_id' => 2, 'enseignant_id' => 10, 'matiere_nom' => 'Algorithme', 'enseignant_nom' => 'N\'GUESSAN Marcel'],

    // Anglais → KOUAME ROGER
    ['matiere_id' => 3, 'enseignant_id' => 11, 'matiere_nom' => 'Anglais', 'enseignant_nom' => 'KOUAME ROGER'],

    // Marketing digital → aussi N'GUESSAN Marcel (co-enseignant)
    ['matiere_id' => 1, 'enseignant_id' => 10, 'matiere_nom' => 'Marketing digital', 'enseignant_nom' => 'N\'GUESSAN Marcel (co-enseignant)'],
];

echo "Création de " . count($assignations) . " assignations...\n\n";

foreach ($assignations as $assign) {
    try {
        $result = MatiereEnseignant::assignEnseignant(
            $assign['matiere_id'],
            $assign['enseignant_id'],
            2 // Created by user ID 2 (le coordinateur)
        );

        if ($result->wasRecentlyCreated) {
            echo "✅ CRÉÉ: {$assign['matiere_nom']} → {$assign['enseignant_nom']}\n";
        } else {
            echo "ℹ️  EXISTE: {$assign['matiere_nom']} → {$assign['enseignant_nom']}\n";
        }

    } catch (Exception $e) {
        echo "❌ ERREUR: {$assign['matiere_nom']} → {$assign['enseignant_nom']}\n";
        echo "   Raison: " . $e->getMessage() . "\n";
    }
}

echo "\n" . str_repeat('─', 70) . "\n\n";

// Vérification
echo "📊 VÉRIFICATION DES ASSIGNATIONS:\n\n";

for ($matiereId = 1; $matiereId <= 3; $matiereId++) {
    $enseignantIds = MatiereEnseignant::getEnseignantsForMatiere($matiereId);

    $matiereName = ['', 'Marketing digital', 'Algorithme', 'Anglais'][$matiereId];

    echo "📚 {$matiereName} (ID: {$matiereId})\n";
    echo "   Enseignants assignés: " . count($enseignantIds) . "\n";

    if (!empty($enseignantIds)) {
        echo "   IDs: " . implode(', ', $enseignantIds) . "\n";
    }

    echo "\n";
}

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  TERMINÉ!                                                        ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
