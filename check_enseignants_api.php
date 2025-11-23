<?php

/**
 * Vérifier l'endpoint /enseignants et voir comment les matières sont liées
 */

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = \App\Models\User::find(2);

if (!$user || !$user->klassci_token) {
    echo "❌ User ou token non trouvé\n";
    exit(1);
}

$klassciService = app(\App\Services\KlassciProxyService::class);

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  VÉRIFICATION ENDPOINT /enseignants                              ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

try {
    // Test 1: GET /enseignants
    echo "1️⃣ GET /enseignants\n";
    echo str_repeat('─', 70) . "\n";

    $response = $klassciService->requestWithUserToken(
        $user->klassci_token,
        'enseignants',
        'GET'
    );

    if (isset($response['data'])) {
        $enseignants = $response['data'];
        echo "✅ " . count($enseignants) . " enseignants trouvés\n\n";

        // Afficher les 3 premiers
        $limit = min(3, count($enseignants));

        for ($i = 0; $i < $limit; $i++) {
            $ens = $enseignants[$i];

            echo "👨‍🏫 Enseignant #" . ($i + 1) . ":\n";
            echo "   ID: " . ($ens['id'] ?? 'N/A') . "\n";
            echo "   Nom: " . ($ens['nom'] ?? $ens['name'] ?? 'N/A') . "\n";
            echo "   Email: " . ($ens['email'] ?? 'N/A') . "\n";

            // Vérifier si 'matieres' est présent
            if (isset($ens['matieres'])) {
                echo "   ✅ Champ 'matieres' présent\n";
                echo "   Type: " . gettype($ens['matieres']) . "\n";

                if (is_array($ens['matieres'])) {
                    echo "   Nombre: " . count($ens['matieres']) . "\n";

                    if (count($ens['matieres']) > 0) {
                        echo "   🎯 MATIÈRES TROUVÉES!\n";
                        foreach ($ens['matieres'] as $mat) {
                            echo "      → " . ($mat['nom'] ?? $mat['name'] ?? 'N/A') .
                                 " (ID: " . ($mat['id'] ?? 'N/A') . ")\n";
                        }
                    } else {
                        echo "   ⚠️  Tableau vide\n";
                    }
                }
            } else {
                echo "   ❌ Pas de champ 'matieres'\n";
                echo "   Clés: " . implode(', ', array_keys($ens)) . "\n";
            }

            echo "\n";
        }
    } else {
        echo "❌ Pas de data\n";
    }

    echo str_repeat('─', 70) . "\n\n";

    // Test 2: GET /enseignants avec paramètre ?with=matieres
    echo "2️⃣ GET /enseignants?with=matieres\n";
    echo str_repeat('─', 70) . "\n";

    $response2 = $klassciService->requestWithUserToken(
        $user->klassci_token,
        'enseignants?with=matieres',
        'GET'
    );

    if (isset($response2['data']) && count($response2['data']) > 0) {
        $firstEns = $response2['data'][0];

        echo "Structure premier enseignant:\n";
        echo json_encode($firstEns, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

        if (isset($firstEns['matieres']) && count($firstEns['matieres']) > 0) {
            echo "🎯 TROUVÉ! Les enseignants ont leurs matières avec ?with=matieres\n";
        }
    }

    echo str_repeat('─', 70) . "\n\n";

    // Test 3: GET /enseignants?filiere_id=1&niveau_id=1 (pour la classe B2 COM)
    echo "3️⃣ GET /enseignants?filiere_id=1&niveau_id=1\n";
    echo str_repeat('─', 70) . "\n";

    $response3 = $klassciService->requestWithUserToken(
        $user->klassci_token,
        'enseignants?filiere_id=1&niveau_id=1',
        'GET'
    );

    if (isset($response3['data'])) {
        echo "✅ " . count($response3['data']) . " enseignants pour filière=1, niveau=1\n";

        foreach ($response3['data'] as $ens) {
            echo "   → " . ($ens['nom'] ?? 'N/A');

            if (isset($ens['matieres'])) {
                $matiereNames = array_map(fn($m) => $m['nom'] ?? 'N/A', $ens['matieres']);
                echo " (" . count($ens['matieres']) . " matières: " . implode(', ', $matiereNames) . ")";
            }

            echo "\n";
        }
    }

    echo "\n" . str_repeat('─', 70) . "\n\n";

    // Test 4: Chercher quel enseignant a la matière ID=1 (Marketing digital)
    echo "4️⃣ Chercher quel enseignant a la matière 'Marketing digital' (ID=1)\n";
    echo str_repeat('─', 70) . "\n";

    if (isset($response2['data'])) {
        $found = false;

        foreach ($response2['data'] as $ens) {
            if (isset($ens['matieres']) && is_array($ens['matieres'])) {
                foreach ($ens['matieres'] as $mat) {
                    if (($mat['id'] ?? null) == 1) {
                        echo "🎯 TROUVÉ!\n";
                        echo "   Enseignant: " . ($ens['nom'] ?? 'N/A') . "\n";
                        echo "   Email: " . ($ens['email'] ?? 'N/A') . "\n";
                        echo "   Matière: " . ($mat['nom'] ?? 'N/A') . "\n";
                        $found = true;
                        break 2;
                    }
                }
            }
        }

        if (!$found) {
            echo "❌ Aucun enseignant trouvé pour la matière ID=1\n";
        }
    }

    echo "\n" . str_repeat('─', 70) . "\n";

} catch (Exception $e) {
    echo "❌ ERREUR: " . $e->getMessage() . "\n";
}

echo "\n╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  FIN                                                             ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
