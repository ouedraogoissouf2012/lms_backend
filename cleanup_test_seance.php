<?php

require __DIR__ . '/vendor/autoload.php';

use App\Models\Seance;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

Seance::where('klassci_seance_id', 99999)->forceDelete();

echo "✅ Séances de test nettoyées\n";
