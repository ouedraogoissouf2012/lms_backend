<?php
/**
 * Investigation FINALE - Contenu de prochaines_seances
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Support\Facades\Cache;

echo "============================================\n";
echo "    CONTENU DE PROCHAINES_SEANCES\n";
echo "============================================\n\n";

Cache::flush();

$klassciService = app(KlassciProxyService::class);
$bede = User::find(2);

try {
    $response = $klassciService->requestWithUserToken(
        $bede->klassci_token,
        'me/teacher-dashboard',
        'GET',
        [],
        true
    );

    $data = $response['data'];

    echo "=== PROCHAINES_SEANCES (ce que l'API retourne) ===\n\n";
    $prochaines = $data['prochaines_seances'] ?? [];
    echo "Nombre: " . count($prochaines) . "\n\n";

    foreach ($prochaines as $i => $seance) {
        echo "--- Seance " . ($i + 1) . " ---\n";
        echo json_encode($seance, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    echo "\n=== COMPARAISON ===\n";
    echo "API retourne: data.prochaines_seances (" . count($prochaines) . " seances)\n";
    echo "Frontend cherche: dashboardData.seances (0 seances car le champ n'existe pas)\n";

    echo "\n=== SOLUTION ===\n";
    echo "Le frontend TeacherDashboard.vue ligne 70 fait:\n";
    echo "  {{ dashboardData.seances?.length || 0 }}\n\n";
    echo "Mais devrait faire:\n";
    echo "  {{ dashboardData.prochaines_seances?.length || 0 }}\n";

} catch (Exception $e) {
    echo "ERREUR: " . $e->getMessage() . "\n";
}

echo "\n============================================\n";
echo "    FIN\n";
echo "============================================\n";
