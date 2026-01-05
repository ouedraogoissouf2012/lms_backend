<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$seance = App\Models\Seance::find(16);

if ($seance) {
    $seance->visio_active = false;
    $seance->save();
    echo "✅ Séance 16 (ID KLASSCI: {$seance->klassci_seance_id}) désactivée\n";
} else {
    echo "❌ Séance 16 non trouvée\n";
}
