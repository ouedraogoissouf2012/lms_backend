<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Models\Lesson;
use App\Models\Evaluation;

echo "=== TEST STATISTIQUES SIMPLES ===\n\n";

$teacher = User::where('email', 'bede@gmail.com')->first();

if (!$teacher) {
    echo "❌ Enseignant non trouvé\n";
    exit(1);
}

$klassciId = $teacher->klassci_id;

echo "Enseignant: {$teacher->name} (ID: {$klassciId})\n\n";

// 1. Leçons
$lessonsCount = Lesson::where('enseignant_id', $klassciId)->count();
echo "📚 Leçons: {$lessonsCount}\n";

// 2. Évaluations
$evaluationsCount = Evaluation::where('enseignant_id', $klassciId)->count();
echo "📝 Évaluations: {$evaluationsCount}\n";

// 3. Matières uniques
$matieresSet = [];

$lessonMatieres = Lesson::where('enseignant_id', $klassciId)
    ->whereNotNull('matiere_id')
    ->distinct()
    ->pluck('matiere_id')
    ->toArray();

foreach ($lessonMatieres as $mid) {
    $matieresSet[$mid] = true;
}

$evalMatieres = Evaluation::where('enseignant_id', $klassciId)
    ->whereNotNull('matiere_id')
    ->distinct()
    ->pluck('matiere_id')
    ->toArray();

foreach ($evalMatieres as $mid) {
    $matieresSet[$mid] = true;
}

echo "📖 Matières: " . count($matieresSet) . "\n";
echo "   IDs: " . implode(', ', array_keys($matieresSet)) . "\n";

// 4. Classes uniques
$classesSet = [];

$lessonClasses = Lesson::where('enseignant_id', $klassciId)
    ->whereNotNull('classe_id')
    ->distinct()
    ->pluck('classe_id')
    ->toArray();

foreach ($lessonClasses as $cid) {
    $classesSet[$cid] = true;
}

$evalClasses = Evaluation::where('enseignant_id', $klassciId)
    ->whereNotNull('classe_id')
    ->distinct()
    ->pluck('classe_id')
    ->toArray();

foreach ($evalClasses as $cid) {
    $classesSet[$cid] = true;
}

echo "🎓 Classes: " . count($classesSet) . "\n";
if (count($classesSet) > 0) {
    echo "   IDs: " . implode(', ', array_keys($classesSet)) . "\n";
}

echo "\n=== RÉSULTAT FINAL ===\n";
echo "Matières: " . count($matieresSet) . "\n";
echo "Classes: " . count($classesSet) . "\n";
echo "Évaluations: {$evaluationsCount}\n";
echo "Leçons: {$lessonsCount}\n";
