<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "═══════════════════════════════════════════════════════════════════\n";
echo "   SIMULATION RÉPONSE API - getSeanceAttendances()\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// Tester avec séance #60 (qui devrait afficher 4 étudiants, pas 5)
$seance = DB::table('seances')->where('klassci_seance_id', 60)->first();

if (!$seance) {
    echo "❌ Séance #60 non trouvée\n";
    exit(1);
}

echo "🔍 Test avec Séance #60 - {$seance->matiere_nom}\n\n";

// Simuler exactement la logique du contrôleur
$attendances = \App\Models\ESBTPAttendance::where('seance_id', $seance->id)
    ->where(function($query) {
        // Exclure les observateurs (enseignants/coordinateurs)
        $query->where('is_observer', false)
              ->orWhereNull('is_observer');
    })
    ->where(function($query) {
        // Garder: durée > 0 OU durée NULL (pas encore quitté)
        // Exclure: durée = 0 (données corrompues: joined = left)
        $query->where('duration_minutes', '>', 0)
              ->orWhereNull('duration_minutes');
    })
    ->orderBy('joined_at', 'desc')
    ->get();

echo "Nombre de participants retournés: {$attendances->count()}\n\n";

// Enrichir les données (comme dans le contrôleur)
$enrichedData = $attendances->map(function($attendance) {
    // Calculer le statut de présence basé sur la durée (> 3 min)
    $isPresent = $attendance->duration_minutes !== null && $attendance->duration_minutes > 3;

    return [
        'id' => $attendance->id,
        'nom' => $attendance->nom,
        'prenom' => $attendance->prenom,
        'email' => $attendance->email,
        'joined_at' => $attendance->joined_at,
        'left_at' => $attendance->left_at,
        'duration_minutes' => $attendance->duration_minutes,
        'is_present' => $isPresent,
        'presence_status' => $isPresent ? 'Présent' : 'Absent',
    ];
});

// Calculer les statistiques
$totalParticipants = $attendances->count();
$validDurations = $attendances->filter(fn($a) => $a->duration_minutes !== null && $a->duration_minutes > 0);
$avgDuration = $validDurations->count() > 0 ? round($validDurations->avg('duration_minutes')) : 0;
$validPresences = $attendances->filter(fn($a) => $a->duration_minutes !== null && $a->duration_minutes > 3)->count();
$presenceRate = $totalParticipants > 0 ? round(($validPresences / $totalParticipants) * 100) : 0;

echo "STATISTIQUES:\n";
echo "  Total participants: {$totalParticipants}\n";
echo "  Durée moyenne: {$avgDuration} min\n";
echo "  Présences valides (>3 min): {$validPresences}\n";
echo "  Taux de présence: {$presenceRate}%\n\n";

echo "DÉTAIL DES PARTICIPANTS (format API):\n";
echo str_repeat('─', 100) . "\n";
printf("%-20s %-20s %-30s %-12s %-12s\n", "Nom", "Prénom", "Email", "Durée", "Statut");
echo str_repeat('─', 100) . "\n";

foreach ($enrichedData as $att) {
    printf("%-20s %-20s %-30s %-12s %-12s\n",
        substr($att['nom'], 0, 20),
        substr($att['prenom'], 0, 20),
        substr($att['email'], 0, 30),
        ($att['duration_minutes'] ? round($att['duration_minutes']) . ' min' : 'NULL'),
        $att['presence_status']
    );
}

echo str_repeat('─', 100) . "\n\n";

// Construire la réponse JSON (comme le contrôleur)
$response = [
    'attendances' => $enrichedData,
    'statistics' => [
        'total_participants' => $totalParticipants,
        'average_duration' => $avgDuration,
        'presence_rate' => $presenceRate,
    ],
];

echo "RÉPONSE JSON (aperçu):\n";
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

echo "\n\n═══════════════════════════════════════════════════════════════════\n";
echo "✅ La réponse API devrait maintenant être correcte\n";
echo "   - 4 étudiants affichés (1 observateur exclu)\n";
echo "   - Tous avec statut 'Présent' (durée > 3 min)\n";
echo "   - Taux de présence: 100%\n";
echo "═══════════════════════════════════════════════════════════════════\n";
