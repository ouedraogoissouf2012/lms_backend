<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "═══════════════════════════════════════════════════════════════════\n";
echo "   INVESTIGATION APPROFONDIE - DONNÉES DE PRÉSENCE\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// 1. STRUCTURE DE LA TABLE SEANCES
echo "1️⃣  STRUCTURE TABLE 'seances':\n";
echo "─────────────────────────────────────────────────────────────────\n";
$seancesColumns = DB::select("PRAGMA table_info(seances)");
foreach ($seancesColumns as $col) {
    if (in_array($col->name, ['id', 'klassci_seance_id', 'visio_started_at', 'visio_ended_at', 'visio_status', 'matiere_nom', 'classe_nom', 'titre', 'date_seance', 'created_at', 'updated_at'])) {
        echo "   {$col->name} ({$col->type})\n";
    }
}

// 2. STRUCTURE DE LA TABLE ATTENDANCE
echo "\n2️⃣  STRUCTURE TABLE 'esbtp_attendance':\n";
echo "─────────────────────────────────────────────────────────────────\n";
$attendanceColumns = DB::select("PRAGMA table_info(esbtp_attendance)");
foreach ($attendanceColumns as $col) {
    echo "   {$col->name} ({$col->type})\n";
}

// 3. ANALYSE D'UNE SÉANCE RÉELLE
echo "\n3️⃣  ANALYSE SÉANCE #60 (Algorithme - 5 participants):\n";
echo "─────────────────────────────────────────────────────────────────\n";

$seance = DB::table('seances')->where('klassci_seance_id', 60)->first();
if ($seance) {
    echo "DONNÉES SÉANCE:\n";
    echo "  - ID local: {$seance->id}\n";
    echo "  - KLASSCI ID: {$seance->klassci_seance_id}\n";
    echo "  - Matière: {$seance->matiere_nom}\n";
    echo "  - Classe: " . ($seance->classe_nom ?? '[VIDE]') . "\n";
    echo "  - Titre: " . ($seance->titre ?? '[VIDE]') . "\n";
    echo "  - Date séance: " . ($seance->date_seance ?? '[VIDE]') . "\n";
    echo "  - Visio started: {$seance->visio_started_at}\n";
    echo "  - Visio ended: " . ($seance->visio_ended_at ?? '[VIDE]') . "\n";
    echo "  - Visio status: {$seance->visio_status}\n";
    echo "  - Created at: {$seance->created_at}\n";

    // Calculer la durée de la séance
    if ($seance->visio_started_at && $seance->visio_ended_at) {
        $start = new DateTime($seance->visio_started_at);
        $end = new DateTime($seance->visio_ended_at);
        $diff = $start->diff($end);
        $totalMinutes = ($diff->h * 60) + $diff->i + ($diff->days * 24 * 60);
        echo "  - DURÉE CALCULÉE SÉANCE: {$totalMinutes} minutes ({$diff->h}h {$diff->i}min)\n";
    } else {
        echo "  - DURÉE CALCULÉE SÉANCE: [IMPOSSIBLE - pas de visio_ended_at]\n";
    }

    echo "\nPARTICIPANTS (esbtp_attendance):\n";
    $attendances = DB::table('esbtp_attendance')
        ->where('seance_id', $seance->id)
        ->orderBy('joined_at')
        ->get();

    echo "  Total enregistrements: " . $attendances->count() . "\n\n";

    foreach ($attendances as $i => $att) {
        echo "  Participant " . ($i + 1) . ":\n";
        echo "    • Nom: {$att->nom} {$att->prenom}\n";
        echo "    • Email: {$att->email}\n";
        echo "    • User ID (local): {$att->user_id}\n";
        echo "    • KLASSCI étudiant ID: " . ($att->klassci_etudiant_id ?? '[VIDE]') . "\n";
        echo "    • Joined at: {$att->joined_at}\n";
        echo "    • Left at: " . ($att->left_at ?? '[VIDE]') . "\n";
        echo "    • Last seen at: " . ($att->last_seen_at ?? '[VIDE]') . "\n";
        echo "    • Status: {$att->status}\n";
        echo "    • Duration (DB): " . ($att->duration_minutes ?? '[VIDE]') . " minutes\n";

        // Calculer la durée réelle si joined_at et left_at existent
        if ($att->joined_at && $att->left_at) {
            $joined = new DateTime($att->joined_at);
            $left = new DateTime($att->left_at);
            $diff = $joined->diff($left);
            $calcDuration = ($diff->h * 60) + $diff->i + ($diff->days * 24 * 60);
            echo "    • DURÉE CALCULÉE: {$calcDuration} minutes\n";

            if ($att->duration_minutes != $calcDuration) {
                echo "    ⚠️  INCOHÉRENCE: DB={$att->duration_minutes} vs Calculé={$calcDuration}\n";
            }
        }
        echo "    • IP: " . ($att->ip_address ?? '[VIDE]') . "\n";
        echo "    • Created at: {$att->created_at}\n";
        echo "    • Updated at: {$att->updated_at}\n";
        echo "\n";
    }
}

echo "\n4️⃣  VÉRIFICATION DES CALCULS ACTUELS DU BACKEND:\n";
echo "─────────────────────────────────────────────────────────────────\n";

// Simuler le calcul du backend
if ($seance && $attendances) {
    $totalParticipants = $attendances->count();
    $validDurations = $attendances->filter(fn($a) => $a->duration_minutes !== null && $a->duration_minutes > 0);
    $avgDuration = $validDurations->count() > 0 ? round($validDurations->avg('duration_minutes')) : 0;
    $validPresences = $attendances->filter(fn($a) => $a->duration_minutes !== null && $a->duration_minutes > 3)->count();
    $presenceRate = $totalParticipants > 0 ? round(($validPresences / $totalParticipants) * 100) : 0;

    echo "Calculs backend (méthode actuelle):\n";
    echo "  - Total participants: {$totalParticipants}\n";
    echo "  - Durées valides: " . $validDurations->count() . "\n";
    echo "  - Durée moyenne: {$avgDuration} minutes\n";
    echo "  - Présences valides (>3min): {$validPresences}\n";
    echo "  - Taux de présence: {$presenceRate}%\n";
}

echo "\n5️⃣  RECHERCHE D'AUTRES SOURCES DE DONNÉES:\n";
echo "─────────────────────────────────────────────────────────────────\n";

// Chercher toutes les tables liées à attendance/presence
$tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND (name LIKE '%attendance%' OR name LIKE '%presence%' OR name LIKE '%seance%')");
echo "Tables liées trouvées:\n";
foreach ($tables as $table) {
    echo "  - {$table->name}\n";
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "FIN DE L'INVESTIGATION\n";
echo "═══════════════════════════════════════════════════════════════════\n";
