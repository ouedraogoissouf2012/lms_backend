<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ESBTPAttendance;

$attendances = ESBTPAttendance::where('seance_id', 14)->orderBy('joined_at')->get();

echo "═══════════════════════════════════════════════════════════\n";
echo "   SÉANCE 14 - ANALYSE DES PRÉSENCES\n";
echo "═══════════════════════════════════════════════════════════\n\n";

foreach ($attendances as $a) {
    echo "Participant: {$a->nom}\n";
    echo "   User ID: {$a->user_id}\n";
    echo "   Joined:    {$a->joined_at}\n";
    echo "   Last seen: {$a->last_seen_at}\n";
    echo "   Left:      " . ($a->left_at ?? 'NULL') . "\n";
    echo "   Status:    {$a->status}\n";
    echo "   Duration:  {$a->duration_minutes} minutes\n";
    echo "   Même heure ? joined_at == last_seen_at : " . ($a->joined_at->equalTo($a->last_seen_at) ? 'OUI ❌' : 'NON ✅') . "\n";
    echo "\n";
}

echo "CONCLUSION:\n";
$sameTime = $attendances->filter(fn($a) => $a->joined_at->equalTo($a->last_seen_at))->count();
echo "   Participants avec joined_at == last_seen_at: {$sameTime} / {$attendances->count()}\n";
echo "   → Aucun heartbeat n'a été envoyé!\n";
