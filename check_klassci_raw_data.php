<?php

/**
 * Script pour vérifier les données brutes retournées par KLASSCI
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "\n========================================\n";
echo "VÉRIFICATION DONNÉES BRUTES KLASSCI\n";
echo "========================================\n\n";

// Trouver un enseignant
$enseignant = App\Models\User::where('role', 'enseignant')
    ->whereNotNull('klassci_token')
    ->first();

if (!$enseignant) {
    echo "❌ Aucun enseignant trouvé\n";
    exit(1);
}

echo "✅ Enseignant: {$enseignant->name} (ID KLASSCI: {$enseignant->klassci_id})\n\n";

$klassciService = app(App\Services\KlassciProxyService::class);

// 1. Tester plusieurs endpoints pour trouver les séances
$endpoints = [
    'matieres' => 'Liste des matières',
    'emploi-temps' => 'Emploi du temps',
    'seances' => 'Liste des séances',
];

foreach ($endpoints as $endpoint => $description) {
    echo "[TEST] {$description} (/{$endpoint})...\n";

    try {
        $response = $klassciService->get($endpoint);

        if (isset($response['data'])) {
            $data = $response['data'];

            if (is_array($data)) {
                echo "   ✅ Succès - " . count($data) . " éléments retournés\n";

                // Afficher un aperçu de la structure
                if (count($data) > 0) {
                    echo "   📋 Structure du premier élément:\n";
                    $premier = $data[0];
                    foreach ($premier as $key => $value) {
                        $type = is_array($value) ? 'array[' . count($value) . ']' : gettype($value);
                        echo "      - {$key}: {$type}\n";
                    }
                }
            } else {
                echo "   ✅ Succès - Réponse non-array\n";
                echo "   Type: " . gettype($data) . "\n";
            }
        } else {
            echo "   ⚠️  Réponse sans 'data'\n";
            echo "   Réponse: " . json_encode($response, JSON_PRETTY_PRINT) . "\n";
        }

    } catch (Exception $e) {
        echo "   ❌ Erreur: " . $e->getMessage() . "\n";
    }

    echo "\n";
}

// 2. Vérifier en détail l'endpoint matieres/{id}
echo "[DÉTAIL] Vérification des seances_programmees pour chaque matière...\n\n";

try {
    $matieresResponse = $klassciService->get('matieres');
    $matieres = $matieresResponse['data'] ?? [];

    foreach ($matieres as $matiere) {
        $matiereId = $matiere['id'];
        $matiereNom = $matiere['nom'] ?? $matiere['libelle'] ?? 'N/A';

        echo "   Matière: {$matiereNom} (ID: {$matiereId})\n";

        $details = $klassciService->get("matieres/{$matiereId}");
        $detailsData = $details['data'] ?? [];

        echo "      Clés disponibles: " . implode(', ', array_keys($detailsData)) . "\n";

        // Vérifier seances_programmees
        if (isset($detailsData['seances_programmees'])) {
            $seances = $detailsData['seances_programmees'];
            echo "      seances_programmees: " . count($seances) . " séances\n";
        } else {
            echo "      ⚠️  Pas de clé 'seances_programmees'\n";
        }

        // Vérifier autres clés potentielles
        $autresCles = ['seances', 'programmation', 'emploi_temps', 'sessions'];
        foreach ($autresCles as $cle) {
            if (isset($detailsData[$cle])) {
                $value = $detailsData[$cle];
                $type = is_array($value) ? 'array[' . count($value) . ']' : gettype($value);
                echo "      {$cle}: {$type}\n";
            }
        }

        echo "\n";
    }

} catch (Exception $e) {
    echo "   ❌ Erreur: " . $e->getMessage() . "\n";
}

echo "========================================\n";
echo "RECOMMANDATIONS:\n";
echo "========================================\n\n";
echo "1. Vérifiez que la séance créée sur KLASSCI est bien\n";
echo "   associée à une matière et une classe.\n\n";
echo "2. Vérifiez que la séance est bien 'programmée' et non\n";
echo "   en brouillon sur KLASSCI.\n\n";
echo "3. Si aucune séance n'apparaît dans 'seances_programmees',\n";
echo "   il y a un problème côté KLASSCI API.\n\n";
