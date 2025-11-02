<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Seance;

echo "🔍 VÉRIFICATION STRUCTURE SÉANCE 37\n";
echo "=====================================\n\n";

$seance = Seance::where('klassci_seance_id', 37)->first();

if (!$seance) {
    echo "❌ Séance 37 non trouvée\n";
    exit(1);
}

echo "✅ Séance trouvée !\n\n";
echo "Colonnes disponibles:\n";
print_r(array_keys($seance->getAttributes()));

echo "\n\nDonnées complètes:\n";
print_r($seance->toArray());

echo "\n\nTest d'accès à klassci_classe_id:\n";
echo "  - Existe ? " . (isset($seance->klassci_classe_id) ? 'OUI' : 'NON') . "\n";
echo "  - Valeur : " . ($seance->klassci_classe_id ?? 'N/A') . "\n";

echo "\n\n✅ Terminé\n";
