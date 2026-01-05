<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$participants = App\Models\ESBTPAttendance::where('seance_id', 21)->get();

foreach ($participants as $p) {
    $user = App\Models\User::find($p->user_id);
    echo "User ID: {$p->user_id}\n";
    echo "Name: " . ($user ? $user->name : 'N/A') . "\n";
    echo "Email: " . ($user ? $user->email : 'N/A') . "\n";
    echo "Status: {$p->status}\n\n";
}
