<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Http;

$coordinateur = User::where('role', 'coordinateur')->first();

echo "==============================================\n";
echo "TEST FINAL - RECHERCHE DES ENSEIGNANTS\n";
echo "==============================================\n\n";

// Test avec un enseignant connu
echo "TEST: Recherche de 'prof.bede.test'\n";
echo "----------------------------------------------\n\n";

$endpoints = [
    '/enseignants',
    '/utilisateurs',
    '/users?role=enseignant',
    '/enseignants?search=prof.bede',
    '/matieres',
    '/emploi-temps',
];

foreach ($endpoints as $endpoint) {
    echo "Endpoint: {$endpoint}\n";

    $url = 'https://presentation.klassci.com/api/lms' . $endpoint;
    $response = Http::withoutVerifying()->withHeaders([
        'Authorization' => 'Bearer ' . $coordinateur->klassci_token,
        'Accept' => 'application/json',
    ])->get($url);

    echo "Status: " . $response->status() . "\n";

    if ($response->successful()) {
        $data = $response->json();

        // Chercher dans toute la réponse
        $json = json_encode($data);

        if (stripos($json, 'prof.bede') !== false || stripos($json, 'bede') !== false) {
            echo "✅ TROUVÉ! L'enseignant 'bede' est dans cette réponse!\n";
            echo "Extrait:\n";

            // Afficher la partie pertinente
            $lines = explode("\n", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            foreach ($lines as $line) {
                if (stripos($line, 'bede') !== false || stripos($line, 'prof') !== false) {
                    echo $line . "\n";
                }
            }
        } else {
            echo "❌ Pas trouvé dans cette réponse\n";
        }
    } else {
        echo "❌ Erreur\n";
    }

    echo "\n";
}

echo "\n==============================================\n";
echo "CONCLUSION\n";
echo "==============================================\n";
echo "Si 'prof.bede.test' n'apparaît nulle part,\n";
echo "cela signifie que KLASSCI ne l'expose pas au coordinateur.\n";
echo "\nSOLUTION: Créer manuellement la liste des enseignants\n";
echo "depuis la base de données locale (table users).\n";
