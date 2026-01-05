<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "═══════════════════════════════════════════════════════════════════\n";
echo "   VÉRIFICATION FINALE - VUE UTILISATEUR\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// Simuler ce que l'utilisateur voit dans la page d'historique
echo "📋 HISTORIQUE DES SÉANCES (comme dans le tableau principal):\n";
echo str_repeat('─', 120) . "\n";
printf("%-10s %-25s %-20s %-15s %-15s %-15s\n",
    "Séance", "Matière", "Date", "Participants", "Taux", "Durée moy.");
echo str_repeat('─', 120) . "\n";

$seances = DB::table('seances')
    ->whereNotNull('visio_started_at')
    ->orderBy('klassci_seance_id', 'desc')
    ->limit(5)
    ->get();

foreach ($seances as $seance) {
    // Calculer les stats (même logique que le contrôleur)
    $attendances = \App\Models\ESBTPAttendance::where('seance_id', $seance->id)
        ->where(function($query) {
            $query->where('is_observer', false)
                  ->orWhereNull('is_observer');
        })
        ->where(function($query) {
            $query->where('duration_minutes', '>', 0)
                  ->orWhereNull('duration_minutes');
        })
        ->get();

    $totalParticipants = $attendances->count();
    $validDurations = $attendances->filter(fn($a) => $a->duration_minutes !== null && $a->duration_minutes > 0);
    $avgDuration = $validDurations->count() > 0 ? round($validDurations->avg('duration_minutes')) : 0;
    $validPresences = $attendances->filter(fn($a) => $a->duration_minutes !== null && $a->duration_minutes > 3)->count();
    $presenceRate = $totalParticipants > 0 ? round(($validPresences / $totalParticipants) * 100) : 0;

    $date = date('Y-m-d H:i', strtotime($seance->visio_started_at));

    printf("%-10s %-25s %-20s %-15s %-15s %-15s\n",
        "#" . $seance->klassci_seance_id,
        substr($seance->matiere_nom, 0, 25),
        $date,
        $totalParticipants,
        $presenceRate . "%",
        $avgDuration . " min"
    );
}

echo str_repeat('─', 120) . "\n\n";

// Simuler le clic sur "Voir" pour la séance #60
echo "👁️  MODAL - DÉTAILS SÉANCE #60 (après clic sur 'Voir'):\n";
echo str_repeat('─', 120) . "\n";

$seance60 = DB::table('seances')->where('klassci_seance_id', 60)->first();
$attendances60 = \App\Models\ESBTPAttendance::where('seance_id', $seance60->id)
    ->where(function($query) {
        $query->where('is_observer', false)
              ->orWhereNull('is_observer');
    })
    ->where(function($query) {
        $query->where('duration_minutes', '>', 0)
              ->orWhereNull('duration_minutes');
    })
    ->orderBy('joined_at', 'desc')
    ->get();

printf("%-20s %-15s %-35s %-12s %-12s %-12s %-12s\n",
    "Nom", "Prénom", "Email", "Arrivée", "Départ", "Durée", "Statut");
echo str_repeat('─', 120) . "\n";

foreach ($attendances60 as $att) {
    $isPresent = $att->duration_minutes !== null && $att->duration_minutes > 3;
    $joinedTime = date('H:i', strtotime($att->joined_at));
    $leftTime = $att->left_at ? date('H:i', strtotime($att->left_at)) : '-';

    // Formater la durée comme le frontend
    $duration = $att->duration_minutes;
    if ($duration && $duration > 0) {
        $hours = floor($duration / 60);
        $mins = round($duration % 60);
        if ($hours > 0) {
            $durationStr = $mins > 0 ? "{$hours}h {$mins}min" : "{$hours}h";
        } else {
            $durationStr = "{$mins}min";
        }
    } else {
        $durationStr = '-';
    }

    $status = $isPresent ? '✅ Présent' : '❌ Absent';

    printf("%-20s %-15s %-35s %-12s %-12s %-12s %-12s\n",
        substr($att->nom, 0, 20),
        substr($att->prenom ?? '', 0, 15),
        substr($att->email, 0, 35),
        $joinedTime,
        $leftTime,
        $durationStr,
        $status
    );
}

echo str_repeat('─', 120) . "\n";
echo "\nStatistiques de la séance:\n";
$totalP = $attendances60->count();
$validP = $attendances60->filter(fn($a) => $a->duration_minutes !== null && $a->duration_minutes > 3)->count();
$avgD = $attendances60->filter(fn($a) => $a->duration_minutes !== null && $a->duration_minutes > 0)->avg('duration_minutes');
echo "  Total: {$totalP} participants | Présents: {$validP} | Taux: " . round(($validP/$totalP)*100) . "% | Durée moy: " . round($avgD) . " min\n";

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "🎉 RÉSUMÉ DES CORRECTIONS APPLIQUÉES:\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "✅ 1. Seuil de présence unifié à 3 minutes partout\n";
echo "✅ 2. Enseignants/coordinateurs exclus (is_observer)\n";
echo "✅ 3. Données corrompues filtrées (duration = 0)\n";
echo "✅ 4. Participants connectés préservés (duration = NULL)\n";
echo "✅ 5. Durées futures en INTEGER (markAsDisconnected fix)\n";
echo "✅ 6. Modal popup avec 7 colonnes (Nom, Prénom, Email, Arrivée, Départ, Durée, Statut)\n";
echo "✅ 7. Statistiques cohérentes entre tableau et modal\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "\n💡 RÉSULTAT ATTENDU:\n";
echo "   - Séance #59: 0 participants (toutes données corrompues)\n";
echo "   - Séance #60: 4 participants (1 observateur exclu)\n";
echo "   - Séance #61: 4 participants (1 observateur exclu)\n";
echo "   - Séance #62: 3 participants (toutes données valides)\n";
echo "   - Page d'historique: affiche maintenant les données\n";
echo "   - Modal: affiche les détails corrects au clic sur 'Voir'\n";
echo "═══════════════════════════════════════════════════════════════════\n";
