<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Vérification des chapitres PDF récents:\n";
echo "========================================\n\n";

$chapters = \App\Models\Chapter::where('content_type', 'pdf')
    ->orderBy('id', 'desc')
    ->limit(5)
    ->get();

foreach ($chapters as $ch) {
    echo "Chapter ID: " . $ch->id . "\n";
    echo "  Title: " . $ch->title . "\n";
    echo "  Lesson ID: " . $ch->lesson_id . "\n";
    echo "  Slides count: " . ($ch->slides_count ?? 0) . "\n";
    echo "  Images array: " . (is_array($ch->slides_images) ? count($ch->slides_images) : 0) . " images\n";

    if ($ch->slides_images && count($ch->slides_images) > 0) {
        echo "  Premier fichier: " . $ch->slides_images[0] . "\n";
        $fullPath = storage_path('app/public/' . $ch->slides_images[0]);
        echo "  Fichier existe? " . (file_exists($fullPath) ? "✓ OUI" : "✗ NON") . "\n";
    }
    echo "\n";
}
