<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$seances = App\Models\Seance::withTrashed()->where('klassci_seance_id', 19)->get();
echo "Entrées trouvées: " . $seances->count() . "\n";
foreach ($seances as $seance) {
    echo "  ID: {$seance->id}\n";
    echo "  Status: " . ($seance->visio_status ?? 'NULL') . "\n";
    echo "  Deleted: " . ($seance->deleted_at ? 'OUI (' . $seance->deleted_at . ')' : 'NON') . "\n";
    echo "\n";
}

// Vraiment supprimer
echo "Suppression définitive...\n";
App\Models\Seance::withTrashed()->where('klassci_seance_id', 19)->forceDelete();
echo "✅ Supprimé\n";
