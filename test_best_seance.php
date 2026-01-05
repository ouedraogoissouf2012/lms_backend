<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

// Trouver la séance avec le plus de participants
$seance = DB::table('seances')
    ->whereNotNull('visio_started_at')
    ->leftJoin('esbtp_attendance', 'seances.id', '=', 'esbtp_attendance.seance_id')
    ->select('seances.*', DB::raw('COUNT(esbtp_attendance.id) as att_count'))
    ->groupBy('seances.id')
    ->orderBy('att_count', 'desc')
    ->first();

if (!$seance || $seance->att_count == 0) {
    echo "Aucune séance avec participants trouvée\n";
    exit(1);
}

echo "Séance #{$seance->klassci_seance_id} - {$seance->matiere_nom}\n";
echo "Participants: {$seance->att_count}\n\n";

$attendances = DB::table('esbtp_attendance')
    ->where('seance_id', $seance->id)
    ->orderBy('joined_at', 'desc')
    ->get();

echo "Aperçu modal:\n";
echo str_repeat('-', 80) . "\n";
foreach ($attendances as $att) {
    $status = ($att->duration_minutes > 3) ? '✅ Présent' : '❌ Absent';
    echo "{$att->nom} {$att->prenom} | {$att->duration_minutes} min | {$status}\n";
}
echo str_repeat('-', 80) . "\n";
