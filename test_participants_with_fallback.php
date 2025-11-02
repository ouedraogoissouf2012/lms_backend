<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Seance;
use App\Models\UserClass;
use App\Models\User;

echo "🧪 TEST: Fallback récupération étudiants pour séance #37\n";
echo str_repeat('=', 80) . "\n\n";

$seance = Seance::where('klassci_seance_id', 37)->first();

if (!$seance) {
    echo "❌ Séance #37 non trouvée\n";
    exit(1);
}

echo "📌 Séance #37:\n";
echo "   - klassci_classe_id: " . ($seance->klassci_classe_id ?? 'NULL') . "\n\n";

if (!$seance->klassci_classe_id) {
    echo "❌ Pas de klassci_classe_id, impossible de récupérer les étudiants\n";
    exit(1);
}

echo "🔍 Recherche étudiants via BDD locale (table user_classes):\n";
echo str_repeat('-', 80) . "\n";

try {
    $students = UserClass::where('klassci_classe_id', $seance->klassci_classe_id)
        ->join('users', 'user_classes.user_id', '=', 'users.id')
        ->where('users.role', 'etudiant')
        ->select('users.id', 'users.name', 'users.email', 'users.klassci_id')
        ->get();

    echo "Étudiants trouvés: {$students->count()}\n\n";

    if ($students->count() > 0) {
        foreach ($students as $student) {
            // Parser le nom
            $nameParts = explode(' ', $student->name, 2);
            $nom = $nameParts[0] ?? $student->name;
            $prenom = $nameParts[1] ?? '';

            echo "   📖 {$nom} {$prenom}\n";
            echo "      - ID: {$student->id}\n";
            echo "      - Email: {$student->email}\n";
            echo "      - KLASSCI ID: {$student->klassci_id}\n";
            echo "\n";
        }

        echo "✅ Le fallback fonctionne!\n";
    } else {
        echo "⚠️ Aucun étudiant trouvé dans user_classes pour classe_id = {$seance->klassci_classe_id}\n\n";

        // Vérifier si la table user_classes a des données
        $totalUserClasses = UserClass::count();
        echo "Total entrées dans user_classes: {$totalUserClasses}\n";

        if ($totalUserClasses > 0) {
            echo "\nExemples d'entrées user_classes:\n";
            $examples = UserClass::limit(5)->get();
            foreach ($examples as $ex) {
                echo "   - user_id: {$ex->user_id}, klassci_classe_id: {$ex->klassci_classe_id}\n";
            }
        }
    }

} catch (\Exception $e) {
    echo "❌ Erreur: {$e->getMessage()}\n";
}

echo "\n✅ Test terminé!\n";
