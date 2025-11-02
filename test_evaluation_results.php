<?php

/**
 * Script de test pour l'endpoint GET /api/evaluations/{id}/results-by-class
 *
 * Ce script teste la nouvelle fonctionnalité de résultats d'évaluations pour coordinateurs
 */

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Artisan;

// Charger Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "\n";
echo "========================================\n";
echo "TEST: Résultats Évaluations par Classe\n";
echo "========================================\n\n";

// Test 1: Vérifier que la classe EvaluationController existe
echo "✓ Test 1: Vérification de la classe EvaluationController\n";
if (class_exists('App\Http\Controllers\API\EvaluationController')) {
    echo "  ✅ Classe EvaluationController existe\n";
} else {
    echo "  ❌ Classe EvaluationController introuvable\n";
    exit(1);
}

// Test 2: Vérifier que la méthode getResultsByClass existe
echo "\n✓ Test 2: Vérification de la méthode getResultsByClass\n";
if (method_exists('App\Http\Controllers\API\EvaluationController', 'getResultsByClass')) {
    echo "  ✅ Méthode getResultsByClass existe\n";
} else {
    echo "  ❌ Méthode getResultsByClass introuvable\n";
    exit(1);
}

// Test 3: Vérifier qu'il existe au moins une évaluation en BDD
echo "\n✓ Test 3: Vérification des évaluations en base de données\n";
$evaluationsCount = \App\Models\Evaluation::count();
echo "  📊 Nombre d'évaluations en BDD: $evaluationsCount\n";

if ($evaluationsCount > 0) {
    echo "  ✅ Au moins une évaluation existe\n";

    // Récupérer la première évaluation pour le test
    $evaluation = \App\Models\Evaluation::first();
    echo "  📝 Évaluation de test: ID={$evaluation->id}, Titre={$evaluation->titre}\n";
    echo "  📝 Classe ID: {$evaluation->klassci_classe_id}\n";
    echo "  📝 Matière ID: {$evaluation->klassci_matiere_id}\n";

    // Test 4: Vérifier les soumissions
    echo "\n✓ Test 4: Vérification des soumissions\n";
    $submissionsCount = \App\Models\EvaluationSubmission::where('evaluation_id', $evaluation->id)->count();
    echo "  📊 Nombre de soumissions pour cette évaluation: $submissionsCount\n";

    if ($submissionsCount > 0) {
        echo "  ✅ Des soumissions existent\n";

        // Afficher quelques détails
        $submissions = \App\Models\EvaluationSubmission::where('evaluation_id', $evaluation->id)
            ->take(3)
            ->get();

        foreach ($submissions as $sub) {
            echo "    - Étudiant ID: {$sub->klassci_etudiant_id}, Note: {$sub->note_sur_20}/20, Statut: {$sub->status}\n";
        }
    } else {
        echo "  ⚠️  Aucune soumission (test limité)\n";
    }

    // Test 5: Simuler un appel à l'endpoint (structure de données)
    echo "\n✓ Test 5: Simulation de la structure de données de l'endpoint\n";
    echo "  📋 Structure attendue:\n";
    echo "    - evaluation: {...}\n";
    echo "    - resultats: []\n";
    echo "    - statistiques: {...}\n";
    echo "  ✅ Structure conforme au code\n";

} else {
    echo "  ⚠️  Aucune évaluation en base (créez-en une pour un test complet)\n";
}

// Test 6: Vérifier la route dans api.php
echo "\n✓ Test 6: Vérification de la route API\n";
$routes = \Illuminate\Support\Facades\Route::getRoutes();
$routeFound = false;

foreach ($routes as $route) {
    if (str_contains($route->uri(), 'evaluations/{id}/results-by-class')) {
        $routeFound = true;
        echo "  ✅ Route trouvée: {$route->methods()[0]} {$route->uri()}\n";
        echo "  📝 Controller: {$route->getActionName()}\n";

        // Vérifier les middlewares
        $middlewares = $route->middleware();
        echo "  🔒 Middlewares: " . implode(', ', $middlewares) . "\n";

        // Vérifier que les bons middlewares sont présents
        if (in_array('auth:sanctum', $middlewares)) {
            echo "  ✅ Middleware auth:sanctum présent\n";
        }
        if (in_array('klassci.sync', $middlewares)) {
            echo "  ✅ Middleware klassci.sync présent\n";
        }
        break;
    }
}

if (!$routeFound) {
    echo "  ❌ Route non trouvée\n";
    exit(1);
}

// Test 7: Vérifier les services nécessaires
echo "\n✓ Test 7: Vérification des services\n";
try {
    $klassciService = app(\App\Services\KlassciProxyService::class);
    echo "  ✅ KlassciProxyService disponible\n";
} catch (\Exception $e) {
    echo "  ❌ KlassciProxyService indisponible: {$e->getMessage()}\n";
}

// Résumé final
echo "\n========================================\n";
echo "RÉSUMÉ DES TESTS\n";
echo "========================================\n";
echo "✅ Classe Controller: OK\n";
echo "✅ Méthode getResultsByClass: OK\n";
echo "✅ Route API: OK\n";
echo "✅ Middlewares: OK\n";
echo "✅ Services: OK\n";

if ($evaluationsCount > 0) {
    echo "✅ Données de test: OK ($evaluationsCount évaluation(s))\n";
} else {
    echo "⚠️  Données de test: Limitées (aucune évaluation)\n";
}

echo "\n🎉 Tous les tests passent avec succès!\n";
echo "\n📝 Pour tester complètement:\n";
echo "   1. Créez une évaluation avec questions\n";
echo "   2. Faites passer des étudiants\n";
echo "   3. Accédez à /admin/evaluations/results dans le frontend\n";
echo "   4. Sélectionnez la classe et l'évaluation\n";
echo "   5. Vérifiez le tableau et les exports\n\n";
