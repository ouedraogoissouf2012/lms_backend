<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Correction des URLs des chapitres PDF:\n";
echo "======================================\n\n";

$chapters = \App\Models\Chapter::where('content_type', 'pdf')
    ->whereNotNull('file_original_path')
    ->get();

echo "Nombre de chapitres PDF trouvés: " . $chapters->count() . "\n\n";

foreach ($chapters as $chapter) {
    $oldUrl = $chapter->pdf_url;

    // Générer la nouvelle URL avec le bon port
    $newUrl = url('storage/' . $chapter->file_original_path);

    $chapter->pdf_url = $newUrl;
    $chapter->save();

    echo "Chapter ID " . $chapter->id . " - " . $chapter->title . "\n";
    echo "  Ancienne URL: " . $oldUrl . "\n";
    echo "  Nouvelle URL: " . $newUrl . "\n\n";
}

echo "✓ Correction terminée!\n";
