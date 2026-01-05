<?php
/**
 * Investigation Part 2 - Comprendre la seance recemment creee
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Models\Seance;
use App\Services\KlassciProxyService;

echo "============================================\n";
echo "    INVESTIGATION PART 2\n";
echo "============================================\n\n";

$klassciService = app(KlassciProxyService::class);

// 1. Verifier tous les utilisateurs enseignants dans le LMS
echo "1. ENSEIGNANTS DANS LE LMS\n";
$enseignants = User::whereIn('role', ['enseignant', 'teacher'])->get();
foreach ($enseignants as $e) {
    echo "   - ID: {$e->id} | Nom: {$e->name} | klassci_id: {$e->klassci_id} | Role LMS: {$e->role}\n";
}

// 2. La seance recente (ID 40, klassci_seance_id 75)
echo "\n2. SEANCE RECENTE (la derniere creee)\n";
$seanceRecente = Seance::orderBy('created_at', 'desc')->first();
if ($seanceRecente) {
    echo "   - ID local: {$seanceRecente->id}\n";
    echo "   - klassci_seance_id: {$seanceRecente->klassci_seance_id}\n";
    echo "   - klassci_matiere_id: {$seanceRecente->klassci_matiere_id}\n";
    echo "   - klassci_classe_id: {$seanceRecente->klassci_classe_id}\n";
    echo "   - klassci_enseignant_id: " . ($seanceRecente->klassci_enseignant_id ?: 'NULL') . "\n";
    echo "   - matiere_nom: {$seanceRecente->matiere_nom}\n";
    echo "   - enseignant_nom: " . ($seanceRecente->enseignant_nom ?: 'NULL') . "\n";
    echo "   - visio_status: {$seanceRecente->visio_status}\n";
    echo "   - created_at: {$seanceRecente->created_at}\n";
}

// 3. Trouver un vrai enseignant avec token valide
echo "\n3. CHERCHER UN ENSEIGNANT AVEC TOKEN VALIDE\n";
$enseignantAvecToken = User::whereIn('role', ['enseignant', 'teacher'])
    ->whereNotNull('klassci_token')
    ->where('klassci_token', '!=', '')
    ->first();

if ($enseignantAvecToken) {
    echo "   Trouvé: {$enseignantAvecToken->name} (ID: {$enseignantAvecToken->id}, klassci_id: {$enseignantAvecToken->klassci_id})\n";

    // Tester son dashboard
    echo "\n4. TEST TEACHER-DASHBOARD POUR CET ENSEIGNANT\n";
    try {
        $dashboard = $klassciService->requestWithUserToken(
            $enseignantAvecToken->klassci_token,
            'me/teacher-dashboard',
            'GET',
            [],
            true
        );

        echo "   Success: " . ($dashboard['success'] ? 'OUI' : 'NON') . "\n";
        $matieres = $dashboard['data']['matieres'] ?? [];
        echo "   Matieres: " . count($matieres) . "\n";

        foreach ($matieres as $m) {
            echo "   - " . ($m['nom'] ?? $m['libelle'] ?? 'N/A') . " (ID: {$m['id']})\n";
        }

        // Recuperer les seances de la premiere matiere
        if (count($matieres) > 0) {
            echo "\n5. SEANCES DE LA PREMIERE MATIERE\n";
            $premiereMatiere = $matieres[0];

            $matiereDetails = $klassciService->requestWithUserToken(
                $enseignantAvecToken->klassci_token,
                "matieres/{$premiereMatiere['id']}",
                'GET',
                [],
                true
            );

            $seances = $matiereDetails['data']['seances_programmees'] ?? [];
            echo "   Seances trouvees: " . count($seances) . "\n\n";

            foreach ($seances as $s) {
                echo "   [SEANCE {$s['id']}]\n";
                echo "   - Classe: " . ($s['classe']['nom'] ?? 'N/A') . "\n";
                $prog = $s['programmation'] ?? [];
                echo "   - Date: " . ($prog['date'] ?? 'NULL') . "\n";
                echo "   - Heure debut: " . ($prog['heure_debut'] ?? 'NULL') . "\n";
                echo "   - Heure fin: " . ($prog['heure_fin'] ?? 'NULL') . "\n";
                echo "\n";
            }
        }

    } catch (Exception $e) {
        echo "   ERREUR: " . $e->getMessage() . "\n";
    }
} else {
    echo "   Aucun enseignant avec token trouve!\n";
}

// 5. Comparer avec ce que voit le coordinateur (endpoint matieres)
echo "\n6. CE QUE VOIT LE COORDINATEUR (via endpoint matieres)\n";
$admin = User::where('role', 'admin')->orWhere('role', 'coordinateur')->first();
if ($admin && $admin->klassci_token) {
    try {
        $matieresResponse = $klassciService->requestWithUserToken(
            $admin->klassci_token,
            'matieres',
            'GET',
            [],
            true
        );

        $allMatieres = $matieresResponse['data'] ?? [];
        echo "   Total matieres dans KLASSCI: " . count($allMatieres) . "\n";

        // Chercher la matiere "Algorithme" (celle de la seance recente)
        $algorithme = collect($allMatieres)->first(function($m) {
            return stripos($m['nom'] ?? '', 'Algorithme') !== false;
        });

        if ($algorithme) {
            echo "\n   MATIERE ALGORITHME (ID: {$algorithme['id']})\n";

            $matiereDetails = $klassciService->requestWithUserToken(
                $admin->klassci_token,
                "matieres/{$algorithme['id']}",
                'GET',
                [],
                true
            );

            $seancesAlgo = $matiereDetails['data']['seances_programmees'] ?? [];
            echo "   Seances programmees: " . count($seancesAlgo) . "\n\n";

            foreach ($seancesAlgo as $s) {
                echo "   [SEANCE {$s['id']}]\n";
                echo "   - Classe: " . ($s['classe']['nom'] ?? 'N/A') . " (ID: " . ($s['classe']['id'] ?? 'N/A') . ")\n";
                $prog = $s['programmation'] ?? [];
                echo "   - Date: " . ($prog['date'] ?? 'NULL') . "\n";
                echo "   - Heure debut: " . ($prog['heure_debut'] ?? 'NULL') . "\n";

                // C'est la seance recente?
                if ($s['id'] == 75) {
                    echo "   >>> C'EST LA SEANCE RECENTE! <<<\n";
                }
                echo "\n";
            }
        }

    } catch (Exception $e) {
        echo "   ERREUR: " . $e->getMessage() . "\n";
    }
}

echo "============================================\n";
echo "    FIN INVESTIGATION PART 2\n";
echo "============================================\n";
