<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;

echo "🔧 CORRECTION ID KLASSCI DE MARCEL\n";
echo "===================================\n\n";

$student = User::where('name', 'LIKE', '%MARCEL%OUEDRAOGO%')
    ->where('role', 'etudiant')
    ->first();

if (!$student) {
    echo "❌ MARCEL OUEDRAOGO non trouvé\n";
    exit(1);
}

echo "Avant correction:\n";
echo "  - Name: {$student->name}\n";
echo "  - KLASSCI ID actuel: {$student->klassci_id}\n\n";

// Mettre à jour avec le bon ID depuis KLASSCI
$student->klassci_id = 3;
$student->save();

echo "✅ Correction effectuée !\n";
echo "  - Nouveau KLASSCI ID: {$student->klassci_id}\n\n";
