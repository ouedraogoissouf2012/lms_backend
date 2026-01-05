<?php
/**
 * Investigation Part 5 - Structure exacte de la reponse teacher-dashboard
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Support\Facades\Cache;

echo "============================================\n";
echo "    STRUCTURE REPONSE TEACHER-DASHBOARD\n";
echo "============================================\n\n";

Cache::flush();

$klassciService = app(KlassciProxyService::class);
$bede = User::find(2); // bede@gmail.com

echo "Utilisateur: {$bede->email}\n\n";

try {
    $response = $klassciService->requestWithUserToken(
        $bede->klassci_token,
        'me/teacher-dashboard',
        'GET',
        [],
        true
    );

    echo "=== STRUCTURE COMPLETE DE LA REPONSE ===\n\n";

    // Afficher les cles de premier niveau
    echo "CLES DE PREMIER NIVEAU (response):\n";
    foreach (array_keys($response) as $key) {
        $value = $response[$key];
        $type = gettype($value);
        if (is_array($value)) {
            echo "  - $key: array(" . count($value) . " elements)\n";
        } else {
            echo "  - $key: $type\n";
        }
    }

    // Afficher les cles de data
    echo "\nCLES DANS 'data':\n";
    $data = $response['data'] ?? [];
    foreach (array_keys($data) as $key) {
        $value = $data[$key];
        $type = gettype($value);
        if (is_array($value)) {
            echo "  - $key: array(" . count($value) . " elements)\n";
        } else {
            echo "  - $key: $type = " . json_encode($value) . "\n";
        }
    }

    // Verifier si 'seances' existe
    echo "\n=== VERIFICATION SEANCES ===\n";
    if (isset($data['seances'])) {
        echo "seances EXISTE: " . count($data['seances']) . " elements\n";
        print_r($data['seances']);
    } else {
        echo "seances N'EXISTE PAS dans data!\n";
    }

    // Le frontend attend dashboardData.seances mais ca n'existe pas!
    echo "\n=== CE QUE LE DASHBOARD FRONTEND ATTEND ===\n";
    echo "dashboardData.matieres?.length: " . count($data['matieres'] ?? []) . "\n";
    echo "dashboardData.classes?.length: " . count($data['classes'] ?? []) . "\n";
    echo "dashboardData.evaluations?.length: " . count($data['evaluations'] ?? []) . "\n";
    echo "dashboardData.seances?.length: " . count($data['seances'] ?? []) . " <-- PROBLEME ICI!\n";

    // Afficher les seances de chaque matiere
    echo "\n=== SEANCES DANS CHAQUE MATIERE ===\n";
    $matieres = $data['matieres'] ?? [];
    foreach ($matieres as $m) {
        echo "\nMatiere: " . ($m['nom'] ?? 'N/A') . "\n";
        if (isset($m['seances_programmees'])) {
            echo "  seances_programmees: " . count($m['seances_programmees']) . "\n";
        } else {
            echo "  seances_programmees: NON PRESENT\n";
        }
    }

    // Verifier si les seances sont dans un autre champ
    echo "\n=== RECHERCHE DE SEANCES DANS TOUTE LA STRUCTURE ===\n";
    function searchForSeances($array, $path = '') {
        foreach ($array as $key => $value) {
            $currentPath = $path ? "$path.$key" : $key;
            if (stripos($key, 'seance') !== false) {
                echo "TROUVE: $currentPath\n";
                if (is_array($value)) {
                    echo "  -> " . count($value) . " elements\n";
                }
            }
            if (is_array($value) && !empty($value)) {
                searchForSeances($value, $currentPath);
            }
        }
    }
    searchForSeances($response);

} catch (Exception $e) {
    echo "ERREUR: " . $e->getMessage() . "\n";
}

echo "\n============================================\n";
echo "    FIN INVESTIGATION\n";
echo "============================================\n";
