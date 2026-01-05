<?php

/**
 * Script de test pour l'historique des séances vidéo
 *
 * Teste:
 * - GET /lms/attendance/history (historique des présences)
 * - GET /lms/seances/history (historique des séances)
 * - GET /lms/seances/{id}/attendances (présences d'une séance)
 */

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use App\Models\User;
use Illuminate\Support\Facades\Log;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "\n========================================\n";
echo "TEST HISTORIQUE DES SÉANCES VIDÉO\n";
echo "========================================\n\n";

// 1. Trouver un enseignant avec des séances
echo "[1] Recherche d'un enseignant avec séances...\n";
$enseignant = User::where('role', 'enseignant')
    ->whereNotNull('klassci_id')
    ->first();

if (!$enseignant) {
    echo "❌ Aucun enseignant trouvé\n";
    exit(1);
}

echo "✅ Enseignant trouvé: {$enseignant->name} (ID: {$enseignant->id}, KLASSCI: {$enseignant->klassci_id})\n\n";

// 2. Trouver un étudiant avec des présences
echo "[2] Recherche d'un étudiant avec présences...\n";
$etudiant = User::where('role', 'etudiant')
    ->whereNotNull('klassci_id')
    ->first();

if (!$etudiant) {
    echo "❌ Aucun étudiant trouvé\n";
    exit(1);
}

echo "✅ Étudiant trouvé: {$etudiant->name} (ID: {$etudiant->id}, KLASSCI: {$etudiant->klassci_id})\n\n";

// 3. Test de l'endpoint d'historique des présences
echo "[3] Test GET /lms/attendance/history (étudiant)...\n";
try {
    // Utiliser la résolution de dépendances de Laravel
    $controller = app(App\Http\Controllers\API\LMSDataController::class);

    // Créer une requête simulée pour l'étudiant
    $request = new Illuminate\Http\Request();
    $request->setUserResolver(function () use ($etudiant) {
        return $etudiant;
    });

    $response = $controller->getAttendanceHistory($request);
    $data = $response->getData(true);

    if ($data['success']) {
        echo "✅ Succès!\n";
        $history = $data['data'] ?? [];
        echo "   Total présences: " . count($history) . "\n";

        if (count($history) > 0) {
            $first = $history[0];
            echo "   Exemple (première): \n";
            echo "     - Seance ID: " . ($first['seance_id'] ?? 'N/A') . "\n";
            echo "     - Date: " . ($first['date'] ?? 'N/A') . "\n";
            echo "     - Status: " . ($first['status'] ?? 'N/A') . "\n";
            echo "     - Temps présence: " . ($first['temps_presence'] ?? 0) . " min\n";
        }
    } else {
        echo "❌ Erreur: " . ($data['message'] ?? 'Erreur inconnue') . "\n";
    }
} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
}

echo "\n";

// 4. Test de l'endpoint d'historique des séances
echo "[4] Test GET /lms/seances/history (enseignant)...\n";
try {
    // Utiliser la résolution de dépendances de Laravel
    $controller = app(App\Http\Controllers\API\LMSDataController::class);

    // Créer une requête simulée pour l'enseignant
    $request = new Illuminate\Http\Request();
    $request->setUserResolver(function () use ($enseignant) {
        return $enseignant;
    });

    $response = $controller->getSeancesHistory($request);
    $data = $response->getData(true);

    if ($data['success']) {
        echo "✅ Succès!\n";
        $history = $data['data'] ?? [];
        echo "   Total séances: " . count($history) . "\n";

        if (count($history) > 0) {
            $first = $history[0];
            echo "   Exemple (première): \n";
            echo "     - ID: " . ($first['id'] ?? 'N/A') . "\n";
            echo "     - Titre: " . ($first['titre'] ?? 'N/A') . "\n";
            echo "     - Date: " . ($first['date_heure'] ?? 'N/A') . "\n";
            echo "     - Status: " . ($first['status'] ?? 'N/A') . "\n";
            echo "     - Classe: " . ($first['classe_nom'] ?? 'N/A') . "\n";
            echo "     - Matière: " . ($first['matiere_nom'] ?? 'N/A') . "\n";
        }
    } else {
        echo "❌ Erreur: " . ($data['message'] ?? 'Erreur inconnue') . "\n";
    }
} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
}

echo "\n";

// 5. Test de l'endpoint des présences d'une séance
echo "[5] Test GET /lms/seances/{id}/attendances...\n";
$seance = App\Models\Seance::where('status', 'terminee')
    ->orderBy('date_heure', 'desc')
    ->first();

if ($seance) {
    echo "   Séance trouvée: ID={$seance->id}, Titre={$seance->titre}\n";

    try {
        // Utiliser la résolution de dépendances de Laravel
        $controller = app(App\Http\Controllers\API\LMSDataController::class);

        // Créer une requête simulée
        $request = new Illuminate\Http\Request();
        $request->setUserResolver(function () use ($enseignant) {
            return $enseignant;
        });

        $response = $controller->getSeanceAttendances($request, $seance->id);
        $data = $response->getData(true);

        if ($data['success']) {
            echo "✅ Succès!\n";
            $attendances = $data['data'] ?? [];
            echo "   Total présences: " . count($attendances) . "\n";

            if (count($attendances) > 0) {
                $first = $attendances[0];
                echo "   Exemple (première): \n";
                echo "     - User ID: " . ($first['user_id'] ?? 'N/A') . "\n";
                echo "     - Nom: " . ($first['user_name'] ?? 'N/A') . "\n";
                echo "     - Status: " . ($first['status'] ?? 'N/A') . "\n";
                echo "     - Temps présence: " . ($first['temps_presence'] ?? 0) . " min\n";
            }
        } else {
            echo "❌ Erreur: " . ($data['message'] ?? 'Erreur inconnue') . "\n";
        }
    } catch (Exception $e) {
        echo "❌ Exception: " . $e->getMessage() . "\n";
    }
} else {
    echo "⚠️  Aucune séance terminée trouvée pour tester\n";
}

echo "\n========================================\n";
echo "✅ TESTS TERMINÉS\n";
echo "========================================\n\n";
