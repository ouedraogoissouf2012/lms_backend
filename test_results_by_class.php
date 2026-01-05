<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Http\Controllers\API\EvaluationController;
use Illuminate\Http\Request;
use App\Models\User;

echo "🧪 TEST : Résultats par classe\n\n";

// Utiliser l'évaluation ID 8 (qui a 2 soumissions)
$evaluationId = 8;

// Trouver un utilisateur avec token
$user = User::where('role', 'coordinateur')
    ->orWhere('role', 'enseignant')
    ->whereNotNull('klassci_token')
    ->first();

if (!$user) {
    echo "❌ Aucun utilisateur avec token trouvé\n";
    exit(1);
}

echo "👤 Utilisateur: {$user->name}\n";
echo "📝 Évaluation ID: {$evaluationId}\n\n";

// Simuler une requête
$request = Request::create("/api/evaluations/{$evaluationId}/results/class", 'GET');
$request->setUserResolver(function () use ($user) {
    return $user;
});

// Créer le controller
$controller = app(EvaluationController::class);

echo "📡 Appel de getResultsByClass({$evaluationId})...\n\n";

try {
    $response = $controller->getResultsByClass($evaluationId);
    $data = json_decode($response->getContent(), true);

    if ($data['success']) {
        echo "✅ SUCCESS\n\n";

        $resultats = $data['data']['resultats'] ?? [];
        $statistiques = $data['data']['statistiques'] ?? [];

        echo "📊 STATISTIQUES:\n";
        echo "  Total étudiants: " . ($statistiques['total_etudiants'] ?? 0) . "\n";
        echo "  Étudiants soumis: " . ($statistiques['etudiants_soumis'] ?? 0) . "\n";
        echo "  Moyenne: " . ($statistiques['moyenne_classe'] ?? '-') . "\n\n";

        echo "👥 RÉSULTATS ÉTUDIANTS:\n";
        foreach ($resultats as $resultat) {
            echo "  - {$resultat['etudiant_nom_complet']}\n";
            echo "    Note: " . ($resultat['note'] ?? '-') . "\n";
            echo "    Status: {$resultat['status']}\n\n";
        }
    } else {
        echo "❌ ERREUR: " . ($data['message'] ?? 'Unknown') . "\n";
    }

    echo "\n📋 RÉPONSE COMPLÈTE:\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

} catch (\Exception $e) {
    echo "❌ EXCEPTION: {$e->getMessage()}\n";
    echo "  Fichier: {$e->getFile()}:{$e->getLine()}\n";
    echo "  Trace:\n{$e->getTraceAsString()}\n";
}

echo "\n✅ TEST TERMINÉ\n";
