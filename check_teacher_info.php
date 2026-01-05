<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Vérification info enseignant pour séance #61:\n\n";

$seance = DB::table('seances')->where('klassci_seance_id', 61)->first();

// 1. Chercher dans esbtp_attendance avec is_observer = 1
$teacher = DB::table('esbtp_attendance')
    ->where('seance_id', $seance->id)
    ->where('is_observer', 1)
    ->first();

if ($teacher) {
    echo "✅ Enseignant trouvé dans esbtp_attendance:\n";
    echo "   Nom: {$teacher->nom}\n";
    echo "   Email: {$teacher->email}\n\n";
} else {
    echo "❌ Pas d'enseignant trouvé avec is_observer = 1\n\n";
}

// 2. Vérifier si seances a un champ enseignant
echo "Colonnes de la table seances:\n";
$columns = DB::select("PRAGMA table_info(seances)");
foreach ($columns as $col) {
    if (strpos($col->name, 'enseignant') !== false || strpos($col->name, 'teacher') !== false || strpos($col->name, 'prof') !== false) {
        echo "   ➤ {$col->name} ({$col->type})\n";
    }
}

// 3. Calculer durée totale séance
echo "\nDurée de la séance #61:\n";
echo "   Début: {$seance->visio_started_at}\n";
echo "   Fin: " . ($seance->visio_ended_at ?? 'NULL') . "\n";

if ($seance->visio_started_at && $seance->visio_ended_at) {
    $start = new DateTime($seance->visio_started_at);
    $end = new DateTime($seance->visio_ended_at);
    $diff = $start->diff($end);
    $totalMinutes = ($diff->h * 60) + $diff->i + ($diff->days * 24 * 60);
    echo "   Durée totale: {$totalMinutes} minutes ({$diff->h}h {$diff->i}min)\n";
}
