<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "═══════════════════════════════════════════════════════════════════\n";
echo "   TEST DU NOUVEAU FILTRE - VÉRIFICATION DONNÉES\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// Trouver toutes les séances avec visio démarrée
$seances = DB::table('seances')
    ->whereNotNull('visio_started_at')
    ->orderBy('klassci_seance_id', 'desc')
    ->limit(5)
    ->get();

echo "📊 ANALYSE DES 5 DERNIÈRES SÉANCES:\n";
echo str_repeat('─', 100) . "\n\n";

foreach ($seances as $seance) {
    echo "Séance #{$seance->klassci_seance_id} - {$seance->matiere_nom}\n";
    echo "  Date: {$seance->visio_started_at}\n";

    // TOUS les participants (sans filtre)
    $allAttendances = DB::table('esbtp_attendance')
        ->where('seance_id', $seance->id)
        ->get();

    // Participants AVEC le nouveau filtre (comme dans le code corrigé)
    $filteredAttendances = DB::table('esbtp_attendance')
        ->where('seance_id', $seance->id)
        ->where(function($query) {
            // Exclure les observateurs
            $query->where('is_observer', false)
                  ->orWhereNull('is_observer');
        })
        ->where(function($query) {
            // Garder: durée > 0 OU durée NULL
            // Exclure: durée = 0
            $query->where('duration_minutes', '>', 0)
                  ->orWhereNull('duration_minutes');
        })
        ->get();

    // Analyse détaillée
    $observers = $allAttendances->filter(fn($a) => $a->is_observer === 1)->count();
    $durationZero = $allAttendances->filter(fn($a) => $a->duration_minutes === 0 || $a->duration_minutes === 0.0)->count();
    $durationNull = $allAttendances->filter(fn($a) => $a->duration_minutes === null)->count();
    $durationPositive = $allAttendances->filter(fn($a) => $a->duration_minutes !== null && $a->duration_minutes > 0)->count();

    echo "  Total brut: {$allAttendances->count()} participants\n";
    echo "    • Observateurs: {$observers}\n";
    echo "    • Durée = 0 (corrompu): {$durationZero}\n";
    echo "    • Durée NULL (encore connecté): {$durationNull}\n";
    echo "    • Durée > 0 (déconnecté): {$durationPositive}\n";
    echo "  Après filtre: {$filteredAttendances->count()} participants (attendu: " . ($durationNull + $durationPositive) . ")\n";

    if ($filteredAttendances->count() !== ($durationNull + $durationPositive)) {
        echo "  ⚠️  PROBLÈME: Le filtre ne donne pas le résultat attendu!\n";
    } else {
        echo "  ✅ FILTRE OK\n";
    }

    if ($filteredAttendances->count() > 0) {
        echo "  Détail participants affichés:\n";
        foreach ($filteredAttendances as $att) {
            $duration = $att->duration_minutes ?? 'NULL';
            $observer = $att->is_observer ? 'OUI' : 'NON';
            $status = $att->duration_minutes !== null && $att->duration_minutes > 3 ? '✅ Présent' : '❌ Absent';
            echo "    - {$att->nom} {$att->prenom} | Durée: {$duration} min | Observateur: {$observer} | {$status}\n";
        }
    }

    echo "\n";
}

echo "═══════════════════════════════════════════════════════════════════\n";
echo "CONCLUSION:\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "Le nouveau filtre devrait:\n";
echo "  ✅ GARDER les participants avec durée > 0 (déjà déconnectés)\n";
echo "  ✅ GARDER les participants avec durée NULL (encore connectés)\n";
echo "  ❌ EXCLURE les participants avec durée = 0 (données corrompues)\n";
echo "  ❌ EXCLURE les observateurs (enseignants/coordinateurs)\n";
echo "═══════════════════════════════════════════════════════════════════\n";
