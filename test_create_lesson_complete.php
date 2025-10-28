<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Lesson;
use App\Models\LessonResource;

// Créer une leçon vidéo complète
$lesson = Lesson::create([
    'matiere_id' => 1, // Marketing digital
    'enseignant_id' => 9, // BEDE ABEL TEST
    'chapter_id' => 2, // Le chapitre qu'on vient de créer
    'title' => 'Les Réseaux Sociaux : Instagram et Facebook',
    'description' => 'Apprenez à créer et gérer des campagnes publicitaires efficaces sur Instagram et Facebook. Découvrez les meilleures pratiques, le ciblage avancé et l\'analyse des performances.',
    'content' => '<h2>Objectifs de la leçon</h2>
<ul>
<li>Comprendre les algorithmes des réseaux sociaux</li>
<li>Créer du contenu engageant</li>
<li>Maîtriser le ciblage publicitaire</li>
<li>Analyser les statistiques et KPIs</li>
</ul>

<h2>Points clés</h2>
<p>Les réseaux sociaux sont devenus incontournables dans toute stratégie marketing digitale moderne.</p>',
    'content_type' => 'video',
    'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'video_provider' => 'youtube',
    'type' => 'cours',
    'status' => 'published', // IMPORTANT: publié pour que les étudiants puissent y accéder
    'duration_minutes' => 45,
    'order' => 1
]);

echo "✓ Leçon vidéo créée avec succès!" . PHP_EOL;
echo "ID: " . $lesson->id . PHP_EOL;
echo "Titre: " . $lesson->title . PHP_EOL;
echo "Type de contenu: " . $lesson->content_type . PHP_EOL;
echo "Statut: " . $lesson->status . PHP_EOL;
echo "URL vidéo: " . $lesson->video_url . PHP_EOL;
echo PHP_EOL;

// Ajouter des ressources supplémentaires
$resource1 = LessonResource::create([
    'lesson_id' => $lesson->id,
    'title' => 'Guide complet Instagram Ads 2024',
    'description' => 'Document PDF avec toutes les spécifications techniques et bonnes pratiques',
    'type' => 'pdf',
    'url' => 'https://example.com/guides/instagram-ads-guide-2024.pdf',
    'order' => 1
]);

echo "✓ Ressource 1 ajoutée: " . $resource1->title . PHP_EOL;

$resource2 = LessonResource::create([
    'lesson_id' => $lesson->id,
    'title' => 'Modèle de calendrier éditorial',
    'description' => 'Template Excel pour planifier vos publications sur les réseaux sociaux',
    'type' => 'document',
    'url' => 'https://example.com/templates/calendrier-editorial.xlsx',
    'order' => 2
]);

echo "✓ Ressource 2 ajoutée: " . $resource2->title . PHP_EOL;

$resource3 = LessonResource::create([
    'lesson_id' => $lesson->id,
    'title' => 'Facebook Business Manager - Documentation officielle',
    'description' => 'Lien vers la documentation complète de Facebook pour les professionnels',
    'type' => 'link',
    'url' => 'https://www.facebook.com/business/help',
    'order' => 3
]);

echo "✓ Ressource 3 ajoutée: " . $resource3->title . PHP_EOL;
echo PHP_EOL;

// Recharger la leçon avec ses relations
$lesson = Lesson::with(['chapter', 'resources'])->find($lesson->id);

echo "=== LEÇON COMPLÈTE ===" . PHP_EOL;
echo json_encode($lesson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
