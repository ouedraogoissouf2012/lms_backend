<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Seance;
use Carbon\Carbon;

echo "\n========================================\n";
echo "TERMINER LA SÉANCE 67\n";
echo "========================================\n\n";

$seance = Seance::find(21);

if (!$seance) {
    echo "❌ Séance non trouvée\n";
    exit(1);
}

echo "Séance ID: {$seance->id} (KLASSCI ID: {$seance->klassci_seance_id})\n";
echo "Démarrée: {$seance->visio_started_at}\n\n";

// Trouver quand le dernier participant a quitté
$dernierDepart = App\Models\ESBTPAttendance::where('seance_id', $seance->id)
    ->whereNotNull('left_at')
    ->orderBy('left_at', 'desc')
    ->first();

if ($dernierDepart) {
    $visioEndedAt = $dernierDepart->left_at;
    echo "Dernier participant quitté: {$visioEndedAt}\n";
} else {
    // Fallback: utiliser maintenant
    $visioEndedAt = now();
    echo "⚠️  Aucun départ enregistré, utiliser maintenant: {$visioEndedAt}\n";
}

echo "\nTerminer la séance...\n";

$seance->visio_ended_at = $visioEndedAt;
$seance->visio_active = false;
$seance->save();

echo "✅ Séance terminée\n";
echo "   - visio_ended_at: {$seance->visio_ended_at}\n";
echo "   - visio_active: false\n\n";

$dureeTotale = $seance->visio_started_at->diffInMinutes($seance->visio_ended_at);
echo "Durée totale de la séance: {$dureeTotale} minutes\n\n";

echo "========================================\n";
echo "✅ SÉANCE 67 TERMINÉE\n";
echo "========================================\n\n";
