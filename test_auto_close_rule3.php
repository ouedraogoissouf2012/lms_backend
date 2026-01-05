<?php

/**
 * TEST RÈGLE 3 : Aucun participant
 *
 * Scénario :
 * 1. Créer une séance active depuis 35 minutes
 * 2. Ne pas ajouter de participants
 * 3. Exécuter le job
 * 4. Vérifier que la séance est fermée (raison: no_participants)
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Seance;
use App\Models\ESBTPAttendance;
use App\Jobs\AutoCloseEmptySeances;
use Carbon\Carbon;

echo "\n========================================\n";
echo "TEST RÈGLE 3 : AUCUN PARTICIPANT\n";
echo "========================================\n\n";

// Nettoyer les tests précédents
Seance::where('klassci_seance_id', 99993)->forceDelete();

// 1. Créer une séance active depuis 35 minutes
$now = Carbon::now();
$seance = Seance::create([
    'klassci_seance_id' => 99993,
    'klassci_matiere_id' => 1,
    'klassci_classe_id' => 1,
    'klassci_enseignant_id' => 1,
    'visio_enabled' => true,
    'visio_active' => true,
    'visio_started_at' => $now->copy()->subMinutes(35),
    'visio_ended_at' => null,
    'is_active' => true,
]);

echo "✅ Séance créée (ID: {$seance->id})\n";
echo "   Démarrée il y a : 35 minutes\n";
echo "   Participants : 0\n\n";

// Vérifier qu'il n'y a vraiment aucun participant
$count = ESBTPAttendance::where('seance_id', $seance->id)->count();
echo "   Vérification : {$count} participants trouvés\n\n";

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "EXÉCUTION DU JOB AutoCloseEmptySeances\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// 2. Exécuter le job
$job = new AutoCloseEmptySeances();
$job->handle();

// 3. Vérifier le résultat
$seance->refresh();

echo "\n========================================\n";
echo "RÉSULTAT\n";
echo "========================================\n\n";

if ($seance->visio_active === false && $seance->visio_ended_at !== null) {
    echo "✅ SUCCÈS : Séance fermée\n\n";
    echo "   visio_active : false\n";
    echo "   visio_ended_at : {$seance->visio_ended_at}\n";
    echo "   Durée totale : " . $seance->visio_started_at->diffInMinutes($seance->visio_ended_at) . " minutes\n\n";

    echo "🎯 RÈGLE 3 VALIDÉE : Séance fermée car aucun participant après 30+ min\n";
} else {
    echo "❌ ÉCHEC : La séance devrait être fermée\n\n";
    echo "   visio_active : " . ($seance->visio_active ? 'true' : 'false') . "\n";
    echo "   visio_ended_at : " . ($seance->visio_ended_at ?? 'NULL') . "\n";
}

echo "\n========================================\n\n";

// Test négatif : séance active depuis seulement 20 minutes (ne doit PAS fermer)
echo "TEST NÉGATIF : Séance active 20 min (ne doit PAS fermer)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

Seance::where('klassci_seance_id', 99993)->forceDelete();

$seance2 = Seance::create([
    'klassci_seance_id' => 99993,
    'klassci_matiere_id' => 1,
    'klassci_classe_id' => 1,
    'klassci_enseignant_id' => 1,
    'visio_enabled' => true,
    'visio_active' => true,
    'visio_started_at' => $now->copy()->subMinutes(20), // Seulement 20 minutes
    'visio_ended_at' => null,
    'is_active' => true,
]);

echo "✅ Séance créée (ID: {$seance2->id})\n";
echo "   Démarrée il y a : 20 minutes (< seuil de 30 min)\n\n";

$job2 = new AutoCloseEmptySeances();
$job2->handle();

$seance2->refresh();

if ($seance2->visio_active === true && $seance2->visio_ended_at === null) {
    echo "✅ SUCCÈS : Séance reste ouverte (normal, < 30 min)\n";
} else {
    echo "❌ ÉCHEC : La séance ne devrait PAS être fermée\n";
}

echo "\n========================================\n\n";

// Nettoyer
Seance::where('id', $seance2->id)->forceDelete();
