<?php

require __DIR__ . '/vendor/autoload.php';

use App\Services\KlassciProxyService;
use App\Models\User;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Test API Klassci /classes/1 ===\n\n";

$teacher = User::where('role', 'enseignant')->whereNotNull('klassci_token')->first();
$service = app(KlassciProxyService::class);

try {
    echo "Appel: GET /classes/1\n\n";
    $response = $service->requestWithUserToken($teacher->klassci_token, 'classes/1', 'GET');

    echo "Réponse:\n";
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo "\n";

} catch (Exception $e) {
    echo "Erreur: {$e->getMessage()}\n";
}
