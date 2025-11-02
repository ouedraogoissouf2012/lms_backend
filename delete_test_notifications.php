<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Notification;

echo "=== SUPPRESSION DES NOTIFICATIONS DE TEST ===\n\n";

// Compter avant suppression
$count = Notification::where('user_id', 3)->count();
echo "Notifications avant suppression: {$count}\n";

// Afficher les notifications à supprimer
$notifs = Notification::where('user_id', 3)->get();
foreach ($notifs as $n) {
    echo "  - ID:{$n->id} | {$n->title}\n";
}

echo "\nSuppression en cours...\n";

// Supprimer toutes les notifications de l'utilisateur 3
$deleted = Notification::where('user_id', 3)->delete();

echo "✅ {$deleted} notification(s) supprimée(s)\n\n";

// Vérifier après suppression
$remaining = Notification::where('user_id', 3)->count();
echo "Notifications restantes: {$remaining}\n";

if ($remaining === 0) {
    echo "✅ Toutes les notifications de test ont été supprimées avec succès!\n";
} else {
    echo "⚠️  Il reste encore {$remaining} notification(s)\n";
}
