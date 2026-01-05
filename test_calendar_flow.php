<?php
/**
 * Investigation Calendrier - Simuler exactement ce que fait UniversalCalendar
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Models\Seance;
use App\Services\KlassciProxyService;
use Illuminate\Support\Facades\Cache;

echo "============================================\n";
echo "    SIMULATION FLUX CALENDRIER\n";
echo "============================================\n\n";

Cache::flush();

$klassciService = app(KlassciProxyService::class);
$bede = User::find(2); // bede@gmail.com

echo "Utilisateur: {$bede->email} (klassci_id: {$bede->klassci_id})\n\n";

// Simuler exactement ce que fait LMSDataController::myTeachingSeances()
echo "=== SIMULATION myTeachingSeances() ===\n\n";

try {
    // Etape 1: teacher-dashboard
    $dashboard = $klassciService->requestWithUserToken(
        $bede->klassci_token,
        'me/teacher-dashboard',
        'GET',
        [],
        true
    );

    $matieres = $dashboard['data']['matieres'] ?? [];
    echo "1. Matieres de l'enseignant: " . count($matieres) . "\n";

    $seancesRetournees = [];

    // Etape 2: Pour chaque matiere, recuperer seances
    foreach ($matieres as $matiere) {
        $matiereDetails = $klassciService->requestWithUserToken(
            $bede->klassci_token,
            "matieres/{$matiere['id']}",
            'GET',
            [],
            true
        );

        $seancesProgrammees = $matiereDetails['data']['seances_programmees'] ?? [];
        echo "\n2. Matiere '{$matiere['nom']}': " . count($seancesProgrammees) . " seance(s)\n";

        foreach ($seancesProgrammees as $seance) {
            // Simuler la transformation du backend (LMSDataController lignes 2338-2398)
            $visioData = Seance::where('klassci_seance_id', $seance['id'])->first();

            $seanceTransformee = [
                'id' => $seance['id'],
                'date_seance' => $seance['programmation']['date'] ?? null,
                'heure_debut' => isset($seance['programmation']['heure_debut'])
                    ? substr($seance['programmation']['heure_debut'], 11, 5)
                    : null,
                'heure_fin' => isset($seance['programmation']['heure_fin'])
                    ? substr($seance['programmation']['heure_fin'], 11, 5)
                    : null,
                'programmation' => [
                    'date' => $seance['programmation']['date'] ?? null,
                    'heure_debut' => $seance['programmation']['heure_debut'] ?? null,
                    'heure_fin' => $seance['programmation']['heure_fin'] ?? null,
                    'salle' => $seance['programmation']['salle'] ?? null
                ],
                'matiere' => [
                    'id' => $matiere['id'],
                    'nom' => $matiere['nom'] ?? $matiere['libelle'] ?? 'N/A',
                ],
                'classe' => [
                    'id' => $seance['classe']['id'] ?? null,
                    'nom' => $seance['classe']['nom'] ?? 'N/A',
                ],
                'visio' => $visioData ? [
                    'status' => $visioData->visio_status,
                    'room_id' => $visioData->visio_room_id,
                ] : null,
            ];

            $seancesRetournees[] = $seanceTransformee;

            echo "\n   [SEANCE #{$seance['id']}] - Backend retourne:\n";
            echo "   - date_seance: " . ($seanceTransformee['date_seance'] ?? 'NULL') . "\n";
            echo "   - programmation.heure_debut: " . ($seanceTransformee['programmation']['heure_debut'] ?? 'NULL') . "\n";
            echo "   - programmation.heure_fin: " . ($seanceTransformee['programmation']['heure_fin'] ?? 'NULL') . "\n";
        }
    }

    echo "\n\n=== SIMULATION FRONTEND (UniversalCalendar.vue) ===\n";
    echo "Seances recues du backend: " . count($seancesRetournees) . "\n\n";

    // Simuler la transformation frontend (UniversalCalendar.vue lignes 383-398)
    echo "3. Transformation pour FullCalendar:\n\n";

    foreach ($seancesRetournees as $seance) {
        // Code exact du frontend:
        // start: seance.programmation?.heure_debut || seance.date_seance
        $start = $seance['programmation']['heure_debut'] ?? $seance['date_seance'] ?? null;
        $end = $seance['programmation']['heure_fin'] ?? null;
        $title = ($seance['matiere']['nom'] ?? 'Cours') . ' - ' . ($seance['classe']['nom'] ?? '');

        echo "   Seance #{$seance['id']}:\n";
        echo "   - title: '$title'\n";
        echo "   - start: " . ($start ?? 'NULL') . "\n";
        echo "   - end: " . ($end ?? 'NULL') . "\n";

        // Verifier si la date est valide pour FullCalendar
        if ($start) {
            $timestamp = strtotime($start);
            if ($timestamp !== false) {
                $dateFormatee = date('Y-m-d H:i:s', $timestamp);
                echo "   - Date parsee: $dateFormatee (VALIDE pour FullCalendar)\n";

                // Verifier si c'est dans le futur ou le passe
                $now = time();
                if ($timestamp > $now) {
                    echo "   - Status: FUTUR (devrait s'afficher)\n";
                } else {
                    echo "   - Status: PASSE (pourrait etre filtre)\n";
                }
            } else {
                echo "   - ERREUR: Date invalide!\n";
            }
        } else {
            echo "   - ERREUR: Pas de date start!\n";
        }
        echo "\n";
    }

    // Verifier le filtre de date du calendrier
    echo "=== VERIFICATION FILTRES CALENDRIER ===\n\n";
    echo "Le calendrier a un filtre dateRangePreset = '30days' par defaut\n";
    echo "Date actuelle: " . date('Y-m-d H:i:s') . "\n";
    echo "Date de la seance #75: 2026-01-02 12:30:00\n";

    $seanceDate = strtotime('2026-01-02 12:30:00');
    $now = time();
    $diff = ($seanceDate - $now) / 86400;
    echo "Difference: " . round($diff, 1) . " jours\n";

    if ($diff >= 0 && $diff <= 30) {
        echo "-> Dans les 30 prochains jours: OUI\n";
    } else if ($diff < 0) {
        echo "-> PROBLEME: La seance est dans le PASSE!\n";
    } else {
        echo "-> PROBLEME: La seance est dans plus de 30 jours!\n";
    }

} catch (Exception $e) {
    echo "ERREUR: " . $e->getMessage() . "\n";
}

echo "\n============================================\n";
echo "    FIN SIMULATION\n";
echo "============================================\n";
