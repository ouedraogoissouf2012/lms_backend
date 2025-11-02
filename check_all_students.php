<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\UserClass;
use Illuminate\Support\Facades\DB;

echo "🔍 VÉRIFICATION: Tous les étudiants du système\n";
echo str_repeat('=', 80) . "\n\n";

// 1. Tous les étudiants dans la table users
$allStudents = User::where('role', 'etudiant')->get();

echo "📚 Total étudiants dans 'users': {$allStudents->count()}\n\n";

if ($allStudents->count() > 0) {
    foreach ($allStudents as $student) {
        echo "   📖 {$student->name} (ID: {$student->id})\n";
        echo "      - Email: {$student->email}\n";
        echo "      - KLASSCI ID: " . ($student->klassci_id ?? 'NULL') . "\n";

        // Chercher ses classes
        $classes = UserClass::where('user_id', $student->id)->get();
        if ($classes->count() > 0) {
            echo "      - Classes: ";
            foreach ($classes as $c) {
                echo "#{$c->klassci_classe_id} ";
            }
            echo "\n";
        } else {
            echo "      - ⚠️ Pas de classe assignée dans user_classes\n";
        }
        echo "\n";
    }
}

echo str_repeat('=', 80) . "\n";
echo "🏫 Détail de la table user_classes:\n";
echo str_repeat('-', 80) . "\n";

$userClasses = UserClass::all();
echo "Total entrées: {$userClasses->count()}\n\n";

if ($userClasses->count() > 0) {
    $grouped = $userClasses->groupBy('klassci_classe_id');

    foreach ($grouped as $classeId => $entries) {
        echo "   Classe #{$classeId}: {$entries->count()} étudiant(s)\n";
        foreach ($entries as $entry) {
            $user = User::find($entry->user_id);
            if ($user) {
                echo "      - {$user->name} (User ID: {$user->id})\n";
            }
        }
        echo "\n";
    }
} else {
    echo "⚠️ La table user_classes est vide!\n";
    echo "Les étudiants doivent être synchronisés depuis KLASSCI.\n";
}

echo "✅ Vérification terminée!\n";
