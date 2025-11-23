<?php

require __DIR__ . '/vendor/autoload.php';

use App\Services\KlassciProxyService;
use App\Models\User;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== ANALYSE SÉANCE 57 ===\n\n";

$teacher = User::where('role', 'enseignant')->whereNotNull('klassci_token')->first();
$service = app(KlassciProxyService::class);

try {
    // Récupérer toutes les matières
    $matieres = $service->requestWithUserToken($teacher->klassci_token, 'matieres', 'GET');

    foreach ($matieres['data'] as $matiere) {
        $details = $service->requestWithUserToken(
            $teacher->klassci_token,
            'matieres/' . $matiere['id'],
            'GET'
        );

        $seances = $details['data']['seances_programmees'] ?? [];

        foreach ($seances as $seance) {
            if ($seance['id'] == 57) {
                echo "✓ SÉANCE 57 TROUVÉE DANS KLASSCI!\n\n";
                echo "Matière: {$matiere['nom']} (ID: {$matiere['id']})\n";
                echo "Classe: " . ($seance['classe']['nom'] ?? 'N/A') . " (ID: " . ($seance['classe']['id'] ?? 'N/A') . ")\n";
                echo "Salle: " . ($seance['programmation']['salle'] ?? 'N/A') . "\n";
                echo "Date: " . ($seance['programmation']['date'] ?? 'N/A') . "\n";
                echo "Heure début: " . ($seance['programmation']['heure_debut'] ?? 'N/A') . "\n\n";

                echo "Détails complets:\n";
                echo json_encode($seance, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                echo "\n\n";
            }
        }
    }

} catch (Exception $e) {
    echo "Erreur: {$e->getMessage()}\n";
}
