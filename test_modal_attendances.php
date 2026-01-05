<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== TEST MODAL ATTENDANCES ===\n\n";

// 1. Récupérer une séance avec participations
$seance = DB::table('seances')
    ->whereNotNull('visio_started_at')
    ->first();

if (!$seance) {
    echo "❌ Aucune séance trouvée\n";
    exit(1);
}

echo "1. Séance test: #{$seance->klassci_seance_id}\n";
echo "   Matière: {$seance->matiere_nom}\n";
echo "   Titre: " . ($seance->titre ?? 'Non défini') . "\n\n";

// 2. Récupérer les participations
$attendances = DB::table('esbtp_attendance')
    ->where('seance_id', $seance->id)
    ->orderBy('joined_at', 'desc')
    ->get();

echo "2. Participations trouvées: " . $attendances->count() . "\n\n";

if ($attendances->isEmpty()) {
    echo "⚠️  Aucune participation pour cette séance\n";
    echo "Le tableau sera vide dans la modal\n";
    exit(0);
}

// 3. Afficher les données comme elles apparaîtront dans la modal
echo "3. Aperçu des données modal:\n";
echo str_repeat('-', 100) . "\n";
printf("%-20s %-15s %-30s %-15s %-15s %-10s %s\n",
    "Nom", "Prénom", "Email", "Arrivée", "Départ", "Durée", "Statut");
echo str_repeat('-', 100) . "\n";

foreach ($attendances->take(10) as $att) {
    $isPresent = $att->duration_minutes !== null && $att->duration_minutes > 3;
    $status = $isPresent ? 'Présent' : 'Absent';

    printf("%-20s %-15s %-30s %-15s %-15s %-10s %s\n",
        substr($att->nom ?? '-', 0, 20),
        substr($att->prenom ?? '-', 0, 15),
        substr($att->email ?? '-', 0, 30),
        $att->joined_at ? date('H:i:s', strtotime($att->joined_at)) : '-',
        $att->left_at ? date('H:i:s', strtotime($att->left_at)) : '-',
        ($att->duration_minutes ?? 0) . ' min',
        $status
    );
}

echo str_repeat('-', 100) . "\n";

// 4. Statistiques
$total = $attendances->count();
$validDurations = $attendances->filter(fn($a) => $a->duration_minutes !== null && $a->duration_minutes > 0);
$avgDuration = $validDurations->count() > 0 ? round($validDurations->avg('duration_minutes')) : 0;
$validPresences = $attendances->filter(fn($a) => $a->duration_minutes !== null && $a->duration_minutes > 3)->count();
$presenceRate = $total > 0 ? round(($validPresences / $total) * 100) : 0;

echo "\n4. Statistiques (affichées en bas de modal):\n";
echo "   Total participants: $total\n";
echo "   Durée moyenne: $avgDuration min\n";
echo "   Taux de présence: {$presenceRate}%\n";

echo "\n✅ Test terminé - La modal devrait afficher ces données\n";
