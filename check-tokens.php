<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$users = App\Models\User::with('tokens')->get();

echo "=== TOKENS SANCTUM ===" . PHP_EOL . PHP_EOL;

foreach ($users as $user) {
    echo "User: {$user->name} (ID: {$user->id}, Role: {$user->role})" . PHP_EOL;
    echo "Tokens Sanctum: " . $user->tokens->count() . PHP_EOL;

    if ($user->tokens->count() > 0) {
        foreach ($user->tokens as $token) {
            echo "  - Token ID: {$token->id}" . PHP_EOL;
            echo "    Nom: {$token->name}" . PHP_EOL;
            echo "    Créé: {$token->created_at}" . PHP_EOL;
            echo "    Utilisé: {$token->last_used_at}" . PHP_EOL;
            echo "    Aperçu: " . substr($token->token, 0, 20) . "..." . PHP_EOL;
        }
    }
    echo "---" . PHP_EOL;
}
