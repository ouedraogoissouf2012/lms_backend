<?php

/**
 * Test endpoint /api/admin/matieres
 */

$token = '13|b9DLXrfhWnHdqYx3da3GbjRKoYvzH89Vs5xt8Jrdfc0ecc2a';
$baseUrl = 'http://localhost:8000/api';

function makeRequest($endpoint, $token, $baseUrl) {
    $ch = curl_init($baseUrl . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'response' => json_decode($response, true)
    ];
}

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  TEST ENDPOINT /api/admin/matieres                               ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

$result = makeRequest('/admin/matieres', $token, $baseUrl);

echo "HTTP Code: " . $result['http_code'] . "\n\n";

if ($result['http_code'] === 200 && isset($result['response']['success'])) {
    $data = $result['response']['data'];
    $matieres = $data['matieres'] ?? [];
    $stats = $data['statistiques'] ?? [];

    echo "✅ API fonctionne!\n";
    echo "Nombre de matières: " . count($matieres) . "\n";
    echo "Statistiques: Total={$stats['total']}, Heures={$stats['total_heures']}, Séances={$stats['total_seances']}\n\n";

    echo str_repeat('═', 70) . "\n";

    foreach ($matieres as $matiere) {
        echo "\n📚 " . $matiere['nom'] . " (ID: {$matiere['id']})\n";
        echo "   Code: " . $matiere['code'] . "\n";
        echo "   Description: " . ($matiere['description'] ? substr($matiere['description'], 0, 50) . '...' : 'N/A') . "\n";
        echo "   Coefficient: " . $matiere['coefficient'] . "\n";
        echo "   Couleur: " . $matiere['couleur'] . "\n";
        echo "   Heures total: " . $matiere['heures_total'] . "\n";
        echo "   Nb séances programmées: " . $matiere['nb_seances_programmees'] . "\n";

        // Check new fields
        if (isset($matiere['nb_lecons'])) {
            echo "   ✅ Nb leçons: " . $matiere['nb_lecons'] . "\n";
        } else {
            echo "   ❌ Nb leçons: MANQUANT\n";
        }

        if (isset($matiere['nb_evaluations'])) {
            echo "   ✅ Nb évaluations: " . $matiere['nb_evaluations'] . "\n";
        } else {
            echo "   ❌ Nb évaluations: MANQUANT\n";
        }

        echo "   Nb combinaisons: " . count($matiere['combinaisons'] ?? []) . "\n";

        if (!empty($matiere['combinaisons'])) {
            echo "   Combinaisons:\n";
            foreach ($matiere['combinaisons'] as $combo) {
                $filiere = $combo['filiere']['nom'] ?? $combo['filiere']['code'] ?? 'N/A';
                $niveau = $combo['niveau']['nom'] ?? $combo['niveau']['code'] ?? 'N/A';
                echo "      → {$filiere} / {$niveau}\n";
            }
        }

        echo "\n" . str_repeat('─', 70) . "\n";
    }

    echo "\n📊 RÉSUMÉ:\n";
    echo "   Total matières: " . count($matieres) . "\n";
    echo "   Champs 'nb_lecons': " . (isset($matieres[0]['nb_lecons']) ? "✅ Présent" : "❌ Manquant") . "\n";
    echo "   Champs 'nb_evaluations': " . (isset($matieres[0]['nb_evaluations']) ? "✅ Présent" : "❌ Manquant") . "\n";

} else {
    echo "❌ Erreur HTTP " . $result['http_code'] . "\n";
    if (isset($result['response']['message'])) {
        echo "Message: " . $result['response']['message'] . "\n";
    }
    if (isset($result['response']['error'])) {
        echo "Error: " . $result['response']['error'] . "\n";
    }
}

echo "\n╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  FIN DU TEST                                                     ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
