<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Lesson;

echo "=== VÉRIFICATION VISIBILITÉ DES LEÇONS ===\n\n";

$lessons = Lesson::orderBy('id', 'desc')->get();

echo "Total leçons: " . $lessons->count() . "\n\n";

foreach ($lessons as $lesson) {
    echo "----------------------------------------\n";
    echo "ID: " . $lesson->id . "\n";
    echo "Titre: " . $lesson->title . "\n";
    echo "Status: " . $lesson->status . "\n";
    echo "Published_at: " . ($lesson->published_at ?? 'NULL') . "\n";
    echo "Enseignant ID: " . $lesson->enseignant_id . "\n";
    echo "Matière ID: " . ($lesson->matiere_id ?? 'NULL') . "\n";
    echo "Classe ID: " . ($lesson->classe_id ?? 'NULL') . "\n";
    echo "Content Type: " . ($lesson->content_type ?? 'NULL') . "\n";

    // Vérifier si la leçon est publiée
    if ($lesson->status === 'published' && $lesson->published_at) {
        echo "✓ VISIBLE pour les étudiants\n";
    } else {
        echo "✗ NON VISIBLE (status: " . $lesson->status . ", published_at: " . ($lesson->published_at ?? 'NULL') . ")\n";
    }
    echo "\n";
}

echo "\n=== RÉSUMÉ ===\n";
$published = Lesson::where('status', 'published')->whereNotNull('published_at')->count();
$draft = Lesson::where('status', 'draft')->count();
$archived = Lesson::where('status', 'archived')->count();

echo "Publiées: " . $published . "\n";
echo "Brouillons: " . $draft . "\n";
echo "Archivées: " . $archived . "\n";
