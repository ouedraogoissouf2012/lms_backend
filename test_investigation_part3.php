<?php
/**
 * Investigation Part 3 - Verifier l'autre BEDE et comprendre le flux
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Services\KlassciProxyService;

echo "============================================\n";
echo "    INVESTIGATION PART 3\n";
echo "============================================\n\n";

$klassciService = app(KlassciProxyService::class);

// 1. Verifier BEDE ID 2
echo "1. UTILISATEUR BEDE ID 2\n";
$bede2 = User::find(2);
if ($bede2) {
    echo "   - ID: {$bede2->id}\n";
    echo "   - Nom: {$bede2->name}\n";
    echo "   - Email: {$bede2->email}\n";
    echo "   - klassci_id: {$bede2->klassci_id}\n";
    echo "   - Role LMS: {$bede2->role}\n";
    echo "   - Token existe: " . ($bede2->klassci_token ? 'OUI' : 'NON') . "\n";

    if ($bede2->klassci_token) {
        echo "\n2. TEST TEACHER-DASHBOARD POUR BEDE ID 2\n";
        try {
            $dashboard = $klassciService->requestWithUserToken(
                $bede2->klassci_token,
                'me/teacher-dashboard',
                'GET',
                [],
                true
            );

            echo "   Success: " . ($dashboard['success'] ? 'OUI' : 'NON') . "\n";

            // Verifier le user_context
            $meta = $dashboard['meta'] ?? [];
            $userContext = $meta['user_context'] ?? [];
            echo "   user_context.role: " . ($userContext['role'] ?? 'N/A') . "\n";
            echo "   user_context.is_enseignant: " . ($userContext['is_enseignant'] ?? 'N/A') . "\n";

            $matieres = $dashboard['data']['matieres'] ?? [];
            echo "   Matieres: " . count($matieres) . "\n\n";

            foreach ($matieres as $m) {
                echo "   - " . ($m['nom'] ?? $m['libelle'] ?? 'N/A') . " (ID: {$m['id']})\n";
            }

            // Recuperer les seances
            if (count($matieres) > 0) {
                echo "\n3. SEANCES POUR BEDE ID 2\n";

                foreach ($matieres as $matiere) {
                    $matiereDetails = $klassciService->requestWithUserToken(
                        $bede2->klassci_token,
                        "matieres/{$matiere['id']}",
                        'GET',
                        [],
                        true
                    );

                    $seances = $matiereDetails['data']['seances_programmees'] ?? [];

                    if (count($seances) > 0) {
                        echo "\n   Matiere: " . ($matiere['nom'] ?? 'N/A') . "\n";
                        foreach ($seances as $s) {
                            $prog = $s['programmation'] ?? [];
                            echo "   - Seance #{$s['id']} | Date: " . ($prog['date'] ?? 'NULL') . " | Heure: " . ($prog['heure_debut'] ?? 'NULL') . "\n";
                        }
                    }
                }
            }

        } catch (Exception $e) {
            echo "   ERREUR: " . $e->getMessage() . "\n";

            // Parser l'erreur pour voir le contexte
            if (strpos($e->getMessage(), 'user_context') !== false) {
                preg_match('/user_context.*?}/', $e->getMessage(), $matches);
                if ($matches) {
                    echo "   Context: " . $matches[0] . "\n";
                }
            }
        }
    }
} else {
    echo "   BEDE ID 2 non trouve\n";
}

// 4. Lister TOUS les enseignants et leur statut KLASSCI
echo "\n4. TOUS LES ENSEIGNANTS ET LEUR STATUT KLASSCI\n";
$allEnseignants = User::whereIn('role', ['enseignant', 'teacher'])->get();

foreach ($allEnseignants as $ens) {
    echo "\n   [{$ens->name}] (LMS ID: {$ens->id}, klassci_id: {$ens->klassci_id})\n";

    if ($ens->klassci_token) {
        try {
            $dashboard = $klassciService->requestWithUserToken(
                $ens->klassci_token,
                'me/teacher-dashboard',
                'GET',
                [],
                true
            );

            $matieres = $dashboard['data']['matieres'] ?? [];
            echo "   -> KLASSCI OK | Matieres: " . count($matieres) . "\n";

        } catch (Exception $e) {
            // Extraire le role KLASSCI de l'erreur
            if (preg_match('/"role":"([^"]+)"/', $e->getMessage(), $m)) {
                echo "   -> KLASSCI ROLE: " . $m[1] . " (PAS ENSEIGNANT)\n";
            } else {
                echo "   -> ERREUR: " . substr($e->getMessage(), 0, 100) . "...\n";
            }
        }
    } else {
        echo "   -> PAS DE TOKEN\n";
    }
}

echo "\n============================================\n";
echo "    FIN INVESTIGATION PART 3\n";
echo "============================================\n";
