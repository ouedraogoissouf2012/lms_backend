<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$chapter = \App\Models\Chapter::where('content_type', 'video')->orderBy('id', 'desc')->first();
if ($chapter) {
    echo "Dernier chapitre vidéo:\n";
    echo "ID: " . $chapter->id . "\n";
    echo "Title: " . $chapter->title . "\n";
    echo "Content (URL): " . ($chapter->content ?? 'NULL') . "\n";
    echo "Video URL: " . ($chapter->video_url ?? 'NULL') . "\n";
} else {
    echo "Aucun chapitre vidéo trouvé\n";
}
