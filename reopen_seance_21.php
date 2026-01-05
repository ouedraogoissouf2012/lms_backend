<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Seance;
use App\Models\ESBTPAttendance;
use Carbon\Carbon;

echo "\n========================================\n";
echo "RÉOUVERTURE DE LA SÉANCE 21\n";
echo "========================================\n\n";

$seance = Seance::find(21);

if (!$seance) {
    echo "❌ Séance non trouvée\n";
    exit(1);
}

echo "Séance ID: {$seance->id} (KLASSCI ID: {$seance->klassci_seance_id})\n";
echo "État actuel:\n";
echo "  - visio_ended_at: {$seance->visio_ended_at}\n";
echo "  - visio_active: " . ($seance->visio_active ? 'true' : 'false') . "\n\n";

// 1. Réouvrir la séance
$seance->visio_ended_at = null;
$seance->visio_active = true;
$seance->save();

echo "✅ Séance réouverte\n";
echo "  - visio_ended_at: NULL\n";
echo "  - visio_active: true\n\n";

// 2. Reconnecter les participants qui étaient présents
$participants = ESBTPAttendance::where('seance_id', $seance->id)
    ->where('status', 'disconnected')
    ->get();

echo "Participants à reconnecter: {$participants->count()}\n\n";

foreach ($participants as $attendance) {
    $user = App\Models\User::find($attendance->user_id);

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "User: " . ($user ? $user->name : "ID {$attendance->user_id}") . "\n";
    echo "Avant:\n";
    echo "  - status: {$attendance->status}\n";
    echo "  - left_at: {$attendance->left_at}\n";
    echo "  - duration_minutes: {$attendance->duration_minutes}\n";

    // Reconnecter
    $attendance->status = 'connected';
    $attendance->left_at = null;
    $attendance->duration_minutes = null;
    $attendance->last_seen_at = now(); // Mettre à jour le heartbeat
    $attendance->save();

    echo "Après:\n";
    echo "  - status: connected\n";
    echo "  - left_at: NULL\n";
    echo "  - duration_minutes: NULL\n";
    echo "  - last_seen_at: " . now()->format('Y-m-d H:i:s') . "\n";
    echo "✅ Reconnecté\n\n";
}

echo "========================================\n";
echo "✅ SÉANCE 21 RÉOUVERTE\n";
echo "========================================\n\n";

echo "PROCHAINES ÉTAPES:\n";
echo "1. Les étudiants devraient maintenant apparaître comme connectés\n";
echo "2. Les heartbeats vont reprendre automatiquement\n";
echo "3. Quand vous terminerez la séance normalement, tout sera correct\n\n";

echo "⚠️  IMPORTANT:\n";
echo "Ne plus relancer le script fix_unfinished_seances.php pendant qu'une séance est en cours!\n";
echo "Le scheduler automatique gère maintenant les déconnexions proprement.\n\n";
