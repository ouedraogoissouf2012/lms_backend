<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;

$user = User::where('name', 'LOSSENI KABIROU COULIBALY')->first();

if ($user) {
    echo "User trouvé:\n";
    echo "  ID: {$user->id}\n";
    echo "  Name: {$user->name}\n";
    echo "  Email: {$user->email}\n";
    echo "  Klassci ID: {$user->klassci_id}\n";
    echo "  Role: {$user->role}\n";
} else {
    echo "User non trouvé\n";
}
