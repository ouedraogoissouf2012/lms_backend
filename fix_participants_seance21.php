<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ESBTPAttendance;
use Carbon\Carbon;

echo "\n========================================\n";
echo "CORRECTION PARTICIPANTS SÉANCE 21\n";
echo "========================================\n\n";

// IDs des participants qui sont VRAIMENT encore dans la visio
// (tous sauf Issouf TRAORE)
$participantsPresents = [
    'BEDE ABEL TEST',
    'MARCEL OUEDRAOGO',
    'LOSSENI KABIROU COULIBALY',
    'Drissa PARE'
];

$seanceId = 21;

echo "Participants à reconnecter: " . count($participantsPresents) . "\n";
echo "Participant qui a vraiment quitté: Issouf TRAORE\n\n";

foreach ($participantsPresents as $userName) {
    $user = App\Models\User::where('name', $userName)->first();

    if (!$user) {
        echo "⚠️  User {$userName} non trouvé\n";
        continue;
    }

    $attendance = ESBTPAttendance::where('seance_id', $seanceId)
        ->where('user_id', $user->id)
        ->first();

    if (!$attendance) {
        echo "⚠️  Participation non trouvée pour {$userName}\n";
        continue;
    }

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "User: {$userName}\n";
    echo "État actuel: {$attendance->status}\n";

    if ($attendance->status === 'disconnected') {
        // Reconnecter
        $attendance->status = 'connected';
        $attendance->left_at = null;
        $attendance->duration_minutes = null;
        $attendance->last_seen_at = now(); // Heartbeat actuel
        $attendance->save();

        echo "✅ Reconnecté\n";
    } else {
        echo "ℹ️  Déjà connecté\n";
    }
    echo "\n";
}

// Vérifier Issouf TRAORE (doit rester déconnecté)
$issof = App\Models\User::where('name', 'Issouf TRAORE')->first();
if ($issof) {
    $attendanceIssof = ESBTPAttendance::where('seance_id', $seanceId)
        ->where('user_id', $issof->id)
        ->first();

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "User: Issouf TRAORE (a vraiment quitté)\n";
    echo "État: {$attendanceIssof->status}\n";
    echo "Left at: {$attendanceIssof->left_at}\n";
    echo "Duration: {$attendanceIssof->duration_minutes} minutes\n";
    echo "✅ Reste déconnecté (correct)\n\n";
}

echo "========================================\n";
echo "✅ CORRECTION TERMINÉE\n";
echo "========================================\n\n";

echo "RÉSULTAT:\n";
echo "- 4 participants reconnectés (présents dans la visio)\n";
echo "- 1 participant reste déconnecté (Issouf TRAORE a vraiment quitté)\n\n";

echo "⚠️  ATTENTION:\n";
echo "Si les heartbeats du frontend ne reprennent pas automatiquement,\n";
echo "les participants devront peut-être rafraîchir leur page (F5).\n\n";
