<?php

require __DIR__ . '/vendor/autoload.php';

use App\Models\Seance;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$seance = Seance::first();

if ($seance) {
    echo "Première séance:\n";
    print_r($seance->toArray());
} else {
    echo "Aucune séance trouvée\n";
}
