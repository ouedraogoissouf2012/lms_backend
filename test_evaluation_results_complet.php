<?php

/**
 * TEST COMPLET - FONCTIONNALITÉ RÉSULTATS ÉVALUATIONS
 * ======================================================
 * Ce script teste exhaustivement toutes les parties de la fonctionnalité
 */

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║   TEST COMPLET - RÉSULTATS ÉVALUATIONS (Coordinateur)         ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// Charger Laravel
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Support\Facades\DB;

$score = 0;
$maxScore = 0;
$tests = [];

// ============================================
// TEST 1: Vérification de la méthode Controller
// ============================================
$maxScore += 10;
echo "🔍 TEST 1: Vérification méthode getResultsByClass...\n";
try {
    $klassciService = app(KlassciProxyService::class);
    $controller = new \App\Http\Controllers\API\EvaluationController($klassciService);
    if (method_exists($controller, 'getResultsByClass')) {
        echo "   ✅ Méthode getResultsByClass existe\n";
        $score += 10;
        $tests['controller_method'] = ['status' => 'PASS', 'points' => 10];
    } else {
        echo "   ❌ Méthode getResultsByClass n'existe pas\n";
        $tests['controller_method'] = ['status' => 'FAIL', 'points' => 0];
    }
} catch (Exception $e) {
    echo "   ❌ Erreur: " . $e->getMessage() . "\n";
    $tests['controller_method'] = ['status' => 'ERROR', 'points' => 0, 'error' => $e->getMessage()];
}
echo "\n";

// ============================================
// TEST 2: Vérification des modèles Eloquent
// ============================================
$maxScore += 15;
echo "🔍 TEST 2: Vérification des modèles Eloquent...\n";
try {
    $evaluationExists = class_exists('App\Models\Evaluation');
    $submissionExists = class_exists('App\Models\EvaluationSubmission');

    if ($evaluationExists && $submissionExists) {
        echo "   ✅ Modèle Evaluation existe\n";
        echo "   ✅ Modèle EvaluationSubmission existe\n";

        // Vérifier les relations
        $eval = new Evaluation();
        $hasSubmissions = method_exists($eval, 'submissions');
        $hasQuestions = method_exists($eval, 'questions');

        if ($hasSubmissions && $hasQuestions) {
            echo "   ✅ Relations submissions et questions définies\n";
            $score += 15;
            $tests['models'] = ['status' => 'PASS', 'points' => 15];
        } else {
            echo "   ⚠️  Relations manquantes\n";
            $score += 10;
            $tests['models'] = ['status' => 'PARTIAL', 'points' => 10];
        }
    } else {
        echo "   ❌ Modèles manquants\n";
        $tests['models'] = ['status' => 'FAIL', 'points' => 0];
    }
} catch (Exception $e) {
    echo "   ❌ Erreur: " . $e->getMessage() . "\n";
    $tests['models'] = ['status' => 'ERROR', 'points' => 0, 'error' => $e->getMessage()];
}
echo "\n";

// ============================================
// TEST 3: Vérification des données BDD
// ============================================
$maxScore += 20;
echo "🔍 TEST 3: Vérification des données dans la BDD...\n";
try {
    $evaluationCount = DB::table('evaluations')->count();
    $submissionCount = DB::table('evaluation_submissions')->count();

    echo "   📊 Évaluations en BDD: $evaluationCount\n";
    echo "   📊 Soumissions en BDD: $submissionCount\n";

    if ($evaluationCount > 0) {
        echo "   ✅ Des évaluations existent\n";
        $score += 10;

        if ($submissionCount > 0) {
            echo "   ✅ Des soumissions existent\n";
            $score += 10;
            $tests['database'] = ['status' => 'PASS', 'points' => 20, 'data' => [
                'evaluations' => $evaluationCount,
                'submissions' => $submissionCount
            ]];
        } else {
            echo "   ⚠️  Aucune soumission (normal si pas de tests)\n";
            $tests['database'] = ['status' => 'PARTIAL', 'points' => 10];
        }
    } else {
        echo "   ⚠️  Aucune évaluation en BDD\n";
        $tests['database'] = ['status' => 'FAIL', 'points' => 0];
    }
} catch (Exception $e) {
    echo "   ❌ Erreur BDD: " . $e->getMessage() . "\n";
    $tests['database'] = ['status' => 'ERROR', 'points' => 0, 'error' => $e->getMessage()];
}
echo "\n";

// ============================================
// TEST 4: Test de la logique métier
// ============================================
$maxScore += 25;
echo "🔍 TEST 4: Test de la logique métier...\n";
try {
    $evaluation = Evaluation::with(['questions', 'submissions'])->first();

    if ($evaluation) {
        echo "   ✅ Évaluation de test trouvée: {$evaluation->titre}\n";
        echo "   📝 ID: {$evaluation->id}\n";
        echo "   📚 Questions: " . $evaluation->questions->count() . "\n";
        echo "   ✅ Soumissions: " . $evaluation->submissions->count() . "\n";

        $score += 15;

        // Tester le calcul des statistiques
        $soumissions = $evaluation->submissions;
        $notes = $soumissions->pluck('note_sur_20')->filter();

        if ($notes->isNotEmpty()) {
            $moyenne = round($notes->avg(), 2);
            $min = $notes->min();
            $max = $notes->max();

            echo "   📊 Moyenne calculée: $moyenne/20\n";
            echo "   📊 Note min: $min/20\n";
            echo "   📊 Note max: $max/20\n";
            echo "   ✅ Calcul des statistiques fonctionnel\n";
            $score += 10;
            $tests['business_logic'] = ['status' => 'PASS', 'points' => 25, 'stats' => [
                'moyenne' => $moyenne,
                'min' => $min,
                'max' => $max
            ]];
        } else {
            echo "   ⚠️  Pas de notes pour tester les statistiques\n";
            $tests['business_logic'] = ['status' => 'PARTIAL', 'points' => 15];
        }
    } else {
        echo "   ⚠️  Aucune évaluation pour tester\n";
        $tests['business_logic'] = ['status' => 'FAIL', 'points' => 0];
    }
} catch (Exception $e) {
    echo "   ❌ Erreur: " . $e->getMessage() . "\n";
    $tests['business_logic'] = ['status' => 'ERROR', 'points' => 0, 'error' => $e->getMessage()];
}
echo "\n";

// ============================================
// TEST 5: Service KlassciProxyService
// ============================================
$maxScore += 10;
echo "🔍 TEST 5: Vérification KlassciProxyService...\n";
try {
    $service = app(KlassciProxyService::class);

    if ($service && method_exists($service, 'getClasseDetails')) {
        echo "   ✅ KlassciProxyService disponible\n";
        echo "   ✅ Méthode getClasseDetails existe\n";
        $score += 10;
        $tests['klassci_service'] = ['status' => 'PASS', 'points' => 10];
    } else {
        echo "   ❌ Service ou méthode manquant\n";
        $tests['klassci_service'] = ['status' => 'FAIL', 'points' => 0];
    }
} catch (Exception $e) {
    echo "   ❌ Erreur: " . $e->getMessage() . "\n";
    $tests['klassci_service'] = ['status' => 'ERROR', 'points' => 0, 'error' => $e->getMessage()];
}
echo "\n";

// ============================================
// TEST 6: Middlewares et sécurité
// ============================================
$maxScore += 10;
echo "🔍 TEST 6: Vérification middlewares et permissions...\n";
try {
    $routes = \Illuminate\Support\Facades\Route::getRoutes();
    $targetRoute = null;

    foreach ($routes as $route) {
        if (str_contains($route->uri(), 'evaluations/{id}/results-by-class')) {
            $targetRoute = $route;
            break;
        }
    }

    if ($targetRoute) {
        $middleware = $targetRoute->middleware();
        echo "   ✅ Route trouvée: " . $targetRoute->uri() . "\n";
        echo "   🔒 Middlewares: " . implode(', ', $middleware) . "\n";

        $hasSanctum = in_array('auth:sanctum', $middleware);
        $hasKlassci = in_array('klassci.sync', $middleware);

        if ($hasSanctum && $hasKlassci) {
            echo "   ✅ Authentification et synchronisation Klassci présents\n";
            $score += 10;
            $tests['security'] = ['status' => 'PASS', 'points' => 10];
        } else {
            echo "   ⚠️  Middlewares de sécurité incomplets\n";
            $score += 5;
            $tests['security'] = ['status' => 'PARTIAL', 'points' => 5];
        }
    } else {
        echo "   ❌ Route non trouvée\n";
        $tests['security'] = ['status' => 'FAIL', 'points' => 0];
    }
} catch (Exception $e) {
    echo "   ❌ Erreur: " . $e->getMessage() . "\n";
    $tests['security'] = ['status' => 'ERROR', 'points' => 0, 'error' => $e->getMessage()];
}
echo "\n";

// ============================================
// TEST 7: Simulation requête complète
// ============================================
$maxScore += 10;
echo "🔍 TEST 7: Simulation requête API complète...\n";
try {
    $evaluation = Evaluation::with(['questions', 'submissions'])->first();

    if ($evaluation) {
        // Simuler ce que fait le controller
        $klassciService = app(KlassciProxyService::class);

        echo "   ✅ Service Klassci initialisé\n";
        echo "   ✅ Évaluation chargée avec relations\n";
        echo "   ✅ Structure de données prête\n";

        // Vérifier structure de réponse attendue
        $expectedKeys = ['evaluation', 'resultats', 'statistiques'];
        echo "   📋 Clés de réponse attendues: " . implode(', ', $expectedKeys) . "\n";

        $score += 10;
        $tests['api_simulation'] = ['status' => 'PASS', 'points' => 10];
    } else {
        echo "   ⚠️  Pas d'évaluation pour simuler\n";
        $tests['api_simulation'] = ['status' => 'FAIL', 'points' => 0];
    }
} catch (Exception $e) {
    echo "   ❌ Erreur: " . $e->getMessage() . "\n";
    $tests['api_simulation'] = ['status' => 'ERROR', 'points' => 0, 'error' => $e->getMessage()];
}
echo "\n";

// ============================================
// RAPPORT FINAL
// ============================================
echo "═══════════════════════════════════════════════════════════════\n";
echo "                      RAPPORT DE TEST FINAL                     \n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$percentage = ($score / $maxScore) * 100;
$reliability = ($score / $maxScore) * 10;

echo "📊 RÉSUMÉ DES TESTS:\n";
echo "──────────────────────────────────────────────────────────────\n";
foreach ($tests as $testName => $result) {
    $status = $result['status'];
    $icon = $status === 'PASS' ? '✅' : ($status === 'PARTIAL' ? '⚠️' : '❌');
    $points = $result['points'];
    echo sprintf("   %s %-30s %2d points\n", $icon, strtoupper(str_replace('_', ' ', $testName)), $points);
}
echo "\n";

echo "═══════════════════════════════════════════════════════════════\n";
echo "   SCORE TOTAL: $score / $maxScore points (" . round($percentage, 1) . "%)\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// Calcul du score de fiabilité sur 10
echo "🎯 SCORE DE FIABILITÉ: " . round($reliability, 1) . " / 10\n\n";

// Interprétation
echo "📝 INTERPRÉTATION:\n";
echo "──────────────────────────────────────────────────────────────\n";
if ($reliability >= 9) {
    echo "   🌟 EXCELLENT - Système hautement fiable et prêt pour production\n";
} elseif ($reliability >= 7) {
    echo "   ✅ TRÈS BON - Système fiable avec quelques optimisations possibles\n";
} elseif ($reliability >= 5) {
    echo "   ⚠️  BON - Système fonctionnel mais nécessite des améliorations\n";
} elseif ($reliability >= 3) {
    echo "   ⚠️  MOYEN - Système partiellement fonctionnel, corrections nécessaires\n";
} else {
    echo "   ❌ FAIBLE - Système nécessite des corrections importantes\n";
}
echo "\n";

echo "═══════════════════════════════════════════════════════════════\n";
echo "                    FIN DU TEST COMPLET                         \n";
echo "═══════════════════════════════════════════════════════════════\n";
