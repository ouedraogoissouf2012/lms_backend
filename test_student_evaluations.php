<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Http\Controllers\API\EvaluationController;
use Illuminate\Http\Request;

echo "🧪 TEST : Évaluations pour un étudiant\n\n";

// Trouver un étudiant
$student = User::where('role', 'etudiant')->first();

if (!$student) {
    echo "❌ Aucun étudiant trouvé\n";
    exit(1);
}

echo "👤 Étudiant: {$student->name}\n";
echo "   Email: {$student->email}\n";
echo "   Klassci ID: {$student->klassci_id}\n";
echo "   Token: " . ($student->klassci_token ? 'Oui' : 'Non') . "\n\n";

// Simuler une requête
$request = Request::create('/api/evaluations', 'GET');
$request->setUserResolver(function () use ($student) {
    return $student;
});

// Créer le controller
$controller = app(EvaluationController::class);

echo "📡 Appel de EvaluationController@index() en tant qu'étudiant...\n";
$response = $controller->index($request);

$data = json_decode($response->getContent(), true);

echo "🔍 Réponse:\n";
echo "  Success: " . ($data['success'] ? 'Oui' : 'Non') . "\n";
echo "  Nombre d'évaluations: " . count($data['data'] ?? []) . "\n\n";

if (isset($data['data']) && count($data['data']) > 0) {
    echo "✅ Évaluations retournées:\n";
    foreach ($data['data'] as $eval) {
        echo "  - ID {$eval['id']}: {$eval['titre']}\n";
        echo "    Matière: {$eval['matiere_nom']}\n";
        echo "    Classe: {$eval['classe_nom']}\n";
        echo "    Statut: {$eval['status']}\n";
        echo "    Publié: " . ($eval['is_published'] ? 'Oui' : 'Non') . "\n";
        echo "    Soumission étudiant: " . (isset($eval['student_submission']) && $eval['student_submission'] ? 'Oui' : 'Non') . "\n";
        echo "\n";
    }
} else {
    echo "❌ AUCUNE ÉVALUATION RETOURNÉE\n\n";

    // Vérifier pourquoi
    echo "🔍 DIAGNOSTIC:\n";

    echo "\n1. Étudiants de la classe {$student->klassci_id} (si étudiant a une classe):\n";
    // Note: Un étudiant peut ne pas avoir klassci_classe_id dans la table users

    echo "\n2. Évaluations publiées:\n";
    $publishedEvals = \App\Models\Evaluation::where('is_published', true)->get();
    foreach ($publishedEvals as $eval) {
        echo "  - ID {$eval->id}: {$eval->titre}\n";
        echo "    Classe ID: {$eval->klassci_classe_id}\n";
        echo "    Status: {$eval->status}\n";
    }
}

echo "\n✅ TEST TERMINÉ\n";
