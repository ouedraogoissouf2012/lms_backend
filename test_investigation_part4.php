<?php
/**
 * Investigation Part 4 - Pourquoi la seance n'apparait pas malgre le bon compte
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Models\Seance;
use App\Services\KlassciProxyService;
use Illuminate\Support\Facades\Cache;

echo "============================================\n";
echo "    INVESTIGATION PART 4 - APPROFONDIE\n";
echo "============================================\n\n";

// Vider le cache
Cache::flush();
echo "Cache vide.\n\n";

$klassciService = app(KlassciProxyService::class);

// BEDE ID 2 - le bon compte (bede@gmail.com)
$bede = User::find(2);
echo "1. COMPTE: {$bede->name} ({$bede->email})\n";
echo "   klassci_id: {$bede->klassci_id}\n\n";

// Simuler exactement ce que fait getMyTeachingSeances
echo "2. SIMULATION DE getMyTeachingSeances()\n";
echo "   -----------------------------------------------\n\n";

try {
    // Etape 1: Appel teacher-dashboard
    echo "   ETAPE 1: Appel me/teacher-dashboard\n";
    $dashboard = $klassciService->requestWithUserToken(
        $bede->klassci_token,
        'me/teacher-dashboard',
        'GET',
        [],
        true
    );

    $matieres = $dashboard['data']['matieres'] ?? [];
    echo "   -> Matieres recues: " . count($matieres) . "\n";

    foreach ($matieres as $m) {
        echo "      - {$m['nom']} (ID: {$m['id']})\n";
    }

    // Etape 2: Pour chaque matiere, recuperer les seances
    echo "\n   ETAPE 2: Recuperation seances par matiere\n";

    $allSeances = [];

    foreach ($matieres as $matiere) {
        echo "\n   -> Matiere: {$matiere['nom']} (ID: {$matiere['id']})\n";

        $matiereDetails = $klassciService->requestWithUserToken(
            $bede->klassci_token,
            "matieres/{$matiere['id']}",
            'GET',
            [],
            true
        );

        $seancesProgrammees = $matiereDetails['data']['seances_programmees'] ?? [];
        echo "      Seances programmees: " . count($seancesProgrammees) . "\n";

        foreach ($seancesProgrammees as $seance) {
            echo "\n      [SEANCE KLASSCI #{$seance['id']}]\n";

            // Afficher TOUTE la structure de programmation
            $prog = $seance['programmation'] ?? [];
            echo "      programmation = " . json_encode($prog, JSON_PRETTY_PRINT) . "\n";

            echo "      classe = " . json_encode($seance['classe'] ?? [], JSON_PRETTY_PRINT) . "\n";

            // Verifier ce que le frontend recevrait
            $dateSeance = $prog['date'] ?? null;
            $heureDebut = $prog['heure_debut'] ?? null;
            $heureFin = $prog['heure_fin'] ?? null;

            echo "\n      POUR LE FRONTEND:\n";
            echo "      - date_seance: " . ($dateSeance ?: 'NULL') . "\n";
            echo "      - programmation.heure_debut: " . ($heureDebut ?: 'NULL') . "\n";
            echo "      - programmation.heure_fin: " . ($heureFin ?: 'NULL') . "\n";

            // Le frontend utilise: start: seance.programmation?.heure_debut || seance.date_seance
            $startValue = $heureDebut ?: $dateSeance;
            echo "      - VALEUR START CALENDRIER: " . ($startValue ?: 'NULL !!!') . "\n";

            if (!$startValue) {
                echo "      >>> PROBLEME: Pas de date valide pour le calendrier!\n";
            }

            $allSeances[] = $seance;
        }
    }

    echo "\n   -----------------------------------------------\n";
    echo "   TOTAL SEANCES TROUVEES: " . count($allSeances) . "\n";

    // Etape 3: Verifier la transformation pour FullCalendar
    echo "\n3. TRANSFORMATION POUR FULLCALENDAR\n";
    echo "   -----------------------------------------------\n";

    foreach ($allSeances as $seance) {
        $matiere = collect($matieres)->firstWhere('id', $seance['matiere_id'] ?? null) ?? ['nom' => 'N/A'];

        $prog = $seance['programmation'] ?? [];
        $start = $prog['heure_debut'] ?? $prog['date'] ?? null;
        $end = $prog['heure_fin'] ?? null;

        echo "\n   Seance #{$seance['id']}:\n";
        echo "   - title: " . ($matiere['nom'] ?? 'Cours') . " - " . ($seance['classe']['nom'] ?? '') . "\n";
        echo "   - start: " . ($start ?: 'NULL') . "\n";
        echo "   - end: " . ($end ?: 'NULL') . "\n";

        // Verifier si c'est un format de date valide
        if ($start) {
            $parsedDate = strtotime($start);
            if ($parsedDate === false) {
                echo "   >>> ERREUR: '$start' n'est pas une date valide!\n";
            } else {
                echo "   - Date parsee: " . date('Y-m-d H:i:s', $parsedDate) . " (VALIDE)\n";
            }
        }
    }

} catch (Exception $e) {
    echo "   ERREUR: " . $e->getMessage() . "\n";
}

// 4. Verifier le localStorage/cache cote serveur
echo "\n4. SEANCES DANS LA BDD LOCALE\n";
echo "   -----------------------------------------------\n";

$seancesLocales = Seance::where('klassci_enseignant_id', $bede->klassci_id)
    ->orWhere('klassci_enseignant_id', null)
    ->orderBy('id', 'desc')
    ->limit(10)
    ->get();

foreach ($seancesLocales as $s) {
    echo "   - ID: {$s->id} | klassci_seance_id: {$s->klassci_seance_id} | enseignant_id: " . ($s->klassci_enseignant_id ?: 'NULL') . " | matiere: {$s->matiere_nom}\n";
}

echo "\n============================================\n";
echo "    FIN INVESTIGATION PART 4\n";
echo "============================================\n";
