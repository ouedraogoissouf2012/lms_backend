<?php
/**
 * Test API exacte - Simuler l'appel frontend
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\API\LMSDataController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;

echo "============================================\n";
echo "    TEST API /lms/seances/my-teaching\n";
echo "============================================\n\n";

Cache::flush();

$bede = User::find(2); // bede@gmail.com
Auth::login($bede);

echo "Utilisateur connecte: {$bede->email}\n\n";

// Creer une requete simulee
$request = Request::create('/api/lms/seances/my-teaching', 'GET');
$request->setUserResolver(function () use ($bede) {
    return $bede;
});

// Appeler le controleur
$controller = app(LMSDataController::class);

try {
    $response = $controller->myTeachingSeances($request);
    $content = json_decode($response->getContent(), true);

    echo "=== REPONSE API ===\n\n";
    echo "Success: " . ($content['success'] ? 'OUI' : 'NON') . "\n";
    echo "Nombre de seances: " . count($content['data'] ?? []) . "\n\n";

    if (!empty($content['data'])) {
        echo "=== SEANCES RETOURNEES ===\n\n";
        foreach ($content['data'] as $seance) {
            echo "Seance #{$seance['id']}:\n";
            echo json_encode($seance, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
        }
    } else {
        echo "AUCUNE SEANCE RETOURNEE!\n\n";

        // Debug: afficher toute la reponse
        echo "=== REPONSE COMPLETE ===\n";
        echo json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }

} catch (Exception $e) {
    echo "ERREUR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "\n============================================\n";
echo "    FIN TEST\n";
echo "============================================\n";
