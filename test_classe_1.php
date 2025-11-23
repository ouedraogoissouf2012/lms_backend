<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\KlassciProxyService;
use App\Models\User;

$user = User::where('role', 'enseignant')->whereNotNull('klassci_token')->first();
$service = app(KlassciProxyService::class);

try {
    $response = $service->requestWithUserToken($user->klassci_token, 'classes/1', 'GET');
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo "Erreur: {$e->getMessage()}\n";
}
