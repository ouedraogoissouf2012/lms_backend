<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Récupérer un coordinateur
$user = App\Models\User::where('role', 'coordinateur')->first();

if (!$user) {
    echo "⚠ Pas de coordinateur, utilisation d'un enseignant\n";
    $user = App\Models\User::where('role', 'enseignant')->first();
}

if (!$user || !$user->klassci_token) {
    echo "❌ Pas d'utilisateur avec token KLASSCI\n";
    exit(1);
}

echo "════════════════════════════════════════════\n";
echo "TEST ENDPOINT /api/admin/matieres\n";
echo "════════════════════════════════════════════\n\n";

echo "Utilisateur: " . $user->email . " (role: " . $user->role . ")\n";
echo "Token KLASSCI: " . (empty($user->klassci_token) ? 'Absent' : 'Présent') . "\n\n";

try {
    // Simuler la requête HTTP
    $request = Illuminate\Http\Request::create('/api/admin/matieres', 'GET');
    $request->setUserResolver(function () use ($user) {
        return $user;
    });

    $service = app(App\Services\KlassciProxyService::class);
    $controller = new App\Http\Controllers\API\LMSDataController($service);

    echo "📡 Appel de l'endpoint...\n\n";

    $response = $controller->adminMatieresList($request);
    $data = json_decode($response->getContent(), true);

    if (!$data['success']) {
        echo "❌ ÉCHEC: " . $data['message'] . "\n";
        exit(1);
    }

    echo "✅ SUCCÈS\n\n";

    $matieres = $data['data']['matieres'] ?? [];
    $stats = $data['data']['statistiques'] ?? [];

    echo "════════════════════════════════════════════\n";
    echo "STATISTIQUES\n";
    echo "════════════════════════════════════════════\n\n";

    echo "Total matières: " . $stats['total'] . "\n";
    echo "Total heures: " . $stats['total_heures'] . "h\n";
    echo "Total séances: " . $stats['total_seances'] . "\n\n";

    echo "════════════════════════════════════════════\n";
    echo "MATIÈRES ENRICHIES\n";
    echo "════════════════════════════════════════════\n\n";

    foreach ($matieres as $idx => $matiere) {
        echo ($idx + 1) . ". " . $matiere['nom'] . " (" . $matiere['code'] . ")\n";
        echo "   Coefficient: " . ($matiere['coefficient'] ?? 'N/A') . "\n";
        echo "   Heures: " . $matiere['heures_total'] . "h\n";
        echo "   Séances: " . $matiere['nb_seances_programmees'] . "\n";
        echo "   Couleur: " . $matiere['couleur'] . "\n";

        $combinaisons = $matiere['combinaisons'] ?? [];
        echo "   Combinaisons: " . count($combinaisons) . "\n";

        if (count($combinaisons) > 0) {
            echo "   ─────────────────────────────────────\n";

            // Grouper par niveau
            $niveauxMap = [];
            foreach ($combinaisons as $combi) {
                $niveauNom = $combi['niveau']['nom'] ?? $combi['niveau']['code'] ?? 'N/A';
                $filiereNom = $combi['filiere']['nom'] ?? $combi['filiere']['code'] ?? 'N/A';

                if (!isset($niveauxMap[$niveauNom])) {
                    $niveauxMap[$niveauNom] = [];
                }
                $niveauxMap[$niveauNom][] = $filiereNom;
            }

            foreach ($niveauxMap as $niveau => $filieres) {
                $filieresUniques = array_unique($filieres);
                echo "   → " . $niveau . ": " . implode(', ', $filieresUniques) . "\n";
            }
        } else {
            echo "   ⚠ Aucune combinaison\n";
        }

        echo "\n";
    }

    // Test détaillé sur la première matière
    if (count($matieres) > 0) {
        echo "════════════════════════════════════════════\n";
        echo "DÉTAILS PREMIÈRE MATIÈRE\n";
        echo "════════════════════════════════════════════\n\n";

        $first = $matieres[0];
        echo json_encode($first, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

        // Vérifier que les combinaisons sont valides
        if (isset($first['combinaisons']) && count($first['combinaisons']) > 0) {
            $firstCombi = $first['combinaisons'][0];

            echo "VALIDATION COMBINAISON:\n";
            echo "  - filiere.id: " . ($firstCombi['filiere']['id'] ?? 'NULL') . "\n";
            echo "  - filiere.nom: " . ($firstCombi['filiere']['nom'] ?? 'NULL') . "\n";
            echo "  - filiere.code: " . ($firstCombi['filiere']['code'] ?? 'NULL') . "\n";
            echo "  - niveau.id: " . ($firstCombi['niveau']['id'] ?? 'NULL') . "\n";
            echo "  - niveau.nom: " . ($firstCombi['niveau']['nom'] ?? 'NULL') . "\n";
            echo "  - niveau.code: " . ($firstCombi['niveau']['code'] ?? 'NULL') . "\n\n";

            $isValid = isset($firstCombi['filiere']['id']) && isset($firstCombi['niveau']['id']);
            echo ($isValid ? "✅" : "❌") . " Combinaisons " . ($isValid ? "VALIDES" : "INVALIDES") . "\n\n";
        }
    }

    echo "════════════════════════════════════════════\n";
    echo "✅ Test terminé avec succès\n";
    echo "════════════════════════════════════════════\n";

} catch (\Exception $e) {
    echo "❌ ERREUR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
