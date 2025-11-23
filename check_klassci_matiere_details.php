<?php

$token = '13|b9DLXrfhWnHdqYx3da3GbjRKoYvzH89Vs5xt8Jrdfc0ecc2a';
$baseUrl = 'http://localhost:8000/api';

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  VÉRIFICATION DÉTAILS MATIÈRE DEPUIS KLASSCI                     ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

// Test 1: Endpoint /matieres
echo "📍 TEST 1: GET /matieres\n";
echo str_repeat('─', 70) . "\n";

$ch = curl_init($baseUrl . '/matieres');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Accept: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($response, true);

if ($httpCode === 200 && isset($data['data'][0])) {
    $matiere = $data['data'][0];
    echo "Matière: {$matiere['nom']} (ID: {$matiere['id']})\n";
    echo "Champs disponibles:\n";
    foreach ($matiere as $key => $value) {
        if (!is_array($value)) {
            echo "  - $key: $value\n";
        } else {
            echo "  - $key: [array avec " . count($value) . " élément(s)]\n";
        }
    }
} else {
    echo "❌ Erreur HTTP $httpCode\n";
}

echo "\n" . str_repeat('═', 70) . "\n\n";

// Test 2: Endpoint /matieres/{id} (détails)
echo "📍 TEST 2: GET /matieres/1 (détails)\n";
echo str_repeat('─', 70) . "\n";

$ch = curl_init($baseUrl . '/matieres/1');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Accept: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($response, true);

if ($httpCode === 200) {
    echo "✅ Réponse reçue\n";
    $matiere = $data['data'] ?? $data;

    echo "\nChamps dans la réponse détaillée:\n";
    foreach ($matiere as $key => $value) {
        if (!is_array($value)) {
            echo "  - $key: $value\n";
        } else {
            echo "  - $key: [array avec " . count($value) . " élément(s)]\n";
            if ($key === 'combinaisons' && count($value) > 0) {
                echo "    Exemple combinaison[0]:\n";
                foreach ($value[0] as $k => $v) {
                    if (!is_array($v)) {
                        echo "      - $k: $v\n";
                    } else {
                        echo "      - $k: [object]\n";
                    }
                }
            }
        }
    }

    // Chercher spécifiquement les champs de stats
    echo "\n🔍 RECHERCHE de statistiques:\n";
    $statsFields = ['nb_seances', 'nb_seances_programmees', 'nb_lecons', 'nb_evaluations', 'nb_lessons', 'evaluations_count', 'lessons_count', 'seances_count'];
    foreach ($statsFields as $field) {
        if (isset($matiere[$field])) {
            echo "  ✅ $field: {$matiere[$field]}\n";
        } else {
            echo "  ❌ $field: non trouvé\n";
        }
    }
} else {
    echo "❌ Erreur HTTP $httpCode\n";
    echo "Réponse: $response\n";
}

echo "\n╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  FIN                                                             ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
