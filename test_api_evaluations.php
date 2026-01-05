<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Evaluation;
use App\Models\User;
use App\Http\Controllers\API\EvaluationController;
use Illuminate\Http\Request;

echo "🔍 DIAGNOSTIC API ÉVALUATIONS\n\n";

// 1. Vérifier les évaluations en BDD
echo "📊 ÉVALUATIONS EN BASE DE DONNÉES:\n";
$evaluations = Evaluation::all();
echo "  Total: {$evaluations->count()}\n";
echo "  Publiées: " . Evaluation::where('is_published', true)->count() . "\n\n";

foreach ($evaluations as $eval) {
    echo "  - ID {$eval->id}: {$eval->titre}\n";
    echo "    Matière: {$eval->matiere_nom}\n";
    echo "    Classe: {$eval->classe_nom}\n";
    echo "    Enseignant: {$eval->enseignant_nom}\n";
    echo "    Publié: " . ($eval->is_published ? 'Oui' : 'Non') . "\n\n";
}

// 2. Tester la méthode enrichEvaluationsWithKlassciData
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🧪 TEST DE LA MÉTHODE enrichEvaluationsWithKlassciData\n\n";

try {
    $user = User::where('role', 'coordinateur')
        ->orWhere('role', 'enseignant')
        ->whereNotNull('klassci_token')
        ->first();

    if (!$user) {
        echo "❌ Aucun utilisateur trouvé\n";
        exit(1);
    }

    echo "👤 Utilisateur: {$user->name} (role: {$user->role})\n\n";

    // Simuler une requête
    $request = Request::create('/api/evaluations', 'GET');
    $request->setUserResolver(function () use ($user) {
        return $user;
    });

    // Créer le controller
    $controller = app(EvaluationController::class);

    // Appeler index()
    echo "📡 Appel de EvaluationController@index()...\n";
    $response = $controller->index($request);

    $data = json_decode($response->getContent(), true);

    echo "🔍 Réponse de l'API:\n";
    echo "  Success: " . ($data['success'] ? 'Oui' : 'Non') . "\n";

    if (isset($data['data'])) {
        echo "  Nombre d'évaluations: " . count($data['data']) . "\n\n";

        if (count($data['data']) > 0) {
            echo "  Première évaluation:\n";
            $first = $data['data'][0];
            echo "    ID: " . ($first['id'] ?? 'N/A') . "\n";
            echo "    Titre: " . ($first['titre'] ?? 'N/A') . "\n";
            echo "    Matière: " . ($first['matiere']['nom'] ?? $first['matiere_nom'] ?? 'N/A') . "\n";
            echo "    Classe: " . ($first['classe']['nom'] ?? $first['classe_nom'] ?? 'N/A') . "\n";
            echo "    Enseignant: " . ($first['enseignant']['nom'] ?? $first['enseignant_nom'] ?? 'N/A') . "\n";
        } else {
            echo "  ⚠️  TABLEAU VIDE !\n";
        }
    } else {
        echo "  ⚠️  Pas de clé 'data' dans la réponse\n";
    }

    echo "\n📋 Réponse complète:\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

} catch (\Exception $e) {
    echo "❌ ERREUR: {$e->getMessage()}\n";
    echo "  Fichier: {$e->getFile()}:{$e->getLine()}\n";
    echo "  Trace:\n{$e->getTraceAsString()}\n";
}

echo "\n✅ DIAGNOSTIC TERMINÉ\n";
