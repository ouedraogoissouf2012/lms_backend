<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "\n=== SÉANCES EN BASE LOCALE ===\n\n";

$seances = App\Models\Seance::orderBy('created_at', 'desc')->get();

echo "Total: " . $seances->count() . " séances\n\n";

echo "Dernières séances:\n";
foreach ($seances->take(10) as $seance) {
    echo "  - ID local: {$seance->id}\n";
    echo "    KLASSCI ID: {$seance->klassci_seance_id}\n";
    echo "    Titre: {$seance->titre}\n";
    echo "    Date: {$seance->date_seance}\n";
    echo "    Enseignant KLASSCI ID: {$seance->klassci_enseignant_id}\n";
    echo "    Matière: {$seance->matiere_nom} (ID: {$seance->klassci_matiere_id})\n";
    echo "    Classe: {$seance->classe_nom} (ID: {$seance->klassci_classe_id})\n";
    echo "    Créée le: {$seance->created_at}\n";
    echo "\n";
}
