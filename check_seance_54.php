<?php

require __DIR__ . '/vendor/autoload.php';

use App\Services\KlassciProxyService;
use App\Models\User;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Vérification Séance 54 ===\n\n";

$teacher = User::where('role', 'enseignant')->whereNotNull('klassci_token')->first();
$service = app(KlassciProxyService::class);

try {
    // Récupérer les détails de la matière
    $details = $service->requestWithUserToken(
        $teacher->klassci_token,
        'matieres/1',
        'GET'
    );

    $seances = $details['data']['seances_programmees'] ?? [];

    echo "Séances dans la matière Marketing digital:\n\n";

    foreach ($seances as $seance) {
        echo "ID: {$seance['id']}\n";
        echo "Visio enabled: " . (isset($seance['visio_enabled']) ? ($seance['visio_enabled'] ? 'OUI' : 'NON') : 'non défini') . "\n";

        if (isset($seance['date_heure'])) {
            echo "Date/Heure: {$seance['date_heure']}\n";
        }

        echo "\nDétails complets:\n";
        echo json_encode($seance, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        echo "\n\n---\n\n";
    }

} catch (Exception $e) {
    echo "Erreur: {$e->getMessage()}\n";
}
