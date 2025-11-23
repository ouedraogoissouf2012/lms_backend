<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use Illuminate\Http\Request;
use App\Http\Controllers\API\LMSDataController;
use App\Models\User;

echo "=== TEST API ATTENDANCE HISTORY ===\n\n";

// Trouver un utilisateur de test
$user = User::whereIn('role', ['coordinateur', 'superAdmin'])->first();

if (!$user) {
    $user = User::first();
}

if (!$user) {
    echo "❌ Aucun utilisateur trouvé\n";
    exit;
}

echo "👤 Utilisateur: {$user->name} ({$user->role})\n\n";

// Créer une fausse requête
$request = Request::create('/api/lms/attendance/history', 'GET', [
    'page' => 1,
    'per_page' => 10
]);

// Authentifier l'utilisateur
$request->setUserResolver(function() use ($user) {
    return $user;
});

// Appeler le contrôleur
$controller = new LMSDataController();

try {
    echo "📡 Appel de getAttendanceHistory()...\n\n";

    $response = $controller->getAttendanceHistory($request);
    $data = json_decode($response->getContent(), true);

    echo "✅ Réponse reçue:\n";
    echo "   Success: " . ($data['success'] ? 'OUI' : 'NON') . "\n";

    if (isset($data['data'])) {
        echo "   Nombre de résultats: " . count($data['data']) . "\n";
    }

    if (isset($data['pagination'])) {
        echo "   Total: " . $data['pagination']['total'] . "\n";
        echo "   Page: " . $data['pagination']['current_page'] . "/" . $data['pagination']['last_page'] . "\n";
    }

    if (isset($data['data']) && count($data['data']) > 0) {
        echo "\n   Premier résultat:\n";
        $first = $data['data'][0];
        echo "   - ID: " . $first['id'] . "\n";
        echo "   - User: " . $first['user']['name'] . "\n";
        echo "   - Séance: " . $first['seance']['klassci_seance_id'] . "\n";
        echo "   - Status: " . $first['status'] . "\n";
        echo "   - Joined: " . $first['joined_at'] . "\n";
    }

    if (isset($data['message'])) {
        echo "\n   Message: " . $data['message'] . "\n";
    }

} catch (\Exception $e) {
    echo "❌ ERREUR:\n";
    echo "   Message: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\n   Stack trace:\n";
    echo $e->getTraceAsString();
}

echo "\n\n=== FIN TEST ===\n";
