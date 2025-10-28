<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "====================================\n";
echo "TEST COMPLET SYSTÈME VISIOCONFÉRENCE\n";
echo "====================================\n\n";

$user = App\Models\User::where('role', 'enseignant')->first();
if (!$user) {
    echo "❌ Aucun utilisateur enseignant trouvé\n";
    exit(1);
}

echo "👤 Enseignant: {$user->name} (ID: {$user->klassci_id})\n\n";

$controller = app(App\Http\Controllers\API\LMSDataController::class);

// Helper pour créer une requête
function makeRequest($user) {
    $request = new \Illuminate\Http\Request();
    $request->setUserResolver(function() use ($user) {
        return $user;
    });
    return $request;
}

// Helper pour afficher résultat
function displayResult($testName, $response) {
    $data = json_decode($response->getContent(), true);
    $status = $response->getStatusCode();

    if ($status == 200 && $data['success']) {
        echo "✅ $testName: SUCCESS\n";
        return $data;
    } else {
        echo "❌ $testName: FAILED (Status: $status)\n";
        if (isset($data['message'])) {
            echo "   Message: {$data['message']}\n";
        }
        if (isset($data['error'])) {
            echo "   Error: " . substr($data['error'], 0, 100) . "...\n";
        }
        return null;
    }
}

echo "--- TEST 1: myTeachingSeances ---\n";
$response = $controller->myTeachingSeances(makeRequest($user));
$result = displayResult("myTeachingSeances", $response);
if ($result) {
    echo "   Séances trouvées: " . count($result['data']) . "\n";
    if (!empty($result['data'])) {
        $seanceId = $result['data'][0]['id'];
        echo "   Test avec séance ID: $seanceId\n";
    }
}
echo "\n";

echo "--- TEST 2: seanceDetails ---\n";
$seanceId = 19; // ID de test
$response = $controller->seanceDetails($seanceId, makeRequest($user));
$result = displayResult("seanceDetails($seanceId)", $response);
if ($result) {
    $seance = $result['data']['seance'];
    echo "   Matière: " . ($seance['matiere']['nom'] ?? 'N/A') . "\n";
    echo "   Enseignant: " . ($seance['enseignant']['nom'] ?? 'N/A') . "\n";
    echo "   Date: " . ($seance['programmation']['date'] ?? 'N/A') . "\n";
    echo "   Horaire: " . substr($seance['programmation']['heure_debut'] ?? '', 11, 5) .
         " - " . substr($seance['programmation']['heure_fin'] ?? '', 11, 5) . "\n";
    echo "   Durée: " . ($seance['duree_minutes'] ?? 'N/A') . " min\n";
    echo "   Visio enabled: " . ($seance['visio_enabled'] ? 'OUI' : 'NON') . "\n";
    echo "   Visio status: " . ($seance['visio_status'] ?? 'N/A') . "\n";
}
echo "\n";

echo "--- TEST 3: matiereDetails ---\n";
$matiereId = 1; // Marketing digital
$response = $controller->matiereDetails($matiereId, makeRequest($user));
$result = displayResult("matiereDetails($matiereId)", $response);
if ($result) {
    $data = $result['data'];
    echo "   Matière: " . ($data['matiere']['nom'] ?? 'N/A') . "\n";
    echo "   Séances: " . count($data['seances_programmees'] ?? []) . "\n";
    if (!empty($data['seances_programmees'])) {
        $seance = $data['seances_programmees'][0];
        echo "   Première séance:\n";
        echo "     - ID: {$seance['id']}\n";
        echo "     - Date: " . ($seance['programmation']['date'] ?? 'N/A') . "\n";
        echo "     - Enseignant: " . ($seance['enseignant']['nom'] ?? 'N/A') . "\n";
        echo "     - Visio enabled: " . ($seance['visio_enabled'] ? 'OUI' : 'NON') . "\n";
    }
}
echo "\n";

echo "--- TEST 4: seanceParticipants ---\n";
$response = $controller->seanceParticipants($seanceId, makeRequest($user));
$result = displayResult("seanceParticipants($seanceId)", $response);
if ($result) {
    echo "   Teacher: " . ($result['data']['teacher']['nom'] ?? 'N/A') . "\n";
    echo "   Students: " . count($result['data']['students'] ?? []) . "\n";
    echo "   Total: " . ($result['data']['total_participants'] ?? 0) . "\n";
}
echo "\n";

echo "--- TEST 5: activateVisio ---\n";
// D'abord vérifier l'état actuel
$visio = App\Models\Seance::where('klassci_seance_id', $seanceId)->first();
if ($visio && $visio->visio_enabled) {
    echo "   ⚠️  Visio déjà activée (Status: {$visio->visio_status})\n";
} else {
    $response = $controller->activateVisio($seanceId, makeRequest($user));
    $result = displayResult("activateVisio($seanceId)", $response);
    if ($result) {
        echo "   Status: " . ($result['data']['visio_status'] ?? 'N/A') . "\n";
        echo "   Room ID: " . ($result['data']['visio_room_id'] ?? 'N/A') . "\n";
    }
}
echo "\n";

echo "--- TEST 6: startVisio ---\n";
$visio = App\Models\Seance::where('klassci_seance_id', $seanceId)->first();
if ($visio && $visio->visio_status === 'active') {
    echo "   ⚠️  Visio déjà active\n";
    echo "   Started at: {$visio->visio_started_at}\n";
} else {
    $response = $controller->startVisio($seanceId, makeRequest($user));
    $result = displayResult("startVisio($seanceId)", $response);
    if ($result) {
        echo "   Status: " . ($result['data']['visio_status'] ?? 'N/A') . "\n";
        echo "   Room ID: " . ($result['data']['visio_room_id'] ?? 'N/A') . "\n";
        echo "   Started at: " . ($result['data']['visio_started_at'] ?? 'N/A') . "\n";
    }
}
echo "\n";

echo "====================================\n";
echo "RÉSUMÉ FINAL\n";
echo "====================================\n";

$visio = App\Models\Seance::where('klassci_seance_id', $seanceId)->first();
if ($visio) {
    echo "Séance $seanceId dans la BDD:\n";
    echo "  - Visio enabled: " . ($visio->visio_enabled ? 'OUI' : 'NON') . "\n";
    echo "  - Status: " . ($visio->visio_status ?? 'NULL') . "\n";
    echo "  - Room ID: " . ($visio->visio_room_id ?? 'NULL') . "\n";
    echo "  - Matière: " . ($visio->matiere_nom ?? 'NULL') . "\n";
    echo "  - Enseignant: " . ($visio->enseignant_nom ?? 'NULL') . "\n";
} else {
    echo "❌ Aucune entrée visio trouvée pour séance $seanceId\n";
}

echo "\n✅ Tests terminés\n";
