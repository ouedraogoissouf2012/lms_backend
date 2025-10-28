<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Récupérer un enseignant
$user = App\Models\User::where('role', 'teacher')
    ->orWhere('role', 'enseignant')
    ->first();

if (!$user) {
    echo "⚠ Aucun enseignant trouvé dans la base de données\n";
    exit(1);
}

echo "════════════════════════════════════════════\n";
echo "TEST STRUCTURE TEACHER DASHBOARD\n";
echo "════════════════════════════════════════════\n\n";

echo "User ID: {$user->id}\n";
echo "Email: {$user->email}\n";
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

    // Afficher les classes
    $classes = $dashboard['classes'] ?? [];
    echo "📚 CLASSES: " . count($classes) . "\n";
    echo "────────────────────────────────────────────\n";

    if (count($classes) > 0) {
        $classe = $classes[0];
        echo "Structure d'une classe:\n";
        echo "  - id: " . ($classe['id'] ?? 'N/A') . "\n";
        echo "  - name: " . ($classe['name'] ?? $classe['libelle'] ?? 'N/A') . "\n";
        echo "  - effectif_max: " . ($classe['effectif_max'] ?? 'N/A') . "\n";
        echo "  - capacite: " . ($classe['capacite'] ?? 'N/A') . "\n";
        echo "  - places_totales: " . ($classe['places_totales'] ?? 'N/A') . "\n";
        echo "  - effectif: " . ($classe['effectif'] ?? 'N/A') . "\n";
        echo "\n";
        echo "Toutes les clés disponibles: " . implode(', ', array_keys($classe)) . "\n";

        // Afficher filiere et niveau
        if (isset($classe['filiere'])) {
            echo "\n  → Filière:\n";
            echo "     id: " . ($classe['filiere']['id'] ?? 'N/A') . "\n";
            echo "     code: " . ($classe['filiere']['code'] ?? 'N/A') . "\n";
            echo "     name: " . ($classe['filiere']['name'] ?? $classe['filiere']['nom'] ?? 'N/A') . "\n";
        }
        if (isset($classe['niveau'])) {
            echo "\n  → Niveau:\n";
            echo "     id: " . ($classe['niveau']['id'] ?? 'N/A') . "\n";
            echo "     code: " . ($classe['niveau']['code'] ?? 'N/A') . "\n";
            echo "     name: " . ($classe['niveau']['name'] ?? $classe['niveau']['nom'] ?? 'N/A') . "\n";
        }
    } else {
        echo "  ⚠ Aucune classe trouvée\n";
    }

    echo "\n";

    // Afficher les matières
    $matieres = $dashboard['matieres'] ?? [];
    echo "📖 MATIÈRES: " . count($matieres) . "\n";
    echo "────────────────────────────────────────────\n";

    if (count($matieres) > 0) {
        $matiere = $matieres[0];
        echo "Structure d'une matière:\n";
        echo "  - id: " . ($matiere['id'] ?? 'N/A') . "\n";
        echo "  - name/nom: " . ($matiere['name'] ?? $matiere['nom'] ?? 'N/A') . "\n";
        echo "  - classe_id: " . ($matiere['classe_id'] ?? 'N/A') . "\n";
        echo "  - klassci_classe_id: " . ($matiere['klassci_classe_id'] ?? 'N/A') . "\n";
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
                }

                // Afficher niveau
                if (isset($combi['niveau'])) {
                    echo "     → niveau:\n";
                    echo "        id: " . ($combi['niveau']['id'] ?? 'N/A') . "\n";
                    echo "        code: " . ($combi['niveau']['code'] ?? 'N/A') . "\n";
                }
            }
        }

        // Vérifier si 'classe' ou 'classes' existe
        if (isset($matiere['classe'])) {
            echo "\n  → Contient un objet 'classe': id=" . ($matiere['classe']['id'] ?? 'N/A') . "\n";
        }
        if (isset($matiere['classes'])) {
            echo "\n  → Contient un tableau 'classes': " . count($matiere['classes']) . " éléments\n";
            if (count($matiere['classes']) > 0) {
                echo "     Premier élément: id=" . ($matiere['classes'][0]['id'] ?? 'N/A') . "\n";
            }
        }

        // Tester le filtrage pour la classe 1
        if (count($classes) > 0) {
            $classe = $classes[0];
            $classeId = $classe['id'];
            $filiereId = $classe['filiere']['id'] ?? null;
            $niveauId = $classe['niveau']['id'] ?? null;

            echo "\n";
            echo "🔍 Test de filtrage pour classe '{$classe['name']}':\n";
            echo "   classe_id={$classeId}, filiere_id={$filiereId}, niveau_id={$niveauId}\n";

            $matieresFiltered = array_filter($matieres, function($m) use ($classeId, $filiereId, $niveauId) {
                // Test 1: classe_id direct
                if (($m['classe_id'] ?? null) === $classeId) return true;

                // Test 2: via combinaisons filiere+niveau
                if (isset($m['combinaisons']) && is_array($m['combinaisons'])) {
                    foreach ($m['combinaisons'] as $combi) {
                        $combiFiliereId = $combi['filiere']['id'] ?? null;
                        $combiNiveauId = $combi['niveau']['id'] ?? null;

                        if ($combiFiliereId === $filiereId && $combiNiveauId === $niveauId) {
                            return true;
                        }
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
    } else {
        echo "  ⚠ Aucune matière trouvée\n";
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
