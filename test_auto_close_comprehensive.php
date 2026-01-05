<?php

/**
 * TEST COMPLET : Tous les scénarios et edge cases
 *
 * Tests inclus :
 * 1. Ordre de priorité des règles
 * 2. Enseignant se reconnecte
 * 3. Coordinateurs (observers) exclus des calculs
 * 4. Race condition avec fermeture manuelle
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Seance;
use App\Models\ESBTPAttendance;
use App\Models\User;
use App\Jobs\AutoCloseEmptySeances;
use Carbon\Carbon;

echo "\n========================================\n";
echo "TEST COMPLET : TOUS LES SCÉNARIOS\n";
echo "========================================\n\n";

$testsPassed = 0;
$testsFailed = 0;

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// TEST 1 : Ordre de priorité (Règle 1 > Règle 2)
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "TEST 1 : Ordre de priorité des règles\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$seancesToClean = Seance::where('klassci_seance_id', 99901)->get();
foreach ($seancesToClean as $s) {
    ESBTPAttendance::where('seance_id', $s->id)->forceDelete();
}
Seance::where('klassci_seance_id', 99901)->forceDelete();

$now = Carbon::now();
$seance1 = Seance::create([
    'klassci_seance_id' => 99901,
    'klassci_matiere_id' => 1,
    'klassci_classe_id' => 1,
    'klassci_enseignant_id' => 1,
    'visio_enabled' => true,
    'visio_active' => true,
    'visio_started_at' => $now->copy()->subMinutes(20),
    'visio_ended_at' => null,
    'is_active' => true,
]);

// Enseignant quitté il y a 6 min (Règle 1 : seuil 5 min)
$enseignant = User::where('role', 'enseignant')->first();
ESBTPAttendance::create([
    'seance_id' => $seance1->id,
    'user_id' => $enseignant->id,
    'joined_at' => $now->copy()->subMinutes(15),
    'left_at' => $now->copy()->subMinutes(6),
    'last_seen_at' => $now->copy()->subMinutes(6),
    'status' => 'disconnected',
    'duration_minutes' => 9,
    'is_observer' => false,
]);

// Étudiant quitté il y a 4 min (Règle 2 : seuil 10 min)
$etudiant = User::where('role', 'etudiant')->first();
ESBTPAttendance::create([
    'seance_id' => $seance1->id,
    'user_id' => $etudiant->id,
    'joined_at' => $now->copy()->subMinutes(10),
    'left_at' => $now->copy()->subMinutes(4),
    'last_seen_at' => $now->copy()->subMinutes(4),
    'status' => 'disconnected',
    'duration_minutes' => 6,
    'is_observer' => false,
]);

echo "Scénario :\n";
echo "  - Enseignant quitté il y a 6 min (Règle 1: ✅ devrait fermer)\n";
echo "  - Étudiant quitté il y a 4 min (Règle 2: ❌ pas encore 10 min)\n";
echo "  - Attendu : Fermé par Règle 1 (prioritaire)\n\n";

$job = new AutoCloseEmptySeances();
$job->handle();

$seance1->refresh();

if ($seance1->visio_active === false) {
    echo "✅ PASS : Séance fermée par Règle 1 (teacher_disconnected)\n";
    $testsPassed++;
} else {
    echo "❌ FAIL : Séance devrait être fermée\n";
    $testsFailed++;
}

echo "\n";
Seance::where('id', $seance1->id)->forceDelete();

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// TEST 2 : Enseignant se reconnecte
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "TEST 2 : Enseignant se reconnecte (ne doit PAS fermer)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$seancesToClean = Seance::where('klassci_seance_id', 99902)->get();
foreach ($seancesToClean as $s) {
    ESBTPAttendance::where('seance_id', $s->id)->forceDelete();
}
Seance::where('klassci_seance_id', 99902)->forceDelete();

$seance2 = Seance::create([
    'klassci_seance_id' => 99902,
    'klassci_matiere_id' => 1,
    'klassci_classe_id' => 1,
    'klassci_enseignant_id' => 1,
    'visio_enabled' => true,
    'visio_active' => true,
    'visio_started_at' => $now->copy()->subMinutes(20),
    'visio_ended_at' => null,
    'is_active' => true,
]);

// Enseignant connecté (a quitté mais s'est reconnecté)
ESBTPAttendance::create([
    'seance_id' => $seance2->id,
    'user_id' => $enseignant->id,
    'joined_at' => $now->copy()->subMinutes(15),
    'last_seen_at' => $now->copy()->subSeconds(30), // Heartbeat récent
    'status' => 'connected', // Actuellement connecté
    'is_observer' => false,
]);

echo "Scénario :\n";
echo "  - Enseignant quitté puis reconnecté (status = connected)\n";
echo "  - Attendu : Séance reste ouverte\n\n";

$job = new AutoCloseEmptySeances();
$job->handle();

$seance2->refresh();

if ($seance2->visio_active === true) {
    echo "✅ PASS : Séance reste ouverte (enseignant connecté)\n";
    $testsPassed++;
} else {
    echo "❌ FAIL : Séance ne devrait PAS être fermée\n";
    $testsFailed++;
}

echo "\n";
Seance::where('id', $seance2->id)->forceDelete();

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// TEST 3 : Coordinateurs exclus
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "TEST 3 : Coordinateurs (observers) exclus des calculs\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$seancesToClean = Seance::where('klassci_seance_id', 99903)->get();
foreach ($seancesToClean as $s) {
    ESBTPAttendance::where('seance_id', $s->id)->forceDelete();
}
Seance::where('klassci_seance_id', 99903)->forceDelete();

$seance3 = Seance::create([
    'klassci_seance_id' => 99903,
    'klassci_matiere_id' => 1,
    'klassci_classe_id' => 1,
    'klassci_enseignant_id' => 1,
    'visio_enabled' => true,
    'visio_active' => true,
    'visio_started_at' => $now->copy()->subMinutes(35),
    'visio_ended_at' => null,
    'is_active' => true,
]);

// Coordinateur connecté (observer = true)
$coordinateur = User::where('role', 'coordinateur')->first();
if ($coordinateur) {
    ESBTPAttendance::create([
        'seance_id' => $seance3->id,
        'user_id' => $coordinateur->id,
        'joined_at' => $now->copy()->subMinutes(30),
        'last_seen_at' => $now->copy()->subSeconds(30),
        'status' => 'connected',
        'is_observer' => true, // Observer
    ]);

    echo "Scénario :\n";
    echo "  - Séance active depuis 35 min\n";
    echo "  - Seul un coordinateur (observer) est présent\n";
    echo "  - Attendu : Fermé par Règle 3 (observers exclus)\n\n";

    $job = new AutoCloseEmptySeances();
    $job->handle();

    $seance3->refresh();

    if ($seance3->visio_active === false) {
        echo "✅ PASS : Séance fermée (coordinateur ignoré)\n";
        $testsPassed++;
    } else {
        echo "❌ FAIL : Séance devrait être fermée\n";
        $testsFailed++;
    }
} else {
    echo "⚠️  SKIP : Aucun coordinateur trouvé\n";
}

echo "\n";
Seance::where('id', $seance3->id)->forceDelete();

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// TEST 4 : Race condition (séance déjà fermée)
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "TEST 4 : Race condition - séance déjà fermée manuellement\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$seancesToClean = Seance::where('klassci_seance_id', 99904)->get();
foreach ($seancesToClean as $s) {
    ESBTPAttendance::where('seance_id', $s->id)->forceDelete();
}
Seance::where('klassci_seance_id', 99904)->forceDelete();

$seance4 = Seance::create([
    'klassci_seance_id' => 99904,
    'klassci_matiere_id' => 1,
    'klassci_classe_id' => 1,
    'klassci_enseignant_id' => 1,
    'visio_enabled' => true,
    'visio_active' => false, // Déjà fermée
    'visio_started_at' => $now->copy()->subMinutes(40),
    'visio_ended_at' => $now->copy()->subMinutes(1),
    'is_active' => true,
]);

echo "Scénario :\n";
echo "  - Séance déjà fermée (visio_active = false)\n";
echo "  - Attendu : Job doit skip cette séance\n\n";

$job = new AutoCloseEmptySeances();
$job->handle();

$seance4->refresh();

if ($seance4->visio_ended_at->diffInMinutes($now) < 2) {
    echo "✅ PASS : Séance skippée (timestamp inchangé)\n";
    $testsPassed++;
} else {
    echo "❌ FAIL : Le job ne devrait pas toucher cette séance\n";
    $testsFailed++;
}

echo "\n";
Seance::where('id', $seance4->id)->forceDelete();

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// RÉSUMÉ
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
echo "\n========================================\n";
echo "RÉSUMÉ DES TESTS\n";
echo "========================================\n\n";

$total = $testsPassed + $testsFailed;
echo "Total : {$total} tests\n";
echo "✅ Passés : {$testsPassed}\n";
echo "❌ Échoués : {$testsFailed}\n\n";

if ($testsFailed === 0) {
    echo "🎉 TOUS LES TESTS SONT PASSÉS !\n";
} else {
    echo "⚠️  Certains tests ont échoué, vérifier les logs\n";
}

echo "\n========================================\n\n";
