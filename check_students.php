<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Classe;

$classe = Classe::where('klassci_id', 2)->first();
echo "Classe: " . $classe->libelle . PHP_EOL;
echo "Étudiants actifs:" . PHP_EOL;
foreach ($classe->etudiantsActifs as $etudiant) {
    echo "  - ID: " . $etudiant->id . " | Klassci ID: " . $etudiant->klassci_id . " | " . $etudiant->name . " | " . $etudiant->email . PHP_EOL;
}
