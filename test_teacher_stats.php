<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Models\Lesson;
use App\Models\Evaluation;
use App\Services\KlassciProxyService;

echo "=== TEST STATISTIQUES ENSEIGNANT ===\n\n";

// Chercher l'enseignant
$teacher = User::where('email', 'bede@gmail.com')->first();

if (!$teacher) {
    echo "❌ Enseignant non trouvé\n";
    exit(1);
}

echo "✅ Enseignant trouvé: {$teacher->name}\n";
echo "   Email: {$teacher->email}\n";
echo "   Role: {$teacher->role}\n";
echo "   Klassci ID: {$teacher->klassci_id}\n\n";

// 1. Compter les leçons
$lessonsCount = Lesson::where('enseignant_id', $teacher->klassci_id)->count();
echo "📚 Leçons créées: {$lessonsCount}\n";

// Afficher quelques leçons
$lessons = Lesson::where('enseignant_id', $teacher->klassci_id)->limit(3)->get();
foreach ($lessons as $lesson) {
    echo "   - {$lesson->title} (Matière ID: {$lesson->matiere_id})\n";
}
echo "\n";

// 2. Compter les évaluations
$evaluationsCount = Evaluation::where('enseignant_id', $teacher->klassci_id)->count();
echo "📝 Évaluations créées: {$evaluationsCount}\n";

// Afficher quelques évaluations
$evaluations = Evaluation::where('enseignant_id', $teacher->klassci_id)->limit(3)->get();
foreach ($evaluations as $eval) {
    echo "   - {$eval->title} (Matière ID: {$eval->matiere_id})\n";
}
echo "\n";

// 3. Récupérer les matières depuis KLASSCI
echo "📊 Récupération des données KLASSCI...\n";

try {
    $klassciService = app(KlassciProxyService::class);
    $enseignantsData = $klassciService->getEnseignantsEnrichis(true);

    // Trouver l'enseignant correspondant
    $currentEnseignant = null;
    foreach ($enseignantsData as $enseignant) {
        if ($enseignant['id'] == $teacher->klassci_id) {
            $currentEnseignant = $enseignant;
            break;
        }
    }

    if ($currentEnseignant && isset($currentEnseignant['matieres'])) {
        $matieres = $currentEnseignant['matieres'];
        $matieresCount = count($matieres);

        echo "✅ Matières enseignées: {$matieresCount}\n";

        // Extraire les classes uniques
        $classesSet = [];
        foreach ($matieres as $matiere) {
            echo "   - {$matiere['name']} (ID: {$matiere['id']})\n";

            if (isset($matiere['classes']) && is_array($matiere['classes'])) {
                foreach ($matiere['classes'] as $classe) {
                    if (isset($classe['id'])) {
                        $classesSet[$classe['id']] = $classe['name'] ?? 'Classe ' . $classe['id'];
                    }
                }
            }
        }

        $classesCount = count($classesSet);
        echo "\n✅ Classes enseignées: {$classesCount}\n";
        foreach ($classesSet as $id => $name) {
            echo "   - {$name} (ID: {$id})\n";
        }
    } else {
        echo "⚠️  Aucune matière trouvée dans KLASSCI\n";
        $matieresCount = 0;
        $classesCount = 0;
    }
} catch (\Exception $e) {
    echo "❌ Erreur KLASSCI: {$e->getMessage()}\n";
    $matieresCount = 0;
    $classesCount = 0;
}

echo "\n=== RÉSUMÉ DES STATISTIQUES ===\n";
echo "Matières: {$matieresCount}\n";
echo "Classes: {$classesCount}\n";
echo "Évaluations: {$evaluationsCount}\n";
echo "Leçons: {$lessonsCount}\n";
