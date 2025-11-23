<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  VÉRIFICATION COMPLÈTE - SÉANCE EN COURS                         ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

// 1. Trouver les séances avec visio activée
echo "┌─ ÉTAPE 1: Séances avec visio activée ────────────────────────────┐\n\n";

$seances = DB::table('seances')
    ->where('visio_enabled', 1)
    ->orderBy('id', 'desc')
    ->get();

if ($seances->isEmpty()) {
    echo "❌ Aucune séance avec visio activée trouvée\n";
    exit;
}

echo "✅ " . count($seances) . " séance(s) avec visio activée\n\n";

foreach ($seances as $seance) {
    echo "📚 Séance #" . $seance->id . "\n";
    echo "   Matière: " . ($seance->matiere_nom ?? 'N/A') . "\n";
    echo "   Classe: " . ($seance->classe_nom ?? 'N/A') . "\n";

    $enseignant = 'N/A';
    if (isset($seance->enseignant_nom)) {
        $prenom = $seance->enseignant_prenom ?? '';
        $enseignant = trim($prenom . ' ' . $seance->enseignant_nom);
    }
    echo "   Enseignant: " . $enseignant . "\n";
    echo "   \n";
    echo "   📡 VISIO:\n";
    echo "      Status: " . ($seance->visio_status ?? 'N/A') . "\n";
    echo "      Active: " . ($seance->visio_active ? 'OUI' : 'NON') . "\n";
    echo "      Room ID: " . ($seance->visio_room_id ?? 'N/A') . "\n";
    echo "      Type: " . ($seance->visio_type ?? 'N/A') . "\n";

    if ($seance->visio_started_at) {
        echo "      Démarrée à: " . $seance->visio_started_at . "\n";
    }

    // Vérifier les participants
    $participants = DB::table('esbtp_attendance')
        ->where('seance_id', $seance->id)
        ->get();

    echo "      \n";
    echo "      👥 PARTICIPANTS: " . count($participants) . "\n";

    if (count($participants) > 0) {
        foreach ($participants as $p) {
            $entree = $p->joined_at ? date('H:i:s', strtotime($p->joined_at)) : 'N/A';
            $sortie = $p->left_at ? date('H:i:s', strtotime($p->left_at)) : '⏳ En cours';

            echo "         → " . ($p->nom ?? 'N/A');
            if (isset($p->role) && $p->role) {
                echo " (" . $p->role . ")";
            }
            echo "\n";
            echo "            Entrée: " . $entree . " | Sortie: " . $sortie . "\n";
            echo "            Status: " . ($p->status ?? 'N/A') . "\n";
        }
    } else {
        echo "         ⚠️  Aucun participant enregistré\n";
    }

    echo "\n";
}

echo "└───────────────────────────────────────────────────────────────────┘\n\n";

// 2. Vérification de la logique workflow
echo "┌─ ÉTAPE 2: Vérification du workflow ───────────────────────────────┐\n\n";

$programmee = DB::table('seances')
    ->where('visio_enabled', 1)
    ->where('visio_status', 'programmee')
    ->count();

$active = DB::table('seances')
    ->where('visio_enabled', 1)
    ->where('visio_status', 'active')
    ->count();

echo "📊 Répartition des statuts:\n";
echo "   ⏱️  Programmée (en attente): $programmee séance(s)\n";
echo "   ✅ Active (en cours): $active séance(s)\n\n";

if ($programmee > 0) {
    echo "✅ Workflow correct: Les séances sont en attente du démarrage par l'enseignant\n";
} else {
    echo "⚠️  Toutes les séances sont actives\n";
}

echo "\n└───────────────────────────────────────────────────────────────────┘\n\n";

// 3. Tests de cohérence
echo "┌─ ÉTAPE 3: Tests de cohérence ────────────────────────────────────┐\n\n";

$issues = [];

foreach ($seances as $seance) {
    // Test 1: Si active, doit avoir visio_active = true
    if ($seance->visio_status === 'active' && !$seance->visio_active) {
        $issues[] = "Séance #{$seance->id}: status='active' mais visio_active=false";
    }

    // Test 2: Si programmee, doit avoir visio_active = false
    if ($seance->visio_status === 'programmee' && $seance->visio_active) {
        $issues[] = "Séance #{$seance->id}: status='programmee' mais visio_active=true";
    }

    // Test 3: Si active, doit avoir visio_started_at
    if ($seance->visio_status === 'active' && !$seance->visio_started_at) {
        $issues[] = "Séance #{$seance->id}: status='active' mais pas de visio_started_at";
    }

    // Test 4: Vérifier que les participants ont des heures d'entrée
    $participantsSansEntree = DB::table('esbtp_attendance')
        ->where('seance_id', $seance->id)
        ->whereNull('joined_at')
        ->count();

    if ($participantsSansEntree > 0) {
        $issues[] = "Séance #{$seance->id}: $participantsSansEntree participant(s) sans heure d'entrée";
    }
}

if (empty($issues)) {
    echo "✅ Aucune incohérence détectée!\n";
    echo "   Tout semble correct.\n";
} else {
    echo "⚠️  " . count($issues) . " problème(s) détecté(s):\n\n";
    foreach ($issues as $issue) {
        echo "   ❌ $issue\n";
    }
}

echo "\n└───────────────────────────────────────────────────────────────────┘\n\n";

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  FIN DE LA VÉRIFICATION                                          ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
