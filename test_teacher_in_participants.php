<?php

/**
 * TEST: Vérifier si l'API retourne le nom de l'enseignant
 */

$baseUrl = 'http://localhost:8000/api';
$token = '13|b9DLXrfhWnHdqYx3da3GbjRKoYvzH89Vs5xt8Jrdfc0ecc2a';
$seanceId = 49; // Séance de test

echo "═══════════════════════════════════════════════════════════════════\n";
echo "TEST: Vérifier nom enseignant dans getVisioParticipants\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

echo "Séance ID: $seanceId\n\n";

// Appeler l'API
$ch = curl_init("$baseUrl/lms/seances/$seanceId/participants");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Accept: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP: $httpCode\n\n";

if ($httpCode === 200) {
    $data = json_decode($response, true);

    if ($data['success']) {
        echo "✅ API appelée avec succès\n\n";

        // Vérifier si teacher existe
        if (isset($data['data']['teacher'])) {
            echo "✅ Clé 'teacher' présente dans la réponse\n";
            echo "Contenu:\n";
            print_r($data['data']['teacher']);
            echo "\n";

            $teacher = $data['data']['teacher'];
            if (!empty($teacher['nom'])) {
                echo "✅ Nom enseignant: " . ($teacher['prenom'] ?? '') . " " . $teacher['nom'] . "\n";
            } else {
                echo "❌ Nom enseignant vide ou null\n";
            }
        } else {
            echo "❌ Clé 'teacher' ABSENTE de la réponse\n";
        }

        echo "\n";

        // Vérifier seance info
        if (isset($data['data']['seance'])) {
            echo "✅ Clé 'seance' présente\n";
            print_r($data['data']['seance']);
        } else {
            echo "❌ Clé 'seance' ABSENTE\n";
        }

        echo "\n";

        // Afficher structure complète
        echo "═══════════════════════════════════════════════════════════════════\n";
        echo "STRUCTURE COMPLÈTE DE LA RÉPONSE:\n";
        echo "═══════════════════════════════════════════════════════════════════\n";
        echo "Clés dans data:\n";
        foreach ($data['data'] as $key => $value) {
            if (is_array($value)) {
                echo "  - $key: [" . (isset($value[0]) ? 'array[' . count($value) . ']' : 'object') . "]\n";
            } else {
                echo "  - $key: $value\n";
            }
        }

    } else {
        echo "❌ API retourne success=false\n";
        echo "Message: " . ($data['message'] ?? 'N/A') . "\n";
    }
} else {
    echo "❌ Erreur HTTP $httpCode\n";
    echo "Réponse: $response\n";
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "FIN DU TEST\n";
echo "═══════════════════════════════════════════════════════════════════\n";
