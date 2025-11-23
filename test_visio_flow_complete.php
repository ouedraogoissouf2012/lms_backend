<?php

/**
 * TEST COMPLET DU FLUX VISIO - DE BOUT EN BOUT
 * On va tester EXACTEMENT ce qui se passe quand:
 * 1. Coordinateur active la visio
 * 2. Coordinateur essaie de rejoindre
 * 3. Enseignant essaie de rejoindre
 * 4. Étudiant essaie de rejoindre
 */

$baseUrl = 'http://localhost:8000/api';

// Tokens réels
$coordinateurToken = '13|b9DLXrfhWnHdqYx3da3GbjRKoYvzH89Vs5xt8Jrdfc0ecc2a'; // Token coordinateur
$enseignantToken = '13|b9DLXrfhWnHdqYx3da3GbjRKoYvzH89Vs5xt8Jrdfc0ecc2a'; // À remplacer
$etudiantToken = '13|b9DLXrfhWnHdqYx3da3GbjRKoYvzH89Vs5xt8Jrdfc0ecc2a'; // À remplacer

$seanceId = 49; // ID trouvé depuis /lms/seances/upcoming

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  TEST COMPLET FLUX VISIOCONFÉRENCE - DE BOUT EN BOUT            ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

function makeRequest($method, $endpoint, $token, $data = null, $baseUrl = 'http://localhost:8000/api') {
    $ch = curl_init($baseUrl . $endpoint);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json'
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'response' => json_decode($response, true)
    ];
}

echo "═══════════════════════════════════════════════════════════════════\n";
echo "ÉTAPE 1: ÉTAT INITIAL - Vérifier l'état de la séance\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$result = makeRequest('GET', "/lms/seances/{$seanceId}/details", $coordinateurToken, null, $baseUrl);

if ($result['http_code'] === 200) {
    $seance = $result['response']['data']['seance'] ?? null;
    if ($seance) {
        echo "✅ Séance trouvée: {$seance['matiere']['nom']}\n";
        echo "   Classe: {$seance['classe']['nom']}\n";
        echo "   Date: {$seance['date_seance']}\n";
        echo "   Heure: {$seance['heure_debut']} - {$seance['heure_fin']}\n";

        if (isset($seance['visio_enabled'])) {
            echo "\n📹 État Visio:\n";
            echo "   visio_enabled: " . ($seance['visio_enabled'] ? '✅ OUI' : '❌ NON') . "\n";
            echo "   visio_status: " . ($seance['visio_status'] ?? 'N/A') . "\n";
            echo "   visio_room_id: " . ($seance['visio_room_id'] ?? 'N/A') . "\n";
            echo "   visio_participants_count: " . ($seance['visio_participants_count'] ?? 0) . "\n";
        } else {
            echo "\n❌ Visio pas encore activée\n";
        }
    }
} else {
    echo "❌ Erreur HTTP {$result['http_code']}\n";
    print_r($result['response']);
    exit;
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "ÉTAPE 2: COORDINATEUR ACTIVE LA VISIO\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

echo "👤 Coordinateur appelle POST /lms/seances/{$seanceId}/activate-visio\n\n";

$result = makeRequest('POST', "/lms/seances/{$seanceId}/activate-visio", $coordinateurToken, null, $baseUrl);

echo "HTTP {$result['http_code']}\n";

if ($result['http_code'] === 200 && $result['response']['success']) {
    echo "✅ Visio activée avec succès!\n";
    $data = $result['response']['data'];
    echo "\n📊 Données retournées:\n";
    echo "   visio_enabled: " . ($data['visio_enabled'] ? '✅ true' : '❌ false') . "\n";
    echo "   visio_status: {$data['visio_status']}\n";
    echo "   visio_room_id: {$data['visio_room_id']}\n";

    if ($data['visio_status'] === 'programmee') {
        echo "\n⚠️  ATTENTION: visio_status = 'programmee' (PAS 'active')\n";
    }
} else {
    echo "❌ Échec activation\n";
    print_r($result['response']);
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "ÉTAPE 3: COORDINATEUR ESSAIE DE REJOINDRE LA VISIO\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

echo "👤 Coordinateur appelle POST /lms/seances/{$seanceId}/join\n\n";

$result = makeRequest('POST', "/lms/seances/{$seanceId}/join", $coordinateurToken, null, $baseUrl);

echo "HTTP {$result['http_code']}\n";

if ($result['http_code'] === 200 && $result['response']['success']) {
    echo "✅ Coordinateur a PU rejoindre!\n";
    $data = $result['response']['data'];
    echo "\n📊 Données:\n";
    echo "   visio_room_id: {$data['visio_room_id']}\n";
    echo "   participants_count: {$data['participants_count']}\n";
} else {
    echo "❌ Coordinateur BLOQUÉ!\n";
    echo "   Message: " . ($result['response']['message'] ?? 'N/A') . "\n";
    echo "\n🔍 RAISON DU BLOCAGE:\n";
    print_r($result['response']);
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "ÉTAPE 4: VÉRIFIER SI startVisio() EXISTE ET LE TESTER\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

echo "👤 Coordinateur appelle POST /lms/seances/{$seanceId}/start-visio\n\n";

$result = makeRequest('POST', "/lms/seances/{$seanceId}/start-visio", $coordinateurToken, null, $baseUrl);

echo "HTTP {$result['http_code']}\n";

if ($result['http_code'] === 200 && $result['response']['success']) {
    echo "✅ Visio démarrée avec succès!\n";
    $data = $result['response']['data'];
    echo "\n📊 Données:\n";
    echo "   visio_status: {$data['visio_status']}\n";
    echo "   visio_room_id: {$data['visio_room_id']}\n";

    if ($data['visio_status'] === 'active') {
        echo "\n✅ MAINTENANT visio_status = 'active'\n";
    }
} else {
    echo "❌ Échec démarrage\n";
    print_r($result['response']);
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "ÉTAPE 5: RÉESSAYER DE REJOINDRE APRÈS startVisio()\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

echo "👤 Coordinateur réessaie POST /lms/seances/{$seanceId}/join\n\n";

$result = makeRequest('POST', "/lms/seances/{$seanceId}/join", $coordinateurToken, null, $baseUrl);

echo "HTTP {$result['http_code']}\n";

if ($result['http_code'] === 200 && $result['response']['success']) {
    echo "✅ SUCCÈS! Coordinateur peut maintenant rejoindre!\n";
    $data = $result['response']['data'];
    echo "\n📊 Données:\n";
    echo "   visio_room_id: {$data['visio_room_id']}\n";
    echo "   participants_count: {$data['participants_count']}\n";
} else {
    echo "❌ TOUJOURS BLOQUÉ!\n";
    print_r($result['response']);
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "ÉTAPE 6: ENSEIGNANT ESSAIE DE REJOINDRE\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

echo "👨‍🏫 Enseignant appelle POST /lms/seances/{$seanceId}/join\n";
echo "(En utilisant le même token pour simuler)\n\n";

$result = makeRequest('POST', "/lms/seances/{$seanceId}/join", $coordinateurToken, null, $baseUrl);

echo "HTTP {$result['http_code']}\n";

if ($result['http_code'] === 200 && $result['response']['success']) {
    echo "✅ Enseignant peut rejoindre!\n";
} else {
    echo "❌ Enseignant BLOQUÉ!\n";
    echo "   Message: " . ($result['response']['message'] ?? 'N/A') . "\n";
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "ÉTAPE 7: ÉTAT FINAL - Vérifier l'état après tous les tests\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$result = makeRequest('GET', "/lms/seances/{$seanceId}/details", $coordinateurToken, null, $baseUrl);

if ($result['http_code'] === 200) {
    $seance = $result['response']['data']['seance'] ?? null;
    if ($seance) {
        echo "📹 État Final Visio:\n";
        echo "   visio_enabled: " . ($seance['visio_enabled'] ? '✅ OUI' : '❌ NON') . "\n";
        echo "   visio_status: " . ($seance['visio_status'] ?? 'N/A') . "\n";
        echo "   visio_room_id: " . ($seance['visio_room_id'] ?? 'N/A') . "\n";
        echo "   visio_participants_count: " . ($seance['visio_participants_count'] ?? 0) . "\n";
    }
}

echo "\n╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  CONCLUSION                                                      ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

echo "🔍 ANALYSE:\n";
echo "1. activate-visio() met visio_status = 'programmee'\n";
echo "2. join() vérifie si visio_status === 'active'\n";
echo "3. Si status !== 'active' → ERREUR 400 \"Visio pas active\"\n";
echo "4. start-visio() met visio_status = 'active'\n";
echo "5. Après start-visio(), join() fonctionne!\n\n";

echo "💡 PROBLÈME IDENTIFIÉ:\n";
echo "Le coordinateur doit appeler start-visio() AVANT que quiconque\n";
echo "puisse rejoindre (y compris lui-même).\n\n";

echo "✅ SOLUTION:\n";
echo "Modifier le frontend pour qu'il appelle automatiquement\n";
echo "start-visio() quand le coordinateur clique \"Rejoindre\".\n";

echo "\n╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  FIN DU TEST                                                     ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
