<?php
/**
 * Script d'investigation pour comprendre pourquoi les seances
 * ne sont pas visibles dans le calendrier enseignant
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Models\Seance;
use App\Services\KlassciProxyService;
use Illuminate\Support\Facades\Cache;

echo "============================================\n";
echo "    INVESTIGATION SEANCES ENSEIGNANT\n";
echo "============================================\n\n";

// 1. Trouver l'enseignant BEDE
$bede = User::find(1);
echo "1. ENSEIGNANT BEDE\n";
echo "   - ID: {$bede->id}\n";
echo "   - Nom: {$bede->name}\n";
echo "   - KLASSCI ID: {$bede->klassci_id}\n";
echo "   - Token: " . (strlen($bede->klassci_token) > 20 ? substr($bede->klassci_token, 0, 20) . '...' : 'COURT') . "\n\n";

$klassciService = app(KlassciProxyService::class);

// 2. Appeler le teacher-dashboard
echo "2. APPEL API: me/teacher-dashboard\n";
try {
    // Vider le cache d'abord
    Cache::flush();
    echo "   Cache vide.\n";

    $dashboard = $klassciService->requestWithUserToken(
        $bede->klassci_token,
        'me/teacher-dashboard',
        'GET',
        [],
        true // Force refresh
    );

    echo "   Success: " . ($dashboard['success'] ? 'OUI' : 'NON') . "\n";

    $matieres = $dashboard['data']['matieres'] ?? [];
    echo "   Nombre de matieres: " . count($matieres) . "\n\n";

    if (count($matieres) > 0) {
        echo "3. MATIERES DE L'ENSEIGNANT\n";
        foreach ($matieres as $m) {
            echo "   - ID: " . $m['id'] . " | " . ($m['nom'] ?? $m['libelle'] ?? 'N/A') . "\n";
        }
        echo "\n";

        // 4. Pour chaque matiere, recuperer les seances
        echo "4. SEANCES PAR MATIERE (depuis KLASSCI)\n";
        $totalSeances = 0;

        foreach ($matieres as $matiere) {
            echo "\n   === Matiere: " . ($matiere['nom'] ?? $matiere['libelle'] ?? 'N/A') . " (ID: {$matiere['id']}) ===\n";

            try {
                $matiereDetails = $klassciService->requestWithUserToken(
                    $bede->klassci_token,
                    "matieres/{$matiere['id']}",
                    'GET',
                    [],
                    true
                );

                $seancesProgrammees = $matiereDetails['data']['seances_programmees'] ?? [];
                echo "   Seances programmees: " . count($seancesProgrammees) . "\n";

                foreach ($seancesProgrammees as $seance) {
                    $totalSeances++;
                    echo "\n   [SEANCE #{$seance['id']}]\n";
                    echo "   - Classe: " . ($seance['classe']['nom'] ?? 'N/A') . "\n";

                    // IMPORTANT: Verifier la programmation
                    $prog = $seance['programmation'] ?? [];
                    echo "   - programmation.date: " . ($prog['date'] ?? 'NULL') . "\n";
                    echo "   - programmation.heure_debut: " . ($prog['heure_debut'] ?? 'NULL') . "\n";
                    echo "   - programmation.heure_fin: " . ($prog['heure_fin'] ?? 'NULL') . "\n";
                    echo "   - programmation.salle: " . ($prog['salle'] ?? 'NULL') . "\n";

                    // Verifier si cette seance existe en local
                    $localSeance = Seance::where('klassci_seance_id', $seance['id'])->first();
                    if ($localSeance) {
                        echo "   - LOCAL: OUI (ID: {$localSeance->id}, visio_status: {$localSeance->visio_status})\n";
                    } else {
                        echo "   - LOCAL: NON (pas encore synchronisee)\n";
                    }
                }

            } catch (Exception $e) {
                echo "   ERREUR: " . $e->getMessage() . "\n";
            }
        }

        echo "\n============================================\n";
        echo "TOTAL SEANCES TROUVEES: {$totalSeances}\n";
        echo "============================================\n";

    } else {
        echo "   ATTENTION: Aucune matiere trouvee pour cet enseignant!\n";
    }

} catch (Exception $e) {
    echo "   ERREUR: " . $e->getMessage() . "\n";
}

// 5. Verifier les seances locales
echo "\n5. SEANCES LOCALES (BDD LMS)\n";
$seancesLocales = Seance::where('klassci_enseignant_id', $bede->klassci_id)->get();
echo "   Seances avec klassci_enseignant_id = {$bede->klassci_id}: " . count($seancesLocales) . "\n";

foreach ($seancesLocales as $s) {
    echo "   - Seance locale ID: {$s->id} | klassci_seance_id: {$s->klassci_seance_id} | status: {$s->visio_status}\n";
}

// 6. Verifier TOUTES les seances recentes (pour comparaison avec coordinateur)
echo "\n6. TOUTES LES SEANCES LOCALES RECENTES\n";
$toutesSeances = Seance::orderBy('created_at', 'desc')->limit(10)->get();
echo "   Dernieres 10 seances:\n";
foreach ($toutesSeances as $s) {
    echo "   - ID: {$s->id} | klassci_seance_id: {$s->klassci_seance_id} | matiere: {$s->matiere_nom} | enseignant_id: {$s->klassci_enseignant_id}\n";
}

echo "\n============================================\n";
echo "    FIN INVESTIGATION\n";
echo "============================================\n";
