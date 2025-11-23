<?php

/**
 * Vérifier ce que l'API KlassCI retourne EXACTEMENT pour une classe
 */

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Http;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Récupérer un user avec token
$user = \App\Models\User::find(2); // Le coordinateur

if (!$user || !$user->klassci_token) {
    echo "❌ User ou token non trouvé\n";
    exit(1);
}

$klassciService = app(\App\Services\KlassciProxyService::class);

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  VÉRIFICATION API KLASSCI - Structure complète classe           ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

try {
    // Appel avec ?with=filiere,niveau
    echo "📡 Appel: GET /classes/1?with=filiere,niveau\n\n";

    $response = $klassciService->requestWithUserToken(
        $user->klassci_token,
        'classes/1?with=filiere,niveau',
        'GET'
    );

    echo "✅ Réponse reçue\n\n";
    echo "Structure complète:\n";
    echo str_repeat('─', 70) . "\n";
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo "\n" . str_repeat('─', 70) . "\n\n";

    // Analyser la structure
    if (isset($response['data'])) {
        $data = $response['data'];

        echo "📋 Clés dans 'data': " . implode(', ', array_keys($data)) . "\n\n";

        // Vérifier si 'matieres' est présent
        if (isset($data['matieres'])) {
            echo "✅ 'matieres' trouvé dans data\n";
            echo "   Nombre: " . count($data['matieres']) . "\n\n";

            if (count($data['matieres']) > 0) {
                $firstMatiere = $data['matieres'][0];
                echo "   Structure première matière:\n";
                echo "   " . str_repeat('─', 60) . "\n";
                echo "   " . json_encode($firstMatiere, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
                echo "   " . str_repeat('─', 60) . "\n\n";

                // Vérifier les enseignants
                if (isset($firstMatiere['enseignants'])) {
                    echo "   ✅ Champ 'enseignants' présent\n";
                    echo "   Type: " . gettype($firstMatiere['enseignants']) . "\n";

                    if (is_array($firstMatiere['enseignants'])) {
                        echo "   Nombre: " . count($firstMatiere['enseignants']) . "\n";

                        if (count($firstMatiere['enseignants']) > 0) {
                            echo "\n   🎯 ENSEIGNANTS TROUVÉS!\n\n";
                            foreach ($firstMatiere['enseignants'] as $ens) {
                                echo "   → " . ($ens['nom'] ?? $ens['name'] ?? 'N/A') . "\n";
                                echo "      ID: " . ($ens['id'] ?? 'N/A') . "\n";
                                echo "      Email: " . ($ens['email'] ?? 'N/A') . "\n";
                            }
                        } else {
                            echo "   ⚠️  Tableau vide\n";
                        }
                    }
                } else {
                    echo "   ❌ Champ 'enseignants' ABSENT\n";

                    echo "\n   Clés disponibles dans matière: " . implode(', ', array_keys($firstMatiere)) . "\n";
                }
            }
        } else {
            echo "❌ 'matieres' NON trouvé dans data\n";
            echo "   Clés disponibles: " . implode(', ', array_keys($data)) . "\n";
        }
    }

    echo "\n╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║  TEST ALTERNATIF: Essayer d'autres paramètres with              ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

    // Essayer avec d'autres paramètres
    $variations = [
        'classes/1?with=filiere,niveau,matieres',
        'classes/1?with=filiere,niveau,matieres.enseignants',
        'classes/1?with=matieres,enseignants',
        'classes/1?include=matieres.enseignants',
    ];

    foreach ($variations as $endpoint) {
        echo "🔍 Test: GET /{$endpoint}\n";

        try {
            $testResponse = $klassciService->requestWithUserToken(
                $user->klassci_token,
                $endpoint,
                'GET'
            );

            if (isset($testResponse['data']['matieres'])) {
                $matieres = $testResponse['data']['matieres'];
                echo "   ✅ Matières: " . count($matieres) . "\n";

                if (count($matieres) > 0 && isset($matieres[0]['enseignants'])) {
                    $nbEns = count($matieres[0]['enseignants']);
                    echo "   Enseignants première matière: {$nbEns}\n";

                    if ($nbEns > 0) {
                        echo "   🎯 TROUVÉ AVEC CET ENDPOINT!\n";
                    }
                }
            } else {
                echo "   ⚠️  Pas de matières\n";
            }

        } catch (Exception $e) {
            echo "   ❌ Erreur: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

} catch (Exception $e) {
    echo "❌ ERREUR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  FIN DE LA VÉRIFICATION                                          ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
