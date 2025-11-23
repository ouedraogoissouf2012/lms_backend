<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$seances = \App\Models\Seance::whereIn('id', [2, 3, 11])->get();

echo "Séances avec présences:\n\n";
foreach ($seances as $s) {
    echo "ID: {$s->id} | Klassci: {$s->klassci_seance_id}\n";
    echo "   Titre: {$s->titre}\n";
    echo "   Date: " . ($s->date_seance ?? 'NULL') . "\n";
    echo "   Heure début: " . ($s->heure_debut ?? 'NULL') . "\n";
    echo "   Heure fin: " . ($s->heure_fin ?? 'NULL') . "\n\n";
}
