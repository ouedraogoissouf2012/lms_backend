<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$seance = App\Models\Seance::find(21);

echo "=== CHAMPS DE LA SÉANCE 67 ===\n\n";
echo "ID: {$seance->id}\n";
echo "KLASSCI ID: {$seance->klassci_seance_id}\n";
echo "date_seance: " . ($seance->date_seance ?? 'NULL') . "\n";
echo "heure_debut: " . ($seance->heure_debut ?? 'NULL') . "\n";
echo "heure_fin: " . ($seance->heure_fin ?? 'NULL') . "\n";
echo "visio_started_at: {$seance->visio_started_at}\n";
echo "visio_ended_at: " . ($seance->visio_ended_at ?? 'NULL') . "\n";
echo "visio_active: " . ($seance->visio_active ? 'true' : 'false') . "\n";
