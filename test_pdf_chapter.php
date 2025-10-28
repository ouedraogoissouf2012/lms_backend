<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Test URL generation pour chapitre PDF:\n";
echo "=====================================\n\n";

$chapter = \App\Models\Chapter::where('content_type', 'pdf')->first();

if ($chapter) {
    echo "Chapter ID: " . $chapter->id . "\n";
    echo "Title: " . $chapter->title . "\n";
    echo "PDF URL: " . ($chapter->pdf_url ?? 'NULL') . "\n";
    echo "File original path: " . ($chapter->file_original_path ?? 'NULL') . "\n";
    echo "Content type: " . $chapter->content_type . "\n";

    // Générer une URL complète
    if ($chapter->file_original_path) {
        $fullUrl = url('storage/' . $chapter->file_original_path);
        echo "URL complète générée: " . $fullUrl . "\n";
    }
} else {
    echo "Aucun chapitre PDF trouvé dans la base de données\n";
}
