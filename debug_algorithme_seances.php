<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Seance;
use Illuminate\Support\Facades\DB;

echo "🔍 DEBUG: Séances d'Algorithme\n";
echo str_repeat('=', 80) . "\n\n";

echo "📊 TOUTES les séances avec 'Algorithme' dans le nom:\n";
echo str_repeat('-', 80) . "\n";

$seancesParNom = Seance::whereRaw("LOWER(matiere_nom) LIKE ?", ['%algorithme%'])
    ->select('id', 'klassci_seance_id', 'klassci_matiere_id', 'matiere_nom', 'visio_status')
    ->get();

echo "Trouvées par matiere_nom: {$seancesParNom->count()}\n\n";

foreach ($seancesParNom as $s) {
    echo "Séance #{$s->klassci_seance_id}:\n";
    echo "   - ID local: {$s->id}\n";
    echo "   - klassci_matiere_id: " . ($s->klassci_matiere_id ?? 'NULL') . "\n";
    echo "   - matiere_nom: {$s->matiere_nom}\n";
    echo "   - status: " . ($s->visio_status ?? 'NULL') . "\n";
    echo "\n";
}

echo str_repeat('=', 80) . "\n";
echo "📊 Séances avec klassci_matiere_id = 2:\n";
echo str_repeat('-', 80) . "\n";

$seancesParId = Seance::where('klassci_matiere_id', 2)
    ->select('id', 'klassci_seance_id', 'klassci_matiere_id', 'matiere_nom', 'visio_status')
    ->get();

echo "Trouvées par klassci_matiere_id=2: {$seancesParId->count()}\n\n";

foreach ($seancesParId as $s) {
    echo "Séance #{$s->klassci_seance_id}:\n";
    echo "   - matiere_nom: " . ($s->matiere_nom ?? 'NULL') . "\n";
    echo "   - status: " . ($s->visio_status ?? 'NULL') . "\n";
    echo "\n";
}

echo str_repeat('=', 80) . "\n";
echo "🔍 ANALYSE: Différence entre les deux méthodes\n";
echo str_repeat('-', 80) . "\n";

if ($seancesParNom->count() != $seancesParId->count()) {
    echo "⚠️ PROBLÈME DÉTECTÉ!\n\n";
    echo "Il y a {$seancesParNom->count()} séances avec 'Algorithme' dans le nom\n";
    echo "Mais seulement {$seancesParId->count()} avec klassci_matiere_id=2\n\n";

    echo "Séances qui n'ont PAS klassci_matiere_id=2:\n";

    foreach ($seancesParNom as $s) {
        if ($s->klassci_matiere_id != 2) {
            echo "   ❌ Séance #{$s->klassci_seance_id}: klassci_matiere_id = " . ($s->klassci_matiere_id ?? 'NULL') . "\n";
        }
    }

    echo "\n💡 Solution: Ces séances doivent avoir klassci_matiere_id = 2\n";
} else {
    echo "✅ Tout est cohérent!\n";
}

echo "\n✅ Debug terminé!\n";
