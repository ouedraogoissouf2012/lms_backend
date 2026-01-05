<?php

/**
 * TEST RÈGLE 2 : Tous les participants déconnectés
 *
 * Scénario :
 * 1. Créer une séance active
 * 2. Ajouter 3 participants qui ont tous quitté il y a 11 minutes
 * 3. Exécuter le job
 * 4. Vérifier que la séance est fermée (raison: all_disconnected)
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
echo "TEST RÈGLE 2 : TOUS DÉCONNECTÉS\n";
echo "========================================\n\n";

// Nettoyer les tests précédents
Seance::where('klassci_seance_id', 99992)->forceDelete();

// 1. Créer une séance active
$now = Carbon::now();
$seance = Seance::create([
    'klassci_seance_id' => 99992,
    'klassci_matiere_id' => 1,
    'klassci_classe_id' => 1,
    'klassci_enseignant_id' => 1,
    'visio_enabled' => true,
    'visio_active' => true,
    'visio_started_at' => $now->copy()->subMinutes(30),
    'visio_ended_at' => null,
    'is_active' => true,
]);

echo "✅ Séance créée (ID: {$seance->id})\n";
echo "   Démarrée il y a 30 minutes\n\n";

// 2. Ajouter 3 participants tous déconnectés il y a 11 minutes
$users = User::whereIn('role', ['enseignant', 'etudiant'])->take(3)->get();
$leftAt = $now->copy()->subMinutes(11);

foreach ($users as $user) {
    ESBTPAttendance::create([
        'seance_id' => $seance->id,
        'user_id' => $user->id,
        'joined_at' => $now->copy()->subMinutes(25),
        'left_at' => $leftAt,
        'last_seen_at' => $leftAt,
        'status' => 'disconnected',
        'duration_minutes' => 14,
        'is_observer' => false,
    ]);

    echo "✅ Participant ajouté : {$user->name} ({$user->role})\n";
    echo "   Quitté il y a : 11 minutes\n";
    echo "   Status : disconnected\n\n";
}

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

if ($seance->visio_active === false && $seance->visio_ended_at !== null) {
    echo "✅ SUCCÈS : Séance fermée\n\n";
    echo "   visio_active : false\n";
    echo "   visio_ended_at : {$seance->visio_ended_at}\n";
    echo "   Durée totale : " . $seance->visio_started_at->diffInMinutes($seance->visio_ended_at) . " minutes\n\n";

    // Vérifier le timestamp de fin (devrait être le dernier left_at)
    $expectedEndTime = $leftAt->toDateTimeString();
    $actualEndTime = $seance->visio_ended_at->toDateTimeString();

    echo "   Timestamp de fin attendu : {$expectedEndTime}\n";
    echo "   Timestamp de fin réel    : {$actualEndTime}\n\n";

    if ($expectedEndTime === $actualEndTime) {
        echo "   ✅ Timestamp correct (= dernier left_at)\n";
    }

    echo "\n🎯 RÈGLE 2 VALIDÉE : Séance fermée car tous déconnectés 10+ min\n";
} else {
    echo "❌ ÉCHEC : La séance devrait être fermée\n\n";
    echo "   visio_active : " . ($seance->visio_active ? 'true' : 'false') . "\n";
    echo "   visio_ended_at : " . ($seance->visio_ended_at ?? 'NULL') . "\n";
}

echo "\n========================================\n\n";

// Nettoyer
Seance::where('id', $seance->id)->forceDelete();
