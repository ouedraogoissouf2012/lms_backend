<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Récupérer un enseignant
$user = App\Models\User::where('role', 'enseignant')->first();

if (!$user || !$user->klassci_token) {
    echo "⚠ Pas d'enseignant avec token KLASSCI\n";
    exit(1);
}

$service = app(App\Services\KlassciProxyService::class);

echo "════════════════════════════════════════════\n";
echo "TEST ENDPOINT /matieres/{id} DÉTAILS\n";
echo "════════════════════════════════════════════\n\n";

try {
    // 1. Récupérer la liste des matières depuis teacher-dashboard
    echo "📡 Récupération liste matières...\n";
    $dashboard = $service->requestWithUserToken($user->klassci_token, 'me/teacher-dashboard', 'GET');
    $matieres = $dashboard['data']['matieres'] ?? [];

    if (count($matieres) === 0) {
        echo "⚠ Aucune matière trouvée\n";
        exit(1);
    }

    $firstMatiere = $matieres[0];
    $matiereId = $firstMatiere['id'];
    $matiereNom = $firstMatiere['nom'] ?? 'N/A';

    echo "✓ " . count($matieres) . " matière(s) trouvée(s)\n";
    echo "→ Test avec matière ID: {$matiereId} ({$matiereNom})\n\n";

    // 2. Tester l'endpoint KLASSCI direct /matieres/{id}
    echo "════════════════════════════════════════════\n";
    echo "TEST 1: API KLASSCI /matieres/{id}\n";
    echo "════════════════════════════════════════════\n\n";

    try {
        $klassciData = $service->requestWithUserToken($user->klassci_token, "matieres/{$matiereId}", 'GET');

        echo "✓ Réponse reçue\n";
        echo "Structure: " . json_encode(array_keys($klassciData), JSON_PRETTY_PRINT) . "\n\n";

        if (isset($klassciData['data'])) {
            $data = $klassciData['data'];
            echo "Clés data: " . implode(', ', array_keys($data)) . "\n\n";

            // Vérifier les combinaisons
            if (isset($data['combinaisons'])) {
                $combinaisons = $data['combinaisons'];
                echo "📊 COMBINAISONS: " . count($combinaisons) . " trouvée(s)\n\n";

                if (count($combinaisons) > 0) {
                    $firstCombi = $combinaisons[0];
                    echo "Première combinaison:\n";
                    echo json_encode($firstCombi, JSON_PRETTY_PRINT) . "\n\n";
                }
            } else {
                echo "⚠ Pas de clé 'combinaisons' dans data\n\n";
            }
        }
    } catch (\Exception $e) {
        echo "❌ Erreur KLASSCI: " . $e->getMessage() . "\n\n";
    }

    // 3. Tester l'endpoint LMS local /api/lms/matieres/{id}
    echo "════════════════════════════════════════════\n";
    echo "TEST 2: LMS Backend /lms/matieres/{id}\n";
    echo "════════════════════════════════════════════\n\n";

    // Simuler une requête HTTP interne
    $request = Illuminate\Http\Request::create("/api/lms/matieres/{$matiereId}", 'GET');
    $request->setUserResolver(function () use ($user) {
        return $user;
    });

    $controller = new App\Http\Controllers\API\LMSDataController($service);
    $response = $controller->matiereDetails($matiereId, $request);
    $responseData = json_decode($response->getContent(), true);

    if ($responseData['success']) {
        echo "✓ Endpoint LMS fonctionne\n\n";

        $lmsData = $responseData['data'];
        echo "Clés retournées: " . implode(', ', array_keys($lmsData)) . "\n\n";

        // Vérifier les combinaisons
        if (isset($lmsData['combinaisons'])) {
            $combinaisons = $lmsData['combinaisons'];
            echo "📊 COMBINAISONS LMS: " . count($combinaisons) . " trouvée(s)\n\n";

            if (count($combinaisons) > 0) {
                $firstCombi = $combinaisons[0];
                echo "Première combinaison:\n";
                echo json_encode($firstCombi, JSON_PRETTY_PRINT) . "\n\n";

                // Analyse détaillée
                echo "ANALYSE:\n";
                echo "  - filiere.id: " . ($firstCombi['filiere']['id'] ?? 'NULL') . "\n";
                echo "  - filiere.code: " . ($firstCombi['filiere']['code'] ?? 'NULL') . "\n";
                echo "  - filiere.nom: " . ($firstCombi['filiere']['nom'] ?? 'NULL') . "\n";
                echo "  - niveau.id: " . ($firstCombi['niveau']['id'] ?? 'NULL') . "\n";
                echo "  - niveau.code: " . ($firstCombi['niveau']['code'] ?? 'NULL') . "\n";
                echo "  - niveau.nom: " . ($firstCombi['niveau']['nom'] ?? 'NULL') . "\n\n";

                if (isset($firstCombi['filiere']['id']) && isset($firstCombi['niveau']['id'])) {
                    echo "✅ Combinaisons VALIDES - Données complètes\n";
                } else {
                    echo "⚠ Combinaisons VIDES - Objets sans données\n";
                }
            }
        } else {
            echo "⚠ Pas de combinaisons dans la réponse LMS\n";
        }

        // Vérifier les statistiques
        if (isset($lmsData['statistiques'])) {
            echo "\n📈 STATISTIQUES:\n";
            $stats = $lmsData['statistiques'];
            echo "  - Nombre combinaisons: " . ($stats['nombre_combinaisons'] ?? 0) . "\n";
            echo "  - Nombre séances: " . ($stats['nombre_seances'] ?? 0) . "\n";
            echo "  - Nombre évaluations: " . ($stats['nombre_evaluations'] ?? 0) . "\n";
        }

    } else {
        echo "❌ Erreur LMS: " . $responseData['message'] . "\n";
    }

    echo "\n════════════════════════════════════════════\n";
    echo "✅ Test terminé\n";
    echo "════════════════════════════════════════════\n";

} catch (\Exception $e) {
    echo "❌ ERREUR GÉNÉRALE: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
