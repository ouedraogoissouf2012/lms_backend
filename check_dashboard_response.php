<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\KlassciProxyService;

echo "🔍 VÉRIFICATION RÉPONSE DASHBOARD ÉTUDIANT\n";
echo "===========================================\n\n";

// Récupérer MARCEL
$marcel = User::where('email', 'marcel.ouedraogo@esbtp.edu')->first();

if (!$marcel || !$marcel->klassci_token) {
    echo "❌ MARCEL non trouvé ou pas de token\n";
    exit(1);
}

echo "✅ Étudiant trouvé: {$marcel->name}\n";
echo "✅ Token présent\n\n";

$klassciService = app(KlassciProxyService::class);

echo "📡 Appel de l'API KLASSCI /me/dashboard...\n\n";

try {
    $dashboard = $klassciService->requestWithUserToken(
        $marcel->klassci_token,
        'me/dashboard',
        'GET'
    );

    echo "✅ Réponse reçue!\n\n";
    echo "📦 STRUCTURE COMPLÈTE DE LA RÉPONSE:\n";
    echo "====================================\n";
    echo json_encode($dashboard, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo "\n\n";

    echo "🔍 ANALYSE:\n";
    echo "===========\n";

    if (isset($dashboard['data']['classes'])) {
        echo "✅ 'data.classes' existe\n";
        echo "   Nombre: " . count($dashboard['data']['classes']) . "\n";
        echo "   Contenu: " . json_encode($dashboard['data']['classes'], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "❌ 'data.classes' N'EXISTE PAS\n";
    }

    if (isset($dashboard['data']['classe'])) {
        echo "✅ 'data.classe' (singulier) existe\n";
        echo "   Contenu: " . json_encode($dashboard['data']['classe'], JSON_PRETTY_PRINT) . "\n";
    }

    echo "\n📋 Clés disponibles dans 'data':\n";
    if (isset($dashboard['data'])) {
        foreach ($dashboard['data'] as $key => $value) {
            echo "  - {$key}: " . gettype($value) . "\n";
        }
    }

} catch (\Exception $e) {
    echo "❌ ERREUR: {$e->getMessage()}\n";
}
