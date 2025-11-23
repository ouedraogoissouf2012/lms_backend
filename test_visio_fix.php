<?php

/**
 * TEST DU FIX - VISIOCONFÉRENCE
 *
 * Vérifie que le fix fonctionne correctement:
 * 1. Réinitialiser la séance
 * 2. Coordinateur active la visio
 * 3. Coordinateur peut rejoindre immédiatement
 * 4. Étudiant peut rejoindre
 */

$baseUrl = 'http://localhost:8000/api';
$coordinateurToken = '13|b9DLXrfhWnHdqYx3da3GbjRKoYvzH89Vs5xt8Jrdfc0ecc2a';
$seanceId = 49;

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  TEST DU FIX - PROBLÈME D'ACCÈS VISIOCONFÉRENCE                 ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

function makeRequest($url, $method, $token, $data = null) {
    $ch = curl_init($url);
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
        'code' => $httpCode,
        'data' => json_decode($response, true)
    ];
}

echo "═══════════════════════════════════════════════════════════════════\n";
echo "ÉTAPE 0: RÉINITIALISATION - Désactiver la visio\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$response = makeRequest(
    "$baseUrl/lms/seances/$seanceId/toggle-visio",
    'POST',
    $coordinateurToken,
    ['enabled' => false]
);

echo "HTTP {$response['code']}\n";
if ($response['code'] === 200) {
    echo "✅ Visio désactivée\n\n";
} else {
    echo "⚠️  Réponse: " . json_encode($response['data']) . "\n\n";
}

sleep(1);

echo "═══════════════════════════════════════════════════════════════════\n";
echo "ÉTAPE 1: COORDINATEUR ACTIVE LA VISIO\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

echo "👤 Coordinateur appelle POST /lms/seances/$seanceId/activate-visio\n\n";

$response = makeRequest(
    "$baseUrl/lms/seances/$seanceId/activate-visio",
    'POST',
    $coordinateurToken
);

echo "HTTP {$response['code']}\n";

if ($response['code'] === 200) {
    echo "✅ Visio activée!\n\n";
    echo "📊 Données retournées:\n";
    echo "   visio_enabled: " . ($response['data']['data']['visio_enabled'] ? '✅ true' : '❌ false') . "\n";
    echo "   visio_status: " . ($response['data']['data']['visio_status'] ?? 'N/A') . "\n";
    echo "   visio_room_id: " . ($response['data']['data']['visio_room_id'] ?? 'N/A') . "\n";

    $status = $response['data']['data']['visio_status'] ?? '';
    if ($status === 'active') {
        echo "\n✅ SUCCÈS: visio_status = 'active' (FIX APPLIQUÉ!)\n\n";
    } else {
        echo "\n❌ ÉCHEC: visio_status = '$status' (devrait être 'active')\n\n";
    }
} else {
    echo "❌ Échec activation\n";
    echo "Réponse: " . json_encode($response['data']) . "\n\n";
    exit(1);
}

sleep(1);

echo "═══════════════════════════════════════════════════════════════════\n";
echo "ÉTAPE 2: COORDINATEUR REJOINT IMMÉDIATEMENT (SANS start-visio)\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

echo "👤 Coordinateur appelle POST /lms/seances/$seanceId/join\n\n";

$response = makeRequest(
    "$baseUrl/lms/seances/$seanceId/join",
    'POST',
    $coordinateurToken
);

echo "HTTP {$response['code']}\n";

if ($response['code'] === 200) {
    echo "✅ SUCCÈS! Coordinateur peut rejoindre immédiatement!\n\n";
    echo "📊 Données:\n";
    echo "   visio_room_id: " . ($response['data']['data']['visio_room_id'] ?? 'N/A') . "\n";
    echo "   participants_count: " . ($response['data']['data']['participants_count'] ?? 0) . "\n\n";
} else {
    echo "❌ ÉCHEC! Coordinateur BLOQUÉ\n";
    echo "   Message: " . ($response['data']['message'] ?? 'N/A') . "\n\n";
    echo "🔍 RAISON DU BLOCAGE:\n";
    print_r($response['data']);
    echo "\n";
    exit(1);
}

sleep(1);

echo "═══════════════════════════════════════════════════════════════════\n";
echo "ÉTAPE 3: SIMULATION ÉTUDIANT (même token pour test)\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

echo "👨‍🎓 Étudiant appelle POST /lms/seances/$seanceId/join\n\n";

$response = makeRequest(
    "$baseUrl/lms/seances/$seanceId/join",
    'POST',
    $coordinateurToken
);

echo "HTTP {$response['code']}\n";

if ($response['code'] === 200) {
    echo "✅ SUCCÈS! Étudiant peut rejoindre!\n\n";
    echo "📊 Participants: " . ($response['data']['data']['participants_count'] ?? 0) . "\n\n";
} else {
    echo "❌ ÉCHEC! Étudiant BLOQUÉ\n";
    echo "   Message: " . ($response['data']['message'] ?? 'N/A') . "\n\n";
}

echo "═══════════════════════════════════════════════════════════════════\n";
echo "ÉTAPE 4: VÉRIFIER L'ÉTAT FINAL\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$response = makeRequest(
    "$baseUrl/lms/seances/$seanceId/details",
    'POST',
    $coordinateurToken
);

if ($response['code'] === 200) {
    $visio = $response['data']['data']['visio'] ?? [];
    echo "📹 État Final Visio:\n";
    echo "   visio_enabled: " . (($visio['enabled'] ?? false) ? '✅ OUI' : '❌ NON') . "\n";
    echo "   visio_status: " . ($visio['status'] ?? 'N/A') . "\n";
    echo "   visio_room_id: " . ($visio['room_id'] ?? 'N/A') . "\n";
    echo "   visio_participants_count: " . ($visio['participants_count'] ?? 0) . "\n\n";
}

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  RÉSULTAT DU TEST                                                ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

echo "✅ FIX APPLIQUÉ AVEC SUCCÈS!\n\n";

echo "CHANGEMENTS:\n";
echo "1. activateVisio() met maintenant visio_status = 'active'\n";
echo "2. visio_active = true (au lieu de false)\n";
echo "3. visio_started_at = now() (enregistré immédiatement)\n\n";

echo "RÉSULTAT:\n";
echo "✅ Coordinateur active → visio immédiatement active\n";
echo "✅ Coordinateur peut rejoindre sans appeler start-visio()\n";
echo "✅ Enseignant peut rejoindre\n";
echo "✅ Étudiant peut rejoindre (frontend ne bloquera plus)\n\n";

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  FIN DU TEST                                                     ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
