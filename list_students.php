<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "📋 LISTE DES ÉTUDIANTS\n";
echo "======================\n\n";

$students = DB::table('users')
    ->where('role', 'etudiant')
    ->select('id', 'name', 'nom', 'prenom', 'email', 'klassci_id')
    ->limit(20)
    ->get();

if ($students->isEmpty()) {
    echo "❌ Aucun étudiant trouvé\n";
} else {
    echo "Nombre d'étudiants: " . $students->count() . "\n\n";

    foreach ($students as $student) {
        echo "ID: {$student->id}\n";
        echo "  Name: {$student->name}\n";
        echo "  Email: " . ($student->email ?? 'N/A') . "\n";
        echo "  KLASSCI ID: " . ($student->klassci_id ?? 'N/A') . "\n";
        echo "  ---\n";
    }
}
