<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Notification;
use App\Models\User;

echo "=== VÉRIFICATION NOTIFICATIONS PAR UTILISATEUR ===\n\n";

$users = User::all();

foreach ($users as $user) {
    $count = Notification::where('user_id', $user->id)->count();

    if ($count > 0) {
        echo "User ID:{$user->id} | {$user->name} ({$user->role}) | Email: {$user->email}\n";
        echo "Notifications: {$count}\n";

        $notifs = Notification::where('user_id', $user->id)->get();
        foreach ($notifs as $n) {
            $lue = $n->read_at ? 'LUE' : 'NON LUE';
            echo "  - ID:{$n->id} | {$n->title} | {$lue}\n";
        }
        echo "\n";
    }
}

$totalNotifs = Notification::count();
echo "=== TOTAL NOTIFICATIONS DANS LA BASE: {$totalNotifs} ===\n";
