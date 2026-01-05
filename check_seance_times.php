<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "═══════════════════════════════════════════════════════════════════\n";
echo "   ANALYSE DES HEURES DE SÉANCE\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$seance = DB::table('seances')->where('klassci_seance_id', 61)->first();

echo "SÉANCE #61:\n";
echo "─────────────────────────────────────────────────────────────────\n\n";

echo "1️⃣  HEURES PROGRAMMÉES (depuis KLASSCI):\n";
echo "   date_seance: " . ($seance->date_seance ?? '[NULL]') . "\n";
echo "   heure_debut: " . ($seance->heure_debut ?? '[NULL]') . "\n";
echo "   heure_fin: " . ($seance->heure_fin ?? '[NULL]') . "\n\n";

echo "2️⃣  HEURES RÉELLES (visioconférence):\n";
echo "   visio_started_at: " . ($seance->visio_started_at ?? '[NULL]') . "\n";
echo "   visio_ended_at: " . ($seance->visio_ended_at ?? '[NULL]') . "\n\n";

// Calculer les durées
if ($seance->heure_debut && $seance->heure_fin) {
    $debut = new DateTime($seance->date_seance . ' ' . $seance->heure_debut);
    $fin = new DateTime($seance->date_seance . ' ' . $seance->heure_fin);
    $diff = $debut->diff($fin);
    $dureeTheorique = ($diff->h * 60) + $diff->i;
    echo "3️⃣  DURÉE THÉORIQUE (programmée):\n";
    echo "   {$dureeTheorique} minutes ({$diff->h}h {$diff->i}min)\n\n";
}

if ($seance->visio_started_at && $seance->visio_ended_at) {
    $start = new DateTime($seance->visio_started_at);
    $end = new DateTime($seance->visio_ended_at);
    $diff = $start->diff($end);
    $dureeReelle = ($diff->h * 60) + $diff->i;
    echo "4️⃣  DURÉE RÉELLE (visio effectuée):\n";
    echo "   {$dureeReelle} minutes ({$diff->h}h {$diff->i}min)\n\n";
}

echo "5️⃣  ARRIVÉES DES PARTICIPANTS:\n";
$attendances = DB::table('esbtp_attendance')
    ->where('seance_id', $seance->id)
    ->orderBy('joined_at')
    ->get();

foreach ($attendances as $att) {
    echo "   • {$att->nom}: {$att->joined_at}\n";
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "QUESTION LOGIQUE:\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

echo "OPTION A - Heure programmée (KLASSCI):\n";
if ($seance->heure_debut && $seance->heure_fin) {
    echo "   Séance prévue: {$seance->heure_debut} - {$seance->heure_fin}\n";
    echo "   Calcul: durée_étudiant / durée_programmée\n";
    echo "   ✅ Avantage: Évalue la présence par rapport au planning officiel\n";
    echo "   ❌ Inconvénient: Si l'enseignant démarre en retard, les étudiants sont pénalisés\n";
} else {
    echo "   ❌ Pas d'heures programmées dans la DB\n";
}

echo "\nOPTION B - Heure réelle (visio_started_at → visio_ended_at):\n";
if ($seance->visio_started_at && $seance->visio_ended_at) {
    echo "   Séance effectuée: " . date('H:i', strtotime($seance->visio_started_at)) . " - " . date('H:i', strtotime($seance->visio_ended_at)) . "\n";
    echo "   Calcul: durée_étudiant / durée_visio_réelle\n";
    echo "   ✅ Avantage: Basé sur la réalité de ce qui s'est passé\n";
    echo "   ✅ Avantage: Plus juste si l'enseignant termine en avance ou en retard\n";
} else {
    echo "   ❌ Pas de visio_ended_at\n";
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "RECOMMANDATION:\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "Il est plus juste d'utiliser:\n";
echo "  ➤ visio_started_at → visio_ended_at (OPTION B)\n\n";
echo "Car c'est la durée RÉELLE où l'enseignant était connecté.\n";
echo "Si l'enseignant démarre à 19:22 au lieu de 19:00, on compte depuis 19:22.\n";
echo "═══════════════════════════════════════════════════════════════════\n";
