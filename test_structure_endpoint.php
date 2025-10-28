<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Récupérer un enseignant
$user = App\Models\User::where('role', 'enseignant')->first();

if (!$user || !$user->klassci_token) {
    echo "⚠ Pas de token KLASSCI\n";
    exit(1);
}

$service = app(App\Services\KlassciProxyService::class);

echo "════════════════════════════════════════════\n";
echo "TEST ENDPOINT STRUCTURE\n";
echo "════════════════════════════════════════════\n\n";

try {
    echo "📡 Appel API: structure\n\n";
    $structure = $service->requestWithUserToken($user->klassci_token, 'structure', 'GET');

    if (!$structure) {
        echo "❌ Pas de réponse\n";
        exit(1);
    }

    echo "Réponse brute:\n";
    echo json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

    // Analyser la structure
    if (isset($structure['data'])) {
        $data = $structure['data'];

        if (isset($data['filieres'])) {
            echo "📚 FILIÈRES: " . count($data['filieres']) . "\n";
            foreach ($data['filieres'] as $filiere) {
                echo "  - [" . ($filiere['code'] ?? 'N/A') . "] " . ($filiere['nom'] ?? $filiere['name'] ?? 'N/A') . "\n";
            }
        }

        echo "\n";

        if (isset($data['niveaux'])) {
            echo "🎓 NIVEAUX: " . count($data['niveaux']) . "\n";
            foreach ($data['niveaux'] as $niveau) {
                echo "  - [" . ($niveau['code'] ?? 'N/A') . "] " . ($niveau['nom'] ?? $niveau['name'] ?? 'N/A') . "\n";
            }
        }
    }

    echo "\n✅ Test terminé\n";

} catch (\Exception $e) {
    echo "❌ ERREUR: " . $e->getMessage() . "\n";
    exit(1);
}
