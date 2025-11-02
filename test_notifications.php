<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Notification;
use App\Models\User;

echo "=== TEST NOTIFICATIONS ===\n\n";

$user = User::find(3);
echo "User: {$user->name}\n\n";

$notifs = Notification::where('user_id', 3)->get();
echo "Total notifications: " . $notifs->count() . "\n";
echo "Non lues: " . $notifs->where('read_at', null)->count() . "\n";
echo "Lues: " . $notifs->whereNotNull('read_at')->count() . "\n\n";

echo "Détails:\n";
foreach ($notifs as $n) {
    $lue = $n->read_at ? 'OUI (' . $n->read_at . ')' : 'NON';
    echo "- ID:{$n->id} | {$n->title} | Lue: {$lue} | Créée: {$n->created_at}\n";
}
