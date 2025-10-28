<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Chapter;

// Créer un chapitre de test
$chapter = Chapter::create([
    'matiere_id' => 1, // Marketing digital
    'enseignant_id' => 9, // BEDE ABEL TEST
    'title' => 'Introduction au Marketing Digital',
    'description' => 'Découvrez les bases du marketing digital : SEO, réseaux sociaux, publicité en ligne',
    'order' => 1,
    'duration_minutes' => 120
]);

echo "✓ Chapitre créé avec succès!" . PHP_EOL;
echo "ID: " . $chapter->id . PHP_EOL;
echo "Titre: " . $chapter->title . PHP_EOL;
echo "Matière ID: " . $chapter->matiere_id . PHP_EOL;
echo "Enseignant ID: " . $chapter->enseignant_id . PHP_EOL;
echo PHP_EOL;

// Afficher le JSON du chapitre
echo "JSON du chapitre:" . PHP_EOL;
echo json_encode($chapter, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
