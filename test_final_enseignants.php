<?php

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
echo "║  TEST FINAL - API AVEC ASSIGNATIONS LOCALES                     ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

$result = makeRequest('/lms/classes/1', $token, $baseUrl);

echo "HTTP Code: " . $result['http_code'] . "\n\n";

if ($result['http_code'] === 200 && isset($result['response']['success'])) {
    $data = $result['response']['data'];
    $matieres = $data['matieres'] ?? [];

    echo "✅ API fonctionne!\n";
    echo "Nombre de matières: " . count($matieres) . "\n\n";

    echo str_repeat('═', 70) . "\n";

    foreach ($matieres as $matiere) {
        echo "\n📚 " . $matiere['nom'] . " (ID: {$matiere['id']})\n";
        echo "   Code: " . $matiere['code'] . "\n";
        echo "   Coefficient: " . $matiere['coefficient'] . "\n";

        if (isset($matiere['enseignants'])) {
            $nbEns = count($matiere['enseignants']);
            echo "   👨‍🏫 Enseignants: {$nbEns}\n";

            if ($nbEns > 0) {
                echo "   🎯 SUCCÈS! Enseignants trouvés:\n";
                foreach ($matiere['enseignants'] as $ens) {
                    echo "      → " . ($ens['nom'] ?? 'N/A') . " (ID: {$ens['id']})\n";
                    echo "        Email: " . ($ens['email'] ?? 'N/A') . "\n";
                }
            } else {
                echo "      ⚠️  Aucun enseignant assigné\n";
            }
        } else {
            echo "   ❌ Pas de champ 'enseignants'\n";
        }

        echo "\n" . str_repeat('─', 70) . "\n";
    }

    echo "\n📊 RÉSUMÉ:\n";
    echo "   Marketing digital: " . (count($matieres[0]['enseignants'] ?? []) > 0 ? "✅ " . count($matieres[0]['enseignants']) . " enseignant(s)" : "❌ Aucun") . "\n";
    echo "   Algorithme: " . (count($matieres[1]['enseignants'] ?? []) > 0 ? "✅ " . count($matieres[1]['enseignants']) . " enseignant(s)" : "❌ Aucun") . "\n";
    echo "   Anglais: " . (count($matieres[2]['enseignants'] ?? []) > 0 ? "✅ " . count($matieres[2]['enseignants']) . " enseignant(s)" : "❌ Aucun") . "\n";

} else {
    echo "❌ Erreur HTTP " . $result['http_code'] . "\n";
    if (isset($result['response']['message'])) {
        echo "Message: " . $result['response']['message'] . "\n";
    }
}

echo "\n╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  FIN DU TEST                                                     ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
