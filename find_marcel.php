<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;

echo "🔍 RECHERCHE MARCEL OUEDRAOGO\n";
echo "==============================\n\n";

// Chercher par nom
$users = User::where('name', 'like', '%MARCEL%')
    ->orWhere('nom', 'like', '%MARCEL%')
    ->orWhere('prenom', 'like', '%MARCEL%')
    ->orWhere('name', 'like', '%OUEDRAOGO%')
    ->get();

echo "Résultats de recherche: " . count($users) . " utilisateurs trouvés\n\n";

foreach ($users as $user) {
    echo "👤 Utilisateur ID: {$user->id}\n";
    echo "  - Name: {$user->name}\n";
    echo "  - Email: {$user->email}\n";
    echo "  - Role: {$user->role}\n";
    echo "  - KLASSCI ID: {$user->klassci_id}\n";
    echo "  - Nom: " . ($user->nom ?? 'N/A') . "\n";
    echo "  - Prénom: " . ($user->prenom ?? 'N/A') . "\n";
    echo "\n";
}

// Aussi lister tous les étudiants
echo "\n📋 TOUS LES ÉTUDIANTS:\n";
echo "======================\n";

$students = User::where('role', 'etudiant')
    ->orWhere('role', 'student')
    ->get();

foreach ($students as $student) {
    echo "{$student->id}. {$student->name} - {$student->email} (KLASSCI ID: {$student->klassci_id})\n";
}
