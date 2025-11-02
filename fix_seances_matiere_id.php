<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Seance;
use Illuminate\Support\Facades\DB;

echo "🔧 CORRECTION: Remplir matiere_id dans les séances\n";
echo str_repeat('=', 80) . "\n\n";

// Mapping des noms de matières vers leurs IDs
$matiereMapping = [
    'marketing digital' => 1,
    'algorithme' => 2,
    'anglais' => 3,
];

echo "📋 Mapping des matières:\n";
foreach ($matiereMapping as $nom => $id) {
    echo "   - '{$nom}' → ID {$id}\n";
}
echo "\n";

// Récupérer toutes les séances sans matiere_id
$seances = Seance::whereNull('matiere_id')
    ->orWhere('matiere_id', 0)
    ->get();

echo "🔍 Séances à corriger: {$seances->count()}\n\n";

$corriges = 0;
$nonTrouves = 0;

foreach ($seances as $seance) {
    $matiereNom = strtolower(trim($seance->matiere_nom ?? ''));

    if (empty($matiereNom)) {
        echo "   ⚠️ Séance #{$seance->klassci_seance_id} - Pas de matiere_nom\n";
        $nonTrouves++;
        continue;
    }

    // Chercher le matiere_id correspondant
    $matiereId = null;
    foreach ($matiereMapping as $nom => $id) {
        if (str_contains($matiereNom, $nom)) {
            $matiereId = $id;
            break;
        }
    }

    if ($matiereId) {
        $seance->matiere_id = $matiereId;
        $seance->save();
        echo "   ✅ Séance #{$seance->klassci_seance_id} '{$seance->matiere_nom}' → matiere_id = {$matiereId}\n";
        $corriges++;
    } else {
        echo "   ❌ Séance #{$seance->klassci_seance_id} '{$seance->matiere_nom}' - Mapping non trouvé\n";
        $nonTrouves++;
    }
}

echo "\n" . str_repeat('=', 80) . "\n";
echo "📊 RÉSUMÉ:\n";
echo "   ✅ Corrigées: {$corriges}\n";
echo "   ❌ Non trouvées: {$nonTrouves}\n\n";

// Vérification finale
echo "🔍 VÉRIFICATION FINALE:\n";
foreach ($matiereMapping as $nom => $id) {
    $count = Seance::where('matiere_id', $id)->count();
    echo "   - Matière ID {$id} ('{$nom}'): {$count} séances\n";
}

$encoreNull = Seance::whereNull('matiere_id')->count();
echo "\n   ⚠️ Séances encore sans matiere_id: {$encoreNull}\n";

echo "\n✅ Correction terminée!\n";
