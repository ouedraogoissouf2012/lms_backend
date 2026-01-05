<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ESBTPAttendance;

$attendance = ESBTPAttendance::where('seance_id', 21)
    ->where('user_id', 2)
    ->first();

if ($attendance) {
    echo "User: BEDE ABEL TEST (ID: 2)\n";
    echo "État avant: {$attendance->status}\n";

    $attendance->status = 'connected';
    $attendance->left_at = null;
    $attendance->duration_minutes = null;
    $attendance->last_seen_at = now();
    $attendance->save();

    echo "État après: connected\n";
    echo "✅ Reconnecté\n";
} else {
    echo "❌ Participation non trouvée\n";
}
