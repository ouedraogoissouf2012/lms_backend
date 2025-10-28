<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$users = App\Models\User::all();

echo "Total users: " . $users->count() . PHP_EOL . PHP_EOL;

foreach ($users as $user) {
    echo "ID: {$user->id}" . PHP_EOL;
    echo "Name: {$user->name}" . PHP_EOL;
    echo "Email: {$user->email}" . PHP_EOL;
    echo "Role: {$user->role}" . PHP_EOL;
    echo "KLASSCI ID: {$user->klassci_id}" . PHP_EOL;
    echo "KLASSCI Token: " . (empty($user->klassci_token) ? 'VIDE ❌' : 'OK ✓') . PHP_EOL;
    echo "Sanctum Tokens: " . $user->tokens()->count() . PHP_EOL;
    echo "---" . PHP_EOL;
}
