<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Evaluation;
use App\Models\User;
use App\Services\KlassciProxyService;

echo "🔍 DIAGNOSTIC ÉVALUATIONS\n\n";

// 1. Compter les évaluations
echo "📊 ÉVALUATIONS EN BASE:\n";
$totalEvals = Evaluation::count();
$publishedEvals = Evaluation::where('is_published', true)->count();
echo "  - Total: {$totalEvals}\n";
echo "  - Publiées: {$publishedEvals}\n\n";

// 2. Afficher les détails
echo "📋 DÉTAILS DES ÉVALUATIONS:\n";
$evaluations = Evaluation::all();
foreach ($evaluations as $eval) {
    echo "  ID: {$eval->id}\n";
    echo "    Titre: {$eval->titre}\n";
    echo "    Matière ID: {$eval->klassci_matiere_id}\n";
    echo "    Classe ID: {$eval->klassci_classe_id}\n";
    echo "    Enseignant ID: {$eval->klassci_enseignant_id}\n";
    echo "    Publiée: " . ($eval->is_published ? 'Oui' : 'Non') . "\n";
    echo "    Status: {$eval->status}\n";
    echo "    Date: " . ($eval->date_evaluation ? $eval->date_evaluation->format('Y-m-d H:i') : 'N/A') . "\n";
    echo "\n";
}

// 3. Tester l'appel KLASSCI
echo "🌐 TEST APPEL KLASSCI:\n";
try {
    $klassciService = app(KlassciProxyService::class);

    // Trouver un utilisateur avec token
    $userWithToken = User::whereNotNull('klassci_token')->first();

    if ($userWithToken) {
        echo "  Utilisateur: {$userWithToken->name} (ID: {$userWithToken->id}, Klassci ID: {$userWithToken->klassci_id})\n";
        echo "  Token présent: Oui\n";

        // Test appel classes
        echo "\n  Test getClasses()...\n";
        $classes = $klassciService->requestWithUserToken(
            $userWithToken->klassci_token,
            'classes',
            'GET'
        );

        if (isset($classes['data'])) {
            echo "    ✅ Succès: " . count($classes['data']) . " classe(s) trouvée(s)\n";

            // Afficher quelques classes
            $first3 = array_slice($classes['data'], 0, 3);
            foreach ($first3 as $classe) {
                echo "      - ID: {$classe['id']}, Nom: " . ($classe['libelle'] ?? $classe['name'] ?? 'N/A') . "\n";
            }
        } else {
            echo "    ⚠️  Aucune donnée retournée\n";
        }

        // Test appel matières
        echo "\n  Test getMatieres()...\n";
        $matieres = $klassciService->requestWithUserToken(
            $userWithToken->klassci_token,
            'matieres',
            'GET'
        );

        if (isset($matieres['data'])) {
            echo "    ✅ Succès: " . count($matieres['data']) . " matière(s) trouvée(s)\n";

            // Afficher quelques matières
            $first3 = array_slice($matieres['data'], 0, 3);
            foreach ($first3 as $matiere) {
                echo "      - ID: {$matiere['id']}, Nom: " . ($matiere['nom'] ?? $matiere['libelle'] ?? 'N/A') . "\n";
            }
        } else {
            echo "    ⚠️  Aucune donnée retournée\n";
        }

    } else {
        echo "  ❌ Aucun utilisateur avec token KLASSCI trouvé\n";
    }

} catch (\Exception $e) {
    echo "  ❌ Erreur: {$e->getMessage()}\n";
}

echo "\n✅ DIAGNOSTIC TERMINÉ\n";
