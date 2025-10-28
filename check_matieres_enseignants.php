<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Http;

$coordinateur = User::where('role', 'coordinateur')->first();

if (!$coordinateur || empty($coordinateur->klassci_token)) {
    echo "❌ Coordinateur non trouvé ou sans token\n";
    exit(1);
}

echo "Vérification des enseignants via différents endpoints...\n\n";

// Test 1: /matieres pour voir si elles contiennent des infos enseignants
echo "========================================\n";
echo "TEST 1: /matieres\n";
echo "========================================\n";

$url = 'https://presentation.klassci.com/api/lms/matieres';
$response = Http::withoutVerifying()->withHeaders([
    'Authorization' => 'Bearer ' . $coordinateur->klassci_token,
    'Accept' => 'application/json',
])->get($url);

if ($response->successful()) {
    $data = $response->json();

    echo "Structure complète de la réponse:\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

    // Vérifier si c'est un tableau direct ou si les données sont dans une clé
    if (isset($data['data'])) {
        $matieres = $data['data'];
        echo "✅ Données dans data: " . count($matieres) . " matières\n\n";
    } else {
        $matieres = $data;
        echo "✅ Données directes: " . (is_array($matieres) ? count($matieres) : 'non-array') . "\n\n";
    }

    if (is_array($matieres) && count($matieres) > 0) {
        $premiere = reset($matieres); // Prendre le premier élément peu importe l'indexation
        echo "Première matière:\n";
        echo json_encode($premiere, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

        if (is_array($premiere)) {
            $keys = array_keys($premiere);
            echo "Clés disponibles: " . implode(', ', $keys) . "\n\n";

            if (isset($premiere['enseignant']) || isset($premiere['enseignants']) || isset($premiere['professeur']) || isset($premiere['teacher'])) {
                echo "✅ Les matières contiennent des informations sur les enseignants!\n";
            } else {
                echo "❌ Les matières ne semblent pas contenir d'infos directes sur les enseignants\n";
            }
        }
    }
} else {
    echo "❌ Erreur " . $response->status() . "\n";
}

// Test 2: /classes pour voir si elles contiennent des enseignants
echo "\n========================================\n";
echo "TEST 2: /classes\n";
echo "========================================\n";

$url = 'https://presentation.klassci.com/api/lms/classes';
$response = Http::withoutVerifying()->withHeaders([
    'Authorization' => 'Bearer ' . $coordinateur->klassci_token,
    'Accept' => 'application/json',
])->get($url);

if ($response->successful()) {
    $data = $response->json();

    echo "Structure complète de la réponse:\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
} else {
    echo "❌ Erreur " . $response->status() . "\n";
}

// Test 3: Essayer d'autres endpoints possibles
echo "\n========================================\n";
echo "TEST 3: Autres endpoints possibles\n";
echo "========================================\n";

$endpoints = [
    '/utilisateurs',
    '/users',
    '/enseignants',
    '/teachers',
    '/professeurs',
];

foreach ($endpoints as $endpoint) {
    $url = 'https://presentation.klassci.com/api/lms' . $endpoint;
    $response = Http::withoutVerifying()->withHeaders([
        'Authorization' => 'Bearer ' . $coordinateur->klassci_token,
        'Accept' => 'application/json',
    ])->get($url);

    $status = $response->status();
    echo "{$endpoint}: {$status}";

    if ($status === 200) {
        $data = $response->json();
        $count = is_array($data) ? count($data) : '?';
        echo " - {$count} éléments";
    }

    echo "\n";
}
