<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "TEST DIRECT DES DATES KLASSCI\n";
echo "===============================\n\n";

// Trouver Marcel
$marcel = App\Models\User::where('name', 'LIKE', '%MARCEL%')->first();

if (!$marcel) {
    die("Marcel non trouvé\n");
}

echo "Utilisateur: {$marcel->name}\n";
echo "Token: " . substr($marcel->klassci_token, 0, 20) . "...\n\n";

// Appeler l'API matieres/1 directement
$klassciService = app(\App\Services\KlassciProxyService::class);

try {
    // Essayer d'abord le dashboard étudiant
    echo "1. Appel API: me/dashboard\n";
    echo "==========================\n";

    $dashboard = $klassciService->requestWithUserToken(
        $marcel->klassci_token,
        'me/dashboard',
        'GET'
    );

    $matieres = $dashboard['data']['matieres'] ?? [];
    echo "Matières dans le dashboard: " . count($matieres) . "\n\n";

    // Trouver Marketing digital
    $marketing = null;
    foreach ($matieres as $mat) {
        if ($mat['id'] == 1 || stripos($mat['nom'] ?? '', 'Marketing') !== false) {
            $marketing = $mat;
            break;
        }
    }

    if (!$marketing) {
        echo "Marketing digital non trouvé dans le dashboard\n";
        exit(1);
    }

    echo "Matière trouvée: {$marketing['nom']}\n";
    echo "Séances dans le dashboard: " . count($marketing['seances_programmees'] ?? []) . "\n\n";

    // Si des séances dans le dashboard, les afficher
    if (!empty($marketing['seances_programmees'])) {
        echo "SÉANCES DU DASHBOARD:\n";
        echo "=====================\n\n";

        foreach (array_slice($marketing['seances_programmees'], 0, 3) as $index => $seance) {
            $num = $index + 1;
            echo "SÉANCE #{$num} (du dashboard)\n";
            echo "============\n";
            echo json_encode($seance, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
        }
    }

    // Maintenant essayer matieres/1
    echo "\n2. Appel API: matieres/1\n";
    echo "========================\n";

    $response = $klassciService->requestWithUserToken(
        $marcel->klassci_token,
        'matieres/1',
        'GET'
    );

    $seances = $response['data']['seances_programmees'] ?? [];
    echo "Séances de matieres/1: " . count($seances) . "\n\n";

    if (!empty($seances)) {
        echo "SÉANCES DE MATIERES/1:\n";
        echo "======================\n\n";

        foreach (array_slice($seances, 0, 3) as $index => $seance) {
            $num = $index + 1;
            echo "SÉANCE #{$num} (de matieres/1)\n";
            echo "============\n";
            echo json_encode($seance, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
        }
    }

} catch (\Exception $e) {
    echo "ERREUR: {$e->getMessage()}\n";
    exit(1);
}
