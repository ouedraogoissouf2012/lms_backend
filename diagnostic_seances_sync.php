<?php

/**
 * Script de diagnostic pour la synchronisation des séances KLASSCI
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "\n========================================\n";
echo "DIAGNOSTIC SYNCHRONISATION SÉANCES\n";
echo "========================================\n\n";

// 1. Trouver un enseignant
$enseignant = App\Models\User::where('role', 'enseignant')
    ->whereNotNull('klassci_id')
    ->first();

if (!$enseignant) {
    echo "❌ Aucun enseignant trouvé\n";
    exit(1);
}

echo "✅ Enseignant: {$enseignant->name}\n";
echo "   - KLASSCI ID: {$enseignant->klassci_id}\n";
echo "   - Token: " . ($enseignant->klassci_token ? 'Présent' : '❌ MANQUANT') . "\n\n";

// 2. Vérifier les séances en base locale
echo "[1] Séances dans la base locale:\n";
$localSeances = App\Models\Seance::where('klassci_enseignant_id', $enseignant->klassci_id)
    ->orderBy('created_at', 'desc')
    ->take(5)
    ->get();

echo "   Total: " . $localSeances->count() . " séances\n";
foreach ($localSeances as $seance) {
    echo "   - #{$seance->id}: {$seance->titre}\n";
    echo "     KLASSCI ID: {$seance->klassci_seance_id}\n";
    echo "     Date: {$seance->date_heure}\n";
    echo "     Créée le: {$seance->created_at}\n\n";
}

// 3. Appeler l'API KLASSCI pour récupérer les séances
echo "[2] Appel API KLASSCI pour récupérer les séances...\n";

if (!$enseignant->klassci_token) {
    echo "❌ Token KLASSCI manquant, impossible de synchroniser\n";
    exit(1);
}

try {
    $proxyService = app(App\Services\KlassciProxyService::class);

    // Utiliser la méthode get du service
    $response = $proxyService->get('seances/enseignant/' . $enseignant->klassci_id);

    echo "✅ Réponse API reçue\n";

    if (isset($response['data'])) {
        $seancesKlassci = $response['data'];
        echo "   Total séances KLASSCI: " . count($seancesKlassci) . "\n\n";

        if (count($seancesKlassci) > 0) {
            echo "   Dernières séances KLASSCI:\n";
            foreach (array_slice($seancesKlassci, 0, 5) as $seance) {
                echo "   - ID: {$seance['id']}\n";
                echo "     Titre: " . ($seance['titre'] ?? 'Sans titre') . "\n";
                echo "     Date: " . ($seance['date_heure'] ?? 'N/A') . "\n";
                echo "     Matière: " . ($seance['matiere']['name'] ?? 'N/A') . "\n";
                echo "     Classe: " . ($seance['classe']['name'] ?? 'N/A') . "\n\n";
            }
        }
    } else {
        echo "⚠️  Pas de données dans la réponse\n";
        echo "Réponse complète: " . json_encode($response, JSON_PRETTY_PRINT) . "\n";
    }

} catch (Exception $e) {
    echo "❌ Erreur API: " . $e->getMessage() . "\n";
    if (method_exists($e, 'getResponse')) {
        $errorResponse = $e->getResponse();
        if ($errorResponse) {
            echo "   Réponse HTTP: " . $errorResponse->getBody() . "\n";
        }
    }
}

echo "\n";

// 4. Tester l'endpoint upcomingSeances
echo "[3] Test endpoint /lms/seances/upcoming...\n";
try {
    $controller = app(App\Http\Controllers\API\LMSDataController::class);

    $request = new Illuminate\Http\Request();
    $request->setUserResolver(function () use ($enseignant) {
        return $enseignant;
    });

    $response = $controller->upcomingSeances($request);
    $data = $response->getData(true);

    if ($data['success']) {
        echo "✅ Succès!\n";
        $seances = $data['data'] ?? [];
        echo "   Total séances à venir: " . count($seances) . "\n";

        if (count($seances) > 0) {
            $first = $seances[0];
            echo "   Première séance:\n";
            echo "     - ID: " . ($first['id'] ?? 'N/A') . "\n";
            echo "     - Titre: " . ($first['titre'] ?? 'N/A') . "\n";
            echo "     - Date: " . ($first['date_heure'] ?? 'N/A') . "\n";
        }
    } else {
        echo "❌ Erreur: " . ($data['message'] ?? 'Erreur inconnue') . "\n";
    }
} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
}

echo "\n========================================\n";
echo "FIN DU DIAGNOSTIC\n";
echo "========================================\n\n";

echo "RECOMMANDATIONS:\n";
echo "1. Vérifiez que le token KLASSCI de l'enseignant est valide\n";
echo "2. Vérifiez que l'endpoint KLASSCI /seances/enseignant/{id} fonctionne\n";
echo "3. Vérifiez que la séance a bien été créée sur KLASSCI avec le bon enseignant_id\n";
echo "4. Actualisez la page du LMS (F5) pour forcer une nouvelle synchronisation\n\n";
