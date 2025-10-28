<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Récupérer un enseignant
$user = App\Models\User::where('role', 'enseignant')->first();

if (!$user || !$user->klassci_token) {
    echo "⚠ Pas d'enseignant avec token KLASSCI\n";
    exit(1);
}

$service = app(App\Services\KlassciProxyService::class);

echo "════════════════════════════════════════════\n";
echo "TEST AFFICHAGE MATIERES ADMIN\n";
echo "════════════════════════════════════════════\n\n";

try {
    // Récupérer teacher-dashboard
    echo "📡 Récupération dashboard enseignant...\n";
    $dashboard = $service->requestWithUserToken($user->klassci_token, 'me/teacher-dashboard', 'GET');

    if (!$dashboard || !isset($dashboard['data'])) {
        echo "❌ Pas de données dashboard\n";
        exit(1);
    }

    $matieres = $dashboard['data']['matieres'] ?? [];
    echo "✓ " . count($matieres) . " matière(s) trouvée(s)\n\n";

    // Récupérer structure
    echo "📡 Récupération structure...\n";
    $structure = $service->requestWithUserToken($user->klassci_token, 'structure', 'GET');

    $filieres = $structure['data']['filieres'] ?? [];
    $niveaux = $structure['data']['niveaux_etude'] ?? [];

    echo "✓ " . count($filieres) . " filière(s)\n";
    echo "✓ " . count($niveaux) . " niveau(x)\n\n";

    // Analyser les matières et simuler le groupement
    echo "════════════════════════════════════════════\n";
    echo "SIMULATION GROUPEMENT PAR NIVEAU\n";
    echo "════════════════════════════════════════════\n\n";

    $groupes = [];

    foreach ($matieres as $matiere) {
        $nom = $matiere['nom'] ?? 'N/A';
        $combinaisons = $matiere['combinaisons'] ?? [];

        if (count($combinaisons) === 0) {
            // Groupe "Sans niveau"
            if (!isset($groupes['none'])) {
                $groupes['none'] = [
                    'label' => 'Sans niveau',
                    'matieres' => []
                ];
            }
            $groupes['none']['matieres'][] = $nom;
        } else {
            // Vérifier si les combinaisons ont des niveaux valides
            $hasValidNiveau = false;
            foreach ($combinaisons as $combi) {
                if (isset($combi['niveau']['id']) || isset($combi['niveau']['code'])) {
                    $hasValidNiveau = true;
                    break;
                }
            }

            if ($hasValidNiveau) {
                // Récupérer les niveaux uniques
                $niveauxIds = [];
                foreach ($combinaisons as $combi) {
                    if (isset($combi['niveau']['id'])) {
                        $niveauId = $combi['niveau']['id'];
                        $niveauNom = $combi['niveau']['nom'] ?? $combi['niveau']['code'] ?? 'N/A';

                        if (!isset($groupes[$niveauId])) {
                            $groupes[$niveauId] = [
                                'label' => $niveauNom,
                                'matieres' => []
                            ];
                        }
                        $groupes[$niveauId]['matieres'][] = $nom;
                    }
                }
            } else {
                // Groupe "Niveau non défini" (combinaisons vides)
                if (!isset($groupes['undefined'])) {
                    $groupes['undefined'] = [
                        'label' => 'Niveau non défini',
                        'matieres' => []
                    ];
                }
                $groupes['undefined']['matieres'][] = $nom;
            }
        }
    }

    // Afficher les groupes
    foreach ($groupes as $key => $groupe) {
        $count = count($groupe['matieres']);
        echo "📂 " . $groupe['label'] . " (" . $count . " matière(s))\n";
        echo "   " . str_repeat('─', 50) . "\n";

        foreach ($groupe['matieres'] as $matiere) {
            echo "   • " . $matiere . "\n";
        }
        echo "\n";
    }

    // Afficher un exemple de matière avec combinaisons vides
    echo "════════════════════════════════════════════\n";
    echo "EXEMPLE COMBINAISONS VIDES\n";
    echo "════════════════════════════════════════════\n\n";

    if (count($matieres) > 0) {
        $exemple = $matieres[0];
        echo "Matière: " . ($exemple['nom'] ?? 'N/A') . "\n";
        echo "Nombre de combinaisons: " . count($exemple['combinaisons'] ?? []) . "\n\n";

        if (isset($exemple['combinaisons']) && count($exemple['combinaisons']) > 0) {
            $combi = $exemple['combinaisons'][0];
            echo "Première combinaison:\n";
            echo "  - filiere.id: " . ($combi['filiere']['id'] ?? 'N/A') . "\n";
            echo "  - filiere.code: " . ($combi['filiere']['code'] ?? 'N/A') . "\n";
            echo "  - filiere.nom: " . ($combi['filiere']['nom'] ?? 'N/A') . "\n";
            echo "  - niveau.id: " . ($combi['niveau']['id'] ?? 'N/A') . "\n";
            echo "  - niveau.code: " . ($combi['niveau']['code'] ?? 'N/A') . "\n";
            echo "  - niveau.nom: " . ($combi['niveau']['nom'] ?? 'N/A') . "\n";
        }
    }

    echo "\n════════════════════════════════════════════\n";
    echo "✅ Test terminé\n";
    echo "════════════════════════════════════════════\n";

} catch (\Exception $e) {
    echo "❌ ERREUR: " . $e->getMessage() . "\n";
    exit(1);
}
