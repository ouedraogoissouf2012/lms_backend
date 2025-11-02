<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Seance;

echo "🔧 CORRECTION CLASSE_ID POUR SÉANCE 37\n";
echo "=======================================\n\n";

$seance = Seance::where('klassci_seance_id', 37)->first();

if (!$seance) {
    echo "❌ Séance 37 non trouvée\n";
    exit(1);
}

echo "Avant correction:\n";
echo "  - klassci_classe_id: " . ($seance->klassci_classe_id ?? 'NULL') . "\n\n";

$seance->klassci_classe_id = 1; // B2 COM
$seance->save();

echo "✅ Mise à jour effectuée!\n\n";

echo "Après correction:\n";
echo "  - klassci_classe_id: {$seance->klassci_classe_id}\n\n";

echo "✅ Séance 37 maintenant liée à la classe B2 COM (ID: 1)\n";
