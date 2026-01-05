<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== TEST D'INTÉGRATION - HISTORIQUE DES SÉANCES ===\n\n";

// Simuler le traitement getSeancesHistory()
echo "1. Simulation de getSeancesHistory():\n";
$start = microtime(true);

$seances = DB::table('seances')
    ->whereNotNull('visio_started_at')
    ->orderBy('visio_started_at', 'desc')
    ->limit(10)
    ->get();

$results = [];
foreach ($seances as $seance) {
    // Statistiques de présence
    $attendances = DB::table('esbtp_attendance')
        ->where('seance_id', $seance->id)
        ->get();

    $participantsCount = $attendances->count();
    $durations = [];

    foreach ($attendances as $att) {
        $duration = $att->duration_minutes;
        if ($duration !== null && $duration > 0) {
            $durations[] = $duration;
        }
    }

    $avgDuration = count($durations) > 0 ? round(array_sum($durations) / count($durations)) : 0;

    $validPresences = 0;
    foreach ($durations as $dur) {
        if ($dur > 3) {
            $validPresences++;
        }
    }

    $presenceRate = $participantsCount > 0
        ? round(($validPresences / $participantsCount) * 100)
        : 0;

    // Durée séance
    $seanceDuration = null;
    if ($seance->visio_started_at && $seance->visio_ended_at) {
        $started = new DateTime($seance->visio_started_at);
        $ended = new DateTime($seance->visio_ended_at);
        $duration = $started->diff($ended)->i + ($started->diff($ended)->h * 60);
        if ($duration <= 1440) {
            $seanceDuration = $duration;
        }
    }

    $results[] = [
        'klassci_seance_id' => $seance->klassci_seance_id,
        'matiere_nom' => $seance->matiere_nom ?? 'Matière inconnue',
        'classe_nom' => $seance->classe_nom ?? '-',
        'titre' => $seance->titre ?? 'Séance #' . $seance->klassci_seance_id,
        'participants_count' => $participantsCount,
        'duree_moyenne_minutes' => $avgDuration,
        'taux_presence' => $presenceRate,
        'duree_seance_minutes' => $seanceDuration,
    ];
}

$end = microtime(true);
$totalTime = round(($end - $start) * 1000, 2);

echo "   ✅ Temps total de traitement: {$totalTime}ms\n";
echo "   📊 Séances traitées: " . count($results) . "\n\n";

// Afficher les résultats
echo "2. Résultats détaillés:\n";
foreach (array_slice($results, 0, 5) as $result) {
    echo "   Séance #{$result['klassci_seance_id']}:\n";
    echo "     Matière: {$result['matiere_nom']}\n";
    echo "     Classe: {$result['classe_nom']}\n";
    echo "     Titre: {$result['titre']}\n";
    echo "     Participants: {$result['participants_count']}\n";
    echo "     Durée moy.: {$result['duree_moyenne_minutes']} min\n";
    echo "     Taux: {$result['taux_presence']}%\n\n";
}

// Vérifier la présence de toutes les données
echo "3. Validation des données:\n";
$matieresOK = 0;
$classesOK = 0;
$titresOK = 0;

foreach ($results as $r) {
    if ($r['matiere_nom'] !== 'Matière inconnue') $matieresOK++;
    if ($r['classe_nom'] !== '-') $classesOK++;
    if (!str_starts_with($r['titre'], 'Séance #')) $titresOK++;
}

$total = count($results);
echo "   Matières renseignées: $matieresOK/$total (" . round($matieresOK/$total*100) . "%)\n";
echo "   Classes renseignées: $classesOK/$total (" . round($classesOK/$total*100) . "%)\n";
echo "   Titres renseignés: $titresOK/$total (" . round($titresOK/$total*100) . "%)\n\n";

// Performance
echo "4. Performance:\n";
if ($totalTime < 200) {
    echo "   ✅ EXCELLENT: < 200ms (objectif atteint)\n";
} elseif ($totalTime < 1000) {
    echo "   ✅ BON: < 1s (acceptable)\n";
} else {
    echo "   ⚠️  LENT: > 1s (besoin d'optimisation)\n";
}

echo "\n✅ Test d'intégration terminé\n";
