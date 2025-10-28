<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== LESSONS MATIERE 1 (Marketing digital) AVEC DATES ===\n\n";

$lessons = DB::table('lessons')
    ->select('id', 'title', 'status', 'published_at', 'created_at')
    ->where('matiere_id', 1)
    ->get();

foreach ($lessons as $lesson) {
    echo sprintf(
        "ID: %d | Status: %s | Published_at: %s | Title: %s\n",
        $lesson->id,
        $lesson->status,
        $lesson->published_at ?? 'NULL',
        $lesson->title
    );
}

echo "\n=== AVEC SOFT DELETES ===\n\n";

$allLessons = \App\Models\Lesson::withTrashed()
    ->where('matiere_id', 1)
    ->get();

foreach ($allLessons as $lesson) {
    echo sprintf(
        "ID: %d | Status: %s | Deleted: %s | Title: %s\n",
        $lesson->id,
        $lesson->status,
        $lesson->deleted_at ? 'YES (' . $lesson->deleted_at->format('Y-m-d H:i') . ')' : 'NO',
        $lesson->title
    );
}

echo "\n=== TEST DU SCOPE published() ===\n\n";

$publishedLessons = \App\Models\Lesson::where('matiere_id', 1)
    ->published()
    ->get();

echo "Nombre de lessons PUBLISHED (avec scope): " . $publishedLessons->count() . "\n\n";

foreach ($publishedLessons as $lesson) {
    echo sprintf(
        "ID: %d | Title: %s | Published_at: %s\n",
        $lesson->id,
        $lesson->title,
        $lesson->published_at?->format('Y-m-d H:i:s') ?? 'NULL'
    );
}
