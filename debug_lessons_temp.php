<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$allLessons = \App\Models\Lesson::withTrashed()->where('matiere_id', 1)->get(['id', 'title', 'status', 'deleted_at']);
echo "Marketing digital - TOTAL: " . $allLessons->count() . " leçons\n\n";
$published = 0; $drafts = 0; $deleted = 0;
foreach ($allLessons as $l) {
    $isDel = $l->deleted_at !== null;
    echo "ID " . $l->id . ": " . $l->title . " | " . $l->status . " | Deleted: " . ($isDel ? "OUI" : "NON") . "\n";
    if ($isDel) $deleted++; elseif ($l->status === 'published') $published++; elseif ($l->status === 'draft') $drafts++;
}
echo "\nPubliées actives: $published | Brouillons actifs: $drafts | Supprimées: $deleted\n";
