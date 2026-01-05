<?php

/**
 * TEST RÈGLE 1 : Enseignant déconnecté
 *
 * Scénario :
 * 1. Créer une séance active
 * 2. Ajouter un enseignant qui a rejoint puis quitté il y a 6 minutes
 * 3. Ajouter 2 étudiants connectés
 * 4. Exécuter le job
 * 5. Vérifier que la séance est fermée (raison: teacher_disconnected)
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
echo "TEST RÈGLE 1 : ENSEIGNANT DÉCONNECTÉ\n";
echo "========================================\n\n";

// Nettoyer les tests précédents
Seance::where('klassci_seance_id', 99991)->forceDelete();

// 1. Créer une séance active
$now = Carbon::now();
$seance = Seance::create([
    'klassci_seance_id' => 99991,
    'klassci_matiere_id' => 1,
    'klassci_classe_id' => 1,
    'klassci_enseignant_id' => 1,
    'visio_enabled' => true,
    'visio_active' => true,
    'visio_started_at' => $now->copy()->subMinutes(15),
    'visio_ended_at' => null,
    'is_active' => true,
]);

echo "✅ Séance créée (ID: {$seance->id})\n";
echo "   Démarrée il y a 15 minutes\n\n";

// 2. Trouver un enseignant
$enseignant = User::where('role', 'enseignant')->first();
if (!$enseignant) {
    echo "❌ Aucun enseignant trouvé dans la base\n";
    exit(1);
}

// 3. Créer participation enseignant (déconnecté il y a 6 minutes)
$leftAt = $now->copy()->subMinutes(6);
$attendanceEnseignant = ESBTPAttendance::create([
    'seance_id' => $seance->id,
    'user_id' => $enseignant->id,
    'joined_at' => $now->copy()->subMinutes(12),
    'left_at' => $leftAt,
    'last_seen_at' => $leftAt,
    'status' => 'disconnected',
    'duration_minutes' => 6,
    'is_observer' => false,
]);

echo "✅ Enseignant ajouté : {$enseignant->name}\n";
echo "   Quitté il y a : 6 minutes\n";
echo "   Status : disconnected\n\n";

// 4. Ajouter 2 étudiants connectés
$etudiants = User::where('role', 'etudiant')->take(2)->get();
foreach ($etudiants as $etudiant) {
    ESBTPAttendance::create([
        'seance_id' => $seance->id,
        'user_id' => $etudiant->id,
        'joined_at' => $now->copy()->subMinutes(10),
        'last_seen_at' => $now->copy()->subSeconds(30), // Heartbeat récent
        'status' => 'connected',
        'is_observer' => false,
    ]);

    echo "✅ Étudiant ajouté : {$etudiant->name} (connecté)\n";
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "EXÉCUTION DU JOB AutoCloseEmptySeances\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// 5. Exécuter le job
$job = new AutoCloseEmptySeances();
$job->handle();

// 6. Vérifier le résultat
$seance->refresh();

echo "\n========================================\n";
echo "RÉSULTAT\n";
echo "========================================\n\n";

if ($seance->visio_active === false && $seance->visio_ended_at !== null) {
    echo "✅ SUCCÈS : Séance fermée\n\n";
    echo "   visio_active : false\n";
    echo "   visio_ended_at : {$seance->visio_ended_at}\n";
    echo "   Durée totale : " . $seance->visio_started_at->diffInMinutes($seance->visio_ended_at) . " minutes\n\n";

    // Vérifier que les étudiants ont été déconnectés
    $attendances = ESBTPAttendance::where('seance_id', $seance->id)->get();
    echo "État des participants :\n";
    foreach ($attendances as $att) {
        $user = User::find($att->user_id);
        echo "   - {$user->name} ({$user->role}) : {$att->status}\n";
        if ($att->status === 'disconnected' && $att->left_at) {
            echo "     Quitté : {$att->left_at}\n";
            echo "     Durée : {$att->duration_minutes} min\n";
        }
    }

    echo "\n🎯 RÈGLE 1 VALIDÉE : Séance fermée car enseignant déconnecté 5+ min\n";
} else {
    echo "❌ ÉCHEC : La séance devrait être fermée\n\n";
    echo "   visio_active : " . ($seance->visio_active ? 'true' : 'false') . "\n";
    echo "   visio_ended_at : " . ($seance->visio_ended_at ?? 'NULL') . "\n";
}

echo "\n========================================\n\n";

// Nettoyer
Seance::where('id', $seance->id)->forceDelete();
