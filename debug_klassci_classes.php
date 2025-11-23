<?php

/**
 * Script de debug pour voir les classes retournées par Klassci
 */

require __DIR__ . '/vendor/autoload.php';

use App\Services\KlassciProxyService;
use App\Models\User;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Debug classes Klassci ===\n\n";

// Trouver un enseignant avec token
$teacher = User::where('role', 'enseignant')
    ->whereNotNull('klassci_token')
    ->first();

if (!$teacher) {
    echo "Aucun enseignant trouvé\n";
    exit(1);
}

echo "Enseignant: {$teacher->name}\n";
echo "Token: " . substr($teacher->klassci_token, 0, 20) . "...\n\n";

$klassciService = app(KlassciProxyService::class);

// Test 1: /classes
echo "1. Test GET /classes...\n";
try {
    $response = $klassciService->requestWithUserToken(
        $teacher->klassci_token,
        'classes',
        'GET'
    );

    echo "   ✓ Succès!\n";
    echo "   Structure de la réponse:\n";
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo "\n\n";

} catch (\Exception $e) {
    echo "   ✗ Erreur: {$e->getMessage()}\n\n";
}

// Test 2: /matieres
echo "2. Test GET /matieres...\n";
try {
    $response = $klassciService->requestWithUserToken(
        $teacher->klassci_token,
        'matieres',
        'GET'
    );

    echo "   ✓ Succès!\n";
    $matieres = $response['data'] ?? [];
    echo "   Nombre de matières: " . count($matieres) . "\n";

    if (!empty($matieres)) {
        $firstMatiere = $matieres[0];
        echo "   Première matière (structure):\n";
        echo json_encode($firstMatiere, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        echo "\n\n";

        // Test 3: Détails d'une matière
        if (isset($firstMatiere['id'])) {
            echo "3. Test GET /matieres/{$firstMatiere['id']}...\n";
            try {
                $details = $klassciService->requestWithUserToken(
                    $teacher->klassci_token,
                    "matieres/{$firstMatiere['id']}",
                    'GET'
                );

                echo "   ✓ Succès!\n";
                echo "   Structure complète:\n";
                echo json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                echo "\n\n";

            } catch (\Exception $e) {
                echo "   ✗ Erreur: {$e->getMessage()}\n\n";
            }
        }
    }

} catch (\Exception $e) {
    echo "   ✗ Erreur: {$e->getMessage()}\n\n";
}

echo "=== Fin ===\n";
