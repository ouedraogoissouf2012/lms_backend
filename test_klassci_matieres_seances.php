<?php

/**
 * Script pour tester la récupération des séances via l'endpoint KLASSCI /matieres/{id}
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "\n========================================\n";
echo "TEST RÉCUPÉRATION SÉANCES VIA /MATIERES\n";
echo "========================================\n\n";

// 1. Trouver un enseignant avec token
$enseignant = App\Models\User::where('role', 'enseignant')
    ->whereNotNull('klassci_token')
    ->first();

if (!$enseignant) {
    echo "❌ Aucun enseignant trouvé\n";
    exit(1);
}

echo "✅ Enseignant: {$enseignant->name}\n";
echo "   - KLASSCI ID: {$enseignant->klassci_id}\n";
echo "   - Token: Présent\n\n";

// 2. Récupérer les matières
echo "[1] Récupération de la liste des matières...\n";

try {
    $klassciService = app(App\Services\KlassciProxyService::class);

    $matieresResponse = $klassciService->get('matieres');

    $matieres = $matieresResponse['data'] ?? [];
    echo "✅ Total matières: " . count($matieres) . "\n\n";

    if (count($matieres) > 0) {
        // Prendre les 3 premières matières
        $matieresASonder = array_slice($matieres, 0, 3);

        foreach ($matieresASonder as $index => $matiere) {
            $matiereId = $matiere['id'];
            $matiereNom = $matiere['nom'] ?? $matiere['libelle'] ?? 'N/A';

            echo "[2." . ($index + 1) . "] Matière: {$matiereNom} (ID: {$matiereId})\n";

            try {
                // Récupérer les détails de la matière
                $matiereDetails = $klassciService->get("matieres/{$matiereId}");

                $seancesProgrammees = $matiereDetails['data']['seances_programmees'] ?? [];

                echo "     ✅ Séances programmées: " . count($seancesProgrammees) . "\n";

                if (count($seancesProgrammees) > 0) {
                    echo "     📋 Détails des séances:\n";

                    foreach ($seancesProgrammees as $i => $seance) {
                        if ($i >= 3) break; // Limiter à 3 séances par matière

                        echo "        - Séance " . ($i + 1) . ":\n";
                        echo "          ID: " . ($seance['id'] ?? 'N/A') . "\n";
                        echo "          Date: " . ($seance['programmation']['date'] ?? 'N/A') . "\n";
                        echo "          Heure: " . ($seance['programmation']['heure_debut'] ?? 'N/A') . " - " . ($seance['programmation']['heure_fin'] ?? 'N/A') . "\n";
                        echo "          Classe: " . ($seance['classe']['nom'] ?? 'N/A') . " (ID: " . ($seance['classe']['id'] ?? 'N/A') . ")\n";
                        echo "          Salle: " . ($seance['programmation']['salle'] ?? 'N/A') . "\n";

                        // Vérifier si cette séance existe en local
                        $localSeance = App\Models\Seance::where('klassci_seance_id', $seance['id'])->first();
                        if ($localSeance) {
                            echo "          ✅ Existe en local (ID: {$localSeance->id})\n";
                        } else {
                            echo "          ⚠️  N'existe PAS en local\n";
                        }
                        echo "\n";
                    }
                } else {
                    echo "     ℹ️  Aucune séance programmée pour cette matière\n";
                }

            } catch (Exception $e) {
                echo "     ❌ Erreur: " . $e->getMessage() . "\n";
            }

            echo "\n";
        }
    }

} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    exit(1);
}

echo "========================================\n";
echo "ANALYSE:\n";
echo "========================================\n\n";
echo "1. Si vous voyez des séances dans KLASSCI mais pas en local,\n";
echo "   c'est que la séance n'a jamais été synchronisée.\n\n";
echo "2. Les séances sont synchronisées quand:\n";
echo "   - L'enseignant accède à la page des séances\n";
echo "   - L'endpoint /lms/seances/upcoming est appelé\n\n";
echo "3. Vérifiez que la séance créée apparaît bien dans la liste\n";
echo "   des 'seances_programmees' de la matière concernée.\n\n";
