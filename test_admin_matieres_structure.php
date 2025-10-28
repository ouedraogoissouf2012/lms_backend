<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Récupérer un administrateur
$user = App\Models\User::where('role', 'admin')
    ->orWhere('role', 'administrateur')
    ->first();

if (!$user) {
    // Essayer avec un enseignant pour tester
    $user = App\Models\User::where('role', 'teacher')
        ->orWhere('role', 'enseignant')
        ->first();

    if (!$user) {
        echo "⚠ Aucun utilisateur trouvé dans la base de données\n";
        exit(1);
    }
    echo "⚠ Utilisation d'un compte enseignant pour le test\n\n";
}

echo "════════════════════════════════════════════\n";
echo "TEST STRUCTURE ADMIN MATIERES\n";
echo "════════════════════════════════════════════\n\n";

echo "User ID: {$user->id}\n";
echo "Email: {$user->email}\n";
echo "Role: {$user->role}\n";
echo "Token KLASSCI: " . ($user->klassci_token ? 'Présent' : '❌ MANQUANT') . "\n\n";

if (!$user->klassci_token) {
    echo "⚠ L'utilisateur n'a pas de token KLASSCI. Impossible de tester l'API.\n";
    exit(1);
}

// Appeler le service KLASSCI
$service = app(App\Services\KlassciProxyService::class);

try {
    echo "📡 Appel API KLASSCI: me/teacher-dashboard\n";
    echo "────────────────────────────────────────────\n\n";

    $data = $service->requestWithUserToken(
        $user->klassci_token,
        'me/teacher-dashboard',
        'GET'
    );

    if (!$data || !isset($data['data'])) {
        echo "❌ Réponse invalide de l'API\n";
        exit(1);
    }

    $dashboard = $data['data'];

    // Afficher les matières
    $matieres = $dashboard['matieres'] ?? [];
    echo "📖 MATIÈRES: " . count($matieres) . "\n";
    echo "────────────────────────────────────────────\n";

    if (count($matieres) === 0) {
        echo "  ⚠ Aucune matière trouvée\n";
        exit(0);
    }

    $matiere = $matieres[0];
    echo "Structure de la première matière:\n";
    echo "  - id: " . ($matiere['id'] ?? 'N/A') . "\n";
    echo "  - nom: " . ($matiere['nom'] ?? $matiere['name'] ?? 'N/A') . "\n";
    echo "  - code: " . ($matiere['code'] ?? 'N/A') . "\n";
    echo "  - coefficient: " . ($matiere['coefficient'] ?? 'N/A') . "\n";
    echo "  - couleur: " . ($matiere['couleur'] ?? 'N/A') . "\n";
    echo "  - description: " . (isset($matiere['description']) ? (strlen($matiere['description']) > 50 ? substr($matiere['description'], 0, 50) . '...' : $matiere['description']) : 'N/A') . "\n";
    echo "  - heures_total: " . ($matiere['heures_total'] ?? 'N/A') . "\n";
    echo "  - nb_seances_programmees: " . ($matiere['nb_seances_programmees'] ?? 'N/A') . "\n";
    echo "\n";
    echo "Toutes les clés disponibles: " . implode(', ', array_keys($matiere)) . "\n";

    // Vérifier 'combinaisons'
    if (isset($matiere['combinaisons'])) {
        echo "\n  → Contient 'combinaisons': " . count($matiere['combinaisons']) . " éléments\n";
        if (count($matiere['combinaisons']) > 0) {
            echo "     Structure du premier élément:\n";
            $combi = $matiere['combinaisons'][0];
            echo "     Clés: " . implode(', ', array_keys($combi)) . "\n";

            // Afficher filiere
            if (isset($combi['filiere'])) {
                echo "     → filiere:\n";
                echo "        id: " . ($combi['filiere']['id'] ?? 'N/A') . "\n";
                echo "        code: " . ($combi['filiere']['code'] ?? 'N/A') . "\n";
                echo "        nom: " . ($combi['filiere']['nom'] ?? $combi['filiere']['name'] ?? 'N/A') . "\n";
            }

            // Afficher niveau
            if (isset($combi['niveau'])) {
                echo "     → niveau:\n";
                echo "        id: " . ($combi['niveau']['id'] ?? 'N/A') . "\n";
                echo "        code: " . ($combi['niveau']['code'] ?? 'N/A') . "\n";
                echo "        nom: " . ($combi['niveau']['nom'] ?? $combi['niveau']['name'] ?? 'N/A') . "\n";
            }
        }
    } else {
        echo "\n  ⚠ Pas de 'combinaisons' dans cette matière\n";
    }

    echo "\n";

    // Calculer les statistiques globales
    $totalHeures = 0;
    $totalSeances = 0;
    $totalCombinaisons = 0;
    $filieres = [];
    $niveaux = [];

    foreach ($matieres as $m) {
        $totalHeures += ($m['heures_total'] ?? 0);
        $totalSeances += ($m['nb_seances_programmees'] ?? 0);

        if (isset($m['combinaisons']) && is_array($m['combinaisons'])) {
            $totalCombinaisons += count($m['combinaisons']);

            foreach ($m['combinaisons'] as $combi) {
                if (isset($combi['filiere']['id'])) {
                    $filieres[$combi['filiere']['id']] = $combi['filiere'];
                }
                if (isset($combi['niveau']['id'])) {
                    $niveaux[$combi['niveau']['id']] = $combi['niveau'];
                }
            }
        }
    }

    echo "📊 STATISTIQUES GLOBALES:\n";
    echo "────────────────────────────────────────────\n";
    echo "  Total Matières: " . count($matieres) . "\n";
    echo "  Total Heures: " . $totalHeures . "\n";
    echo "  Séances Programmées: " . $totalSeances . "\n";
    echo "  Total Combinaisons: " . $totalCombinaisons . "\n";
    echo "  Filières uniques: " . count($filieres) . "\n";
    echo "  Niveaux uniques: " . count($niveaux) . "\n";

    echo "\n";
    echo "🔍 FILIÈRES DISPONIBLES:\n";
    echo "────────────────────────────────────────────\n";
    foreach ($filieres as $filiere) {
        echo "  - [" . ($filiere['code'] ?? 'N/A') . "] " . ($filiere['nom'] ?? $filiere['name'] ?? 'N/A') . "\n";
    }

    echo "\n";
    echo "🔍 NIVEAUX DISPONIBLES:\n";
    echo "────────────────────────────────────────────\n";
    foreach ($niveaux as $niveau) {
        echo "  - [" . ($niveau['code'] ?? 'N/A') . "] " . ($niveau['nom'] ?? $niveau['name'] ?? 'N/A') . "\n";
    }

    // Test de filtrage
    if (count($filieres) > 0 && count($niveaux) > 0) {
        $testFiliere = array_values($filieres)[0];
        $testNiveau = array_values($niveaux)[0];

        echo "\n";
        echo "🔍 Test de filtrage:\n";
        echo "────────────────────────────────────────────\n";
        echo "  Filière: " . ($testFiliere['nom'] ?? $testFiliere['name'] ?? 'N/A') . "\n";
        echo "  Niveau: " . ($testNiveau['nom'] ?? $testNiveau['name'] ?? 'N/A') . "\n";

        $matieresFiltered = array_filter($matieres, function($m) use ($testFiliere, $testNiveau) {
            if (!isset($m['combinaisons']) || !is_array($m['combinaisons'])) {
                return false;
            }

            foreach ($m['combinaisons'] as $combi) {
                $filiereMatch = isset($combi['filiere']['id']) && $combi['filiere']['id'] === $testFiliere['id'];
                $niveauMatch = isset($combi['niveau']['id']) && $combi['niveau']['id'] === $testNiveau['id'];

                if ($filiereMatch && $niveauMatch) {
                    return true;
                }
            }

            return false;
        });

        echo "  Matières trouvées: " . count($matieresFiltered) . "\n";
        if (count($matieresFiltered) > 0) {
            foreach ($matieresFiltered as $m) {
                echo "     - " . ($m['nom'] ?? $m['name']) . "\n";
            }
        }
    }

    echo "\n";
    echo "════════════════════════════════════════════\n";
    echo "✅ Test terminé avec succès\n";
    echo "════════════════════════════════════════════\n";

} catch (\Exception $e) {
    echo "\n❌ ERREUR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
