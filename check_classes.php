<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Vérification des classes ===\n\n";

$classes = DB::table('classes')->select('id', 'klassci_id', 'nom')->get();

echo "Total classes: " . $classes->count() . "\n\n";

if ($classes->isEmpty()) {
    echo "Aucune classe trouvée!\n";
} else {
    echo "Classes disponibles:\n";
    foreach ($classes as $classe) {
        echo "  - ID local: {$classe->id}, Klassci ID: " . ($classe->klassci_id ?? 'NULL') . ", Nom: {$classe->nom}\n";
    }
}

echo "\n";

// Vérifier les étudiants
$students = DB::table('users')->where('role', 'etudiant')->count();
echo "Total étudiants: {$students}\n";

// Vérifier classe_etudiant
$enrollments = DB::table('classe_etudiant')->count();
echo "Total inscriptions (classe_etudiant): {$enrollments}\n";

echo "\n=== Fin ===\n";
