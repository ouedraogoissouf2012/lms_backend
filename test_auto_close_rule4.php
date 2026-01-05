<?php

/**
 * TEST RÈGLE 4 : Protection heartbeat
 *
 * Scénario :
 * 1. Créer une séance active
 * 2. Ajouter des participants "connectés" mais avec heartbeat > 3 minutes (système en panne)
 * 3. Exécuter le job
 * 4. Vérifier que la séance N'EST PAS fermée (protection activée)
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
echo "TEST RÈGLE 4 : PROTECTION HEARTBEAT\n";
echo "========================================\n\n";

// Nettoyer les tests précédents
Seance::where('klassci_seance_id', 99994)->forceDelete();

// 1. Créer une séance active
$now = Carbon::now();
$seance = Seance::create([
    'klassci_seance_id' => 99994,
    'klassci_matiere_id' => 1,
    'klassci_classe_id' => 1,
    'klassci_enseignant_id' => 1,
    'visio_enabled' => true,
    'visio_active' => true,
    'visio_started_at' => $now->copy()->subMinutes(40),
    'visio_ended_at' => null,
    'is_active' => true,
]);

echo "✅ Séance créée (ID: {$seance->id})\n";
echo "   Démarrée il y a 40 minutes\n\n";

// 2. Ajouter des participants "connectés" avec heartbeat > 3 min (système suspect)
$users = User::whereIn('role', ['enseignant', 'etudiant'])->take(3)->get();

foreach ($users as $user) {
    $attendance = ESBTPAttendance::create([
        'seance_id' => $seance->id,
        'user_id' => $user->id,
        'joined_at' => $now->copy()->subMinutes(30),
        'last_seen_at' => $now->copy()->subMinutes(5), // Heartbeat de 5 minutes
        'status' => 'connected', // Marqué connecté mais heartbeat ancien
        'is_observer' => false,
    ]);

    echo "✅ Participant ajouté : {$user->name} ({$user->role})\n";
    echo "   Status : connected\n";
    echo "   Dernier heartbeat : il y a 5 minutes (> 3 min)\n\n";
}

echo "⚠️  SITUATION SUSPECTE :\n";
echo "   - Tous les participants sont marqués 'connected'\n";
echo "   - Mais tous les heartbeats > 3 minutes\n";
echo "   - Cela suggère que le système de heartbeat est en panne\n\n";

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "EXÉCUTION DU JOB AutoCloseEmptySeances\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// 3. Exécuter le job
$job = new AutoCloseEmptySeances();
$job->handle();

// 4. Vérifier le résultat
$seance->refresh();

echo "\n========================================\n";
echo "RÉSULTAT\n";
echo "========================================\n\n";

if ($seance->visio_active === true && $seance->visio_ended_at === null) {
    echo "✅ SUCCÈS : Séance reste ouverte (protection activée)\n\n";
    echo "   visio_active : true\n";
    echo "   visio_ended_at : NULL\n\n";
    echo "🎯 RÈGLE 4 VALIDÉE : Protection heartbeat a empêché une fermeture prématurée\n";
    echo "   (évite de fermer une séance où les participants sont peut-être encore présents)\n";
} else {
    echo "❌ ÉCHEC : La séance NE DEVRAIT PAS être fermée\n\n";
    echo "   visio_active : " . ($seance->visio_active ? 'true' : 'false') . "\n";
    echo "   visio_ended_at : " . ($seance->visio_ended_at ?? 'NULL') . "\n";
    echo "\n⚠️  La protection heartbeat n'a pas fonctionné !\n";
}

echo "\n========================================\n\n";

// Test négatif : avec au moins un heartbeat récent (doit fonctionner normalement)
echo "TEST NÉGATIF : Heartbeat sain (règle 3 devrait s'appliquer)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

Seance::where('klassci_seance_id', 99994)->forceDelete();

$seance2 = Seance::create([
    'klassci_seance_id' => 99994,
    'klassci_matiere_id' => 1,
    'klassci_classe_id' => 1,
    'klassci_enseignant_id' => 1,
    'visio_enabled' => true,
    'visio_active' => true,
    'visio_started_at' => $now->copy()->subMinutes(35), // 35 min, > seuil règle 3
    'visio_ended_at' => null,
    'is_active' => true,
]);

// Pas de participants = Règle 3 devrait fermer
echo "✅ Séance créée (ID: {$seance2->id})\n";
echo "   Démarrée il y a : 35 minutes\n";
echo "   Participants : 0\n";
echo "   Heartbeat : OK (pas de participants suspects)\n\n";

$job2 = new AutoCloseEmptySeances();
$job2->handle();

$seance2->refresh();

if ($seance2->visio_active === false && $seance2->visio_ended_at !== null) {
    echo "✅ SUCCÈS : Séance fermée par Règle 3 (no_participants)\n";
    echo "   La protection heartbeat n'a pas bloqué (normal)\n";
} else {
    echo "❌ ÉCHEC : La séance devrait être fermée par Règle 3\n";
}

echo "\n========================================\n\n";

// Nettoyer
Seance::where('klassci_seance_id', 99994)->forceDelete();
