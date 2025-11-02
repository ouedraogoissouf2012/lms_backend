<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Seance;

echo "🔧 CORRECTION GLOBALE: Remplir tous les klassci_matiere_id manquants\n";
echo str_repeat('=', 80) . "\n\n";

// Mapping des matières (nom → klassci_matiere_id)
$matiereMapping = [
    'marketing digital' => 1,
    'algorithme' => 2,
    'anglais' => 3,
];

echo "📋 Mapping configuré:\n";
foreach ($matiereMapping as $nom => $id) {
    echo "   '{$nom}' → klassci_matiere_id = {$id}\n";
}
echo "\n";

// Récupérer TOUTES les séances sans klassci_matiere_id
$seancesSansId = Seance::whereNull('klassci_matiere_id')
    ->orWhere('klassci_matiere_id', '')
    ->get();

echo "🔍 Séances à corriger: {$seancesSansId->count()}\n\n";

if ($seancesSansId->count() == 0) {
    echo "✅ Aucune correction nécessaire!\n";
    exit(0);
}

$corriges = 0;
$ignores = 0;

foreach ($seancesSansId as $seance) {
    $matiereNom = strtolower(trim($seance->matiere_nom ?? ''));

    if (empty($matiereNom)) {
        echo "   ⚠️ Séance #{$seance->klassci_seance_id} - Pas de matiere_nom (ignorée)\n";
        $ignores++;
        continue;
    }

    // Chercher le klassci_matiere_id correspondant
    $matiereId = null;
    foreach ($matiereMapping as $nom => $id) {
        if (str_contains($matiereNom, $nom)) {
            $matiereId = $id;
            break;
        }
    }

    if ($matiereId) {
        $seance->klassci_matiere_id = $matiereId;
        $seance->save();
        echo "   ✅ Séance #{$seance->klassci_seance_id} '{$seance->matiere_nom}' → klassci_matiere_id = {$matiereId}\n";
        $corriges++;
    } else {
        echo "   ❌ Séance #{$seance->klassci_seance_id} '{$seance->matiere_nom}' - Mapping inconnu (ignorée)\n";
        $ignores++;
    }
}

echo "\n" . str_repeat('=', 80) . "\n";
echo "📊 RÉSUMÉ:\n";
echo "   ✅ Séances corrigées: {$corriges}\n";
echo "   ⚠️ Séances ignorées: {$ignores}\n\n";

// Vérification finale par matière
echo "🔍 VÉRIFICATION FINALE - Compteurs par matière:\n";
echo str_repeat('-', 80) . "\n";

foreach ($matiereMapping as $nom => $id) {
    $count = Seance::where('klassci_matiere_id', $id)->count();
    echo "   📚 {$nom} (ID {$id}): {$count} séance(s)\n";
}

$resteSansId = Seance::whereNull('klassci_matiere_id')->count();
echo "\n   ⚠️ Séances encore sans klassci_matiere_id: {$resteSansId}\n";

echo "\n✅ Correction terminée!\n";
echo "\n💡 Vous pouvez maintenant actualiser la page 'Mes Matières'\n";
