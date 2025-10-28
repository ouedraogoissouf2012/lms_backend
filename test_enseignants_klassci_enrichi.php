<?php

/**
 * Script de test pour l'endpoint /api/lms/enseignants enrichi depuis KLASSCI externe
 */

require __DIR__ . '/vendor/autoload.php';

use App\Services\KlassciProxyService;
use App\Models\LmsEnseignantCache;
use Illuminate\Support\Facades\DB;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "========================================\n";
echo "TEST: Enseignants enrichis depuis KLASSCI\n";
echo "========================================\n\n";

try {
    // 1. Tester le service KLASSCI
    echo "[1] Test du service KlassciProxyService...\n";
    $service = app(KlassciProxyService::class);

    echo "[2] Appel KLASSCI avec with_details=true...\n";
    $response = $service->getEnseignantsEnrichis(true);

    echo "[3] Réponse reçue:\n";
    echo "    - success: " . ($response['success'] ? 'true' : 'false') . "\n";
    echo "    - nombre: " . count($response['data'] ?? []) . " enseignants\n\n";

    if (!empty($response['data'])) {
        $firstEnseignant = $response['data'][0];
        echo "[4] Premier enseignant (échantillon):\n";
        echo "    - ID: " . $firstEnseignant['id'] . "\n";
        echo "    - Nom: " . $firstEnseignant['nom'] . "\n";
        echo "    - Email: " . $firstEnseignant['email'] . "\n";

        if (isset($firstEnseignant['matieres'])) {
            echo "    - Matières: " . count($firstEnseignant['matieres']) . "\n";
            if (!empty($firstEnseignant['matieres'])) {
                $firstMatiere = $firstEnseignant['matieres'][0];
                echo "      * " . $firstMatiere['nom'] . " (" . $firstMatiere['code'] . ")\n";
                echo "        - Heures: " . $firstMatiere['heures_effectuees'] . " / " . $firstMatiere['heures_prevues'] . "\n";
                echo "        - Taux: " . $firstMatiere['taux_realisation'] . "%\n";

                if (isset($firstMatiere['classes'])) {
                    echo "        - Classes: " . count($firstMatiere['classes']) . "\n";
                    if (!empty($firstMatiere['classes'])) {
                        $firstClasse = $firstMatiere['classes'][0];
                        echo "          * " . $firstClasse['nom'] . " (" . $firstClasse['filiere'] . " - " . $firstClasse['niveau'] . ")\n";
                    }
                }
            }
        }

        if (isset($firstEnseignant['statistiques'])) {
            echo "    - Statistiques:\n";
            $stats = $firstEnseignant['statistiques'];
            echo "      * Classes: " . $stats['total_classes'] . "\n";
            echo "      * Matières: " . $stats['total_matieres'] . "\n";
            echo "      * Heures: " . $stats['total_heures_effectuees'] . " / " . $stats['total_heures_prevues'] . "\n";
            echo "      * Taux réalisation: " . $stats['taux_realisation_global'] . "%\n";
        }

        echo "\n[5] Test du cache...\n";
        // Stocker en cache
        LmsEnseignantCache::store($firstEnseignant['id'], $firstEnseignant, 10);
        echo "    - Stocké en cache: enseignant ID " . $firstEnseignant['id'] . "\n";

        // Récupérer depuis le cache
        $cached = LmsEnseignantCache::getValid($firstEnseignant['id']);
        if ($cached) {
            echo "    - Récupéré du cache: " . $cached->data['nom'] . "\n";
            echo "    - Expire à: " . $cached->expires_at->format('Y-m-d H:i:s') . "\n";
        } else {
            echo "    - Erreur: Cache non trouvé ou expiré\n";
        }
    }

    echo "\n[6] Résumé complet:\n";
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

    echo "\n✅ TEST RÉUSSI !\n\n";

} catch (Exception $e) {
    echo "\n❌ ERREUR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
