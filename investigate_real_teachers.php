<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "═══════════════════════════════════════════════════════════════════\n";
echo "   INVESTIGATION - QUI EST VRAIMENT L'ENSEIGNANT ?\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$seance = DB::table('seances')->where('klassci_seance_id', 61)->first();

echo "SÉANCE #61 - {$seance->matiere_nom}\n";
echo "Date: {$seance->visio_started_at}\n";
echo "visio_ended_at: " . ($seance->visio_ended_at ?? 'NULL') . "\n\n";

echo "1️⃣  DANS LA TABLE SEANCES:\n";
echo "─────────────────────────────────────────────────────────────────\n";
echo "enseignant_nom: " . ($seance->enseignant_nom ?? '[NULL]') . "\n";
echo "klassci_enseignant_id: " . ($seance->klassci_enseignant_id ?? '[NULL]') . "\n\n";

echo "2️⃣  DANS LA TABLE ESBTP_ATTENDANCE:\n";
echo "─────────────────────────────────────────────────────────────────\n";

$allParticipants = DB::table('esbtp_attendance')
    ->where('seance_id', $seance->id)
    ->get();

echo "Total participants: {$allParticipants->count()}\n\n";

foreach ($allParticipants as $p) {
    echo "• {$p->nom}\n";
    echo "  - user_id: {$p->user_id}\n";
    echo "  - klassci_etudiant_id: " . ($p->klassci_etudiant_id ?? '[NULL]') . "\n";
    echo "  - is_observer: " . ($p->is_observer ? 'TRUE' : 'FALSE') . "\n";
    echo "  - email: {$p->email}\n";
    echo "  - duration: " . ($p->duration_minutes ?? 'NULL') . " min\n\n";
}

echo "3️⃣  DANS LA TABLE USERS:\n";
echo "─────────────────────────────────────────────────────────────────\n";

foreach ($allParticipants as $p) {
    $user = DB::table('users')->where('id', $p->user_id)->first();
    if ($user) {
        echo "• {$p->nom} (user_id: {$p->user_id})\n";
        echo "  - role: " . ($user->role ?? '[NULL]') . "\n";
        echo "  - is_enseignant: " . ($user->is_enseignant ?? '[NULL]') . "\n";
        echo "  - is_coordinateur: " . ($user->is_coordinateur ?? '[NULL]') . "\n";
        echo "  - type_user: " . ($user->type_user ?? '[NULL]') . "\n\n";
    }
}

echo "4️⃣  VÉRIFIER KLASSCI_ENSEIGNANT_ID:\n";
echo "─────────────────────────────────────────────────────────────────\n";
if ($seance->klassci_enseignant_id) {
    echo "L'enseignant selon KLASSCI est ID: {$seance->klassci_enseignant_id}\n";

    // Chercher cet ID dans les participants
    $teacherFromKlassci = $allParticipants->first(function($p) use ($seance) {
        return $p->klassci_etudiant_id == $seance->klassci_enseignant_id;
    });

    if ($teacherFromKlassci) {
        echo "✅ TROUVÉ dans esbtp_attendance: {$teacherFromKlassci->nom}\n";
    } else {
        echo "❌ PAS TROUVÉ dans esbtp_attendance\n";
    }
}

echo "\n5️⃣  ANALYSE LOGIQUE:\n";
echo "─────────────────────────────────────────────────────────────────\n";
echo "HYPOTHÈSES:\n";
echo "A. L'enseignant = celui avec is_observer = 1\n";
echo "B. L'enseignant = celui dont klassci_etudiant_id = seances.klassci_enseignant_id\n";
echo "C. L'enseignant = celui dans users avec is_enseignant = 1\n";
echo "D. Le coordinateur = celui dans users avec is_coordinateur = 1\n";

echo "\nQUI EST QUOI ?\n";
foreach ($allParticipants as $p) {
    $user = DB::table('users')->where('id', $p->user_id)->first();
    $isObserver = $p->is_observer ? '✓' : '✗';
    $matchKlassci = ($p->klassci_etudiant_id == $seance->klassci_enseignant_id) ? '✓' : '✗';
    $isEnseignant = ($user && ($user->is_enseignant ?? false)) ? '✓' : '✗';
    $isCoordinateur = ($user && ($user->is_coordinateur ?? false)) ? '✓' : '✗';

    echo "\n{$p->nom}:\n";
    echo "  Observer? {$isObserver} | Match klassci_enseignant_id? {$matchKlassci} | is_enseignant? {$isEnseignant} | is_coordinateur? {$isCoordinateur}\n";
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "QUELLE EST LA BONNE LOGIQUE POUR IDENTIFIER L'ENSEIGNANT ?\n";
echo "═══════════════════════════════════════════════════════════════════\n";
