<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Seance;

echo "📊 VÉRIFICATION RAPIDE: Données des séances\n";
echo "=" . str_repeat("=", 70) . "\n\n";

// Séances qui nous intéressent (celles affichées dans la capture)
$seanceIds = [27, 34, 36, 37];

echo "🎓 SÉANCES ALGORITHME:\n";
echo str_repeat("-", 70) . "\n\n";

foreach ($seanceIds as $seanceId) {
    $seance = Seance::where('klassci_seance_id', $seanceId)->first();

    if ($seance) {
        echo "Séance #{$seanceId} (ID local: {$seance->id}):\n";
        echo "   📚 Matière: " . ($seance->matiere_nom ?? 'N/A') . "\n";
        echo "   👤 Enseignant: " . ($seance->enseignant_nom ?? 'NON DÉFINI') . "\n";
        echo "   🏛️ Classe: " . ($seance->klassci_classe_id ?? 'N/A') . "\n";
        echo "   📹 Visio: " . ($seance->visio_enabled ? 'Oui' : 'Non') . " - Status: " . ($seance->visio_status ?? 'N/A') . "\n";
        echo "\n";
    } else {
        echo "Séance #{$seanceId}: ❌ Non trouvée dans la BDD locale\n\n";
    }
}

echo "\n✅ Si l'enseignant est 'BEDE ABEL TEST' ici, le backend est correct.\n";
echo "   Le problème était qu'il était écrasé par le code ligne 570-573.\n";
echo "   Maintenant que c'est corrigé, le frontend devrait afficher le bon nom.\n";
