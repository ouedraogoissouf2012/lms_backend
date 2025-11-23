<?php

$token = '13|b9DLXrfhWnHdqYx3da3GbjRKoYvzH89Vs5xt8Jrdfc0ecc2a';
$baseUrl = 'http://localhost:8000/api';

echo "Recherche de séances disponibles...\n\n";

// Essayer plusieurs endpoints pour trouver des séances
$endpoints = [
    '/lms/seances/my-teaching',
    '/lms/seances/upcoming',
    '/lms/seances/my-classes'
];

foreach ($endpoints as $endpoint) {
    echo "═══════════════════════════════════════════════════════════════════\n";
    echo "Test: $endpoint\n";
    echo "═══════════════════════════════════════════════════════════════════\n\n";

    $ch = curl_init($baseUrl . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "HTTP: $httpCode\n";

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        $seances = $data['data'] ?? $data;

        if (is_array($seances) && count($seances) > 0) {
            echo "✅ Séances trouvées: " . count($seances) . "\n\n";

            foreach (array_slice($seances, 0, 5) as $seance) {
                echo "ID: {$seance['id']}\n";
                echo "Matière: " . ($seance['matiere']['nom'] ?? 'N/A') . "\n";
                echo "Classe: " . ($seance['classe']['nom'] ?? 'N/A') . "\n";
                echo "Date: " . ($seance['date_seance'] ?? 'N/A') . "\n";
                echo "Visio enabled: " . (($seance['visio_enabled'] ?? false) ? 'OUI' : 'NON') . "\n";
                echo "Visio status: " . ($seance['visio_status'] ?? 'N/A') . "\n";
                echo str_repeat('-', 50) . "\n";
            }

            echo "\n✅ ENDPOINT VALIDE: $endpoint\n";
            echo "Premier ID trouvé: {$seances[0]['id']}\n\n";
            break;
        } else {
            echo "❌ Aucune séance trouvée\n\n";
        }
    } else {
        echo "❌ Erreur: $httpCode\n";
        echo "$response\n\n";
    }
}
