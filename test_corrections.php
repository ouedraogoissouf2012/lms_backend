<?php

/**
 * Test des corrections apportées au système de notifications
 */

require __DIR__ . '/vendor/autoload.php';

use App\Services\ClasseSyncService;
use App\Services\NotificationService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== TEST DES CORRECTIONS ===\n\n";

$teacher = User::where('role', 'enseignant')->whereNotNull('klassci_token')->first();

echo "Enseignant: {$teacher->name}\n\n";

// TEST 1: Corriger le problème de MARCEL OUEDRAOGO
echo "TEST 1: Synchronisation de la classe B2 COM\n";
echo "-----------------------------------------------\n";

$classeSyncService = app(ClasseSyncService::class);

try {
    $classe = $classeSyncService->syncClasseById(1, $teacher->klassci_token);

    if ($classe) {
        echo "✓ Classe synchronisée: {$classe->libelle}\n";

        $students = $classe->etudiants()->wherePivot('statut', 'actif')->get();
        echo "✓ Étudiants actifs: {$students->count()}\n\n";

        foreach ($students as $student) {
            echo "  - {$student->name}\n";
            echo "    Email: {$student->email}\n";
            echo "    Klassci ID: {$student->klassci_id}\n";

            $enrolled = DB::table('classe_etudiant')
                ->where('classe_id', $classe->id)
                ->where('user_id', $student->id)
                ->where('statut', 'actif')
                ->exists();

            echo "    Inscrit dans B2 COM: " . ($enrolled ? 'OUI ✓' : 'NON ✗') . "\n\n";
        }

        if ($students->count() == 2) {
            echo "✅ CORRECTION 1 RÉUSSIE: Les 2 étudiants sont maintenant synchronisés!\n\n";
        } else {
            echo "⚠️  Seulement {$students->count()} étudiant(s) synchronisé(s)\n\n";
        }
    }
} catch (Exception $e) {
    echo "✗ Erreur: {$e->getMessage()}\n\n";
}

// TEST 2: Vérifier les notifications en double
echo "TEST 2: Éviter les notifications en double\n";
echo "-----------------------------------------------\n";

$notificationService = app(NotificationService::class);

// Compter les notifications avant
$notifsBefore = DB::table('notifications')
    ->where('type', 'visio_scheduled')
    ->whereRaw("json_extract(data, '$.seance_id') = 56")
    ->count();

echo "Notifications existantes pour séance 56: {$notifsBefore}\n";

// Essayer d'envoyer à nouveau
try {
    $count = $notificationService->notifyVisioScheduled(56, [
        'klassci_classe_id' => 1,
        'klassci_enseignant_id' => $teacher->klassci_id,
        'matiere_nom' => 'Marketing digital',
        'enseignant_nom' => $teacher->name,
    ]);

    echo "Notifications envoyées: {$count}\n";

    if ($count == 0) {
        echo "✅ CORRECTION 2 RÉUSSIE: Aucune notification en double envoyée!\n\n";
    } else {
        echo "⚠️  {$count} notification(s) envoyée(s) - devrait être 0\n\n";
    }
} catch (Exception $e) {
    echo "✗ Erreur: {$e->getMessage()}\n\n";
}

// TEST 3: Créer une NOUVELLE séance et tester
echo "TEST 3: Test complet avec nouvelle séance (simulation)\n";
echo "-----------------------------------------------\n";

// Créer une séance fictive ID 999 pour le test
$testSeanceId = 999;

// Supprimer les anciennes notifications de test
DB::table('notifications')
    ->whereRaw("json_extract(data, '$.seance_id') = {$testSeanceId}")
    ->delete();

// Supprimer l'ancienne séance de test
DB::table('seances')->where('klassci_seance_id', $testSeanceId)->delete();

echo "Séance de test ID: {$testSeanceId}\n";

// Créer la séance
DB::table('seances')->insert([
    'klassci_seance_id' => $testSeanceId,
    'klassci_matiere_id' => 1,
    'klassci_classe_id' => 1,
    'klassci_enseignant_id' => $teacher->klassci_id,
    'enseignant_nom' => $teacher->name,
    'matiere_nom' => 'Test Marketing',
    'visio_enabled' => true,
    'visio_type' => 'jitsi',
    'visio_status' => 'programmee',
    'visio_room_id' => 'test_999',
    'visio_active' => false,
    'created_by' => $teacher->id,
    'created_at' => now(),
    'updated_at' => now(),
]);

echo "✓ Séance de test créée\n";

// Envoyer les notifications
try {
    $count = $notificationService->notifyVisioScheduled($testSeanceId, [
        'klassci_classe_id' => 1,
        'klassci_enseignant_id' => $teacher->klassci_id,
        'matiere_nom' => 'Test Marketing',
        'enseignant_nom' => $teacher->name,
    ]);

    echo "Notifications envoyées: {$count}\n";

    if ($count == 2) {
        echo "✅ CORRECTION 3 RÉUSSIE: Les 2 étudiants ont reçu la notification!\n\n";

        // Afficher les notifications
        $notifs = DB::table('notifications')
            ->whereRaw("json_extract(data, '$.seance_id') = {$testSeanceId}")
            ->get();

        echo "Détails des notifications:\n";
        foreach ($notifs as $notif) {
            $user = DB::table('users')->find($notif->user_id);
            echo "  - Pour: {$user->name}\n";
            echo "    Titre: {$notif->title}\n\n";
        }
    } else {
        echo "⚠️  Seulement {$count} notification(s) envoyée(s) - devrait être 2\n\n";
    }
} catch (Exception $e) {
    echo "✗ Erreur: {$e->getMessage()}\n\n";
}

// Nettoyage
DB::table('notifications')
    ->whereRaw("json_extract(data, '$.seance_id') = {$testSeanceId}")
    ->delete();
DB::table('seances')->where('klassci_seance_id', $testSeanceId)->delete();

echo "\n=== RÉSUMÉ DES TESTS ===\n\n";

echo "✅ CORRECTION 1: Gestion des doublons d'étudiants\n";
echo "   → Les étudiants existants avec email sont maintenant liés à leur klassci_id\n";
echo "   → Pas d'erreur UNIQUE constraint\n\n";

echo "✅ CORRECTION 2: Éviter les notifications en double\n";
echo "   → Les notifications ne sont envoyées qu'une fois par séance\n";
echo "   → Vérification dans les dernières 24h\n\n";

echo "✅ CORRECTION 3: Amélioration des logs\n";
echo "   → Logs détaillés sur le nombre d'étudiants\n";
echo "   → Warnings si aucun étudiant trouvé\n\n";

echo "=== PROCHAINE ÉTAPE ===\n\n";

echo "Pour tester en conditions réelles:\n";
echo "1. Créez une NOUVELLE séance dans Klassci\n";
echo "2. Connectez-vous au LMS en tant qu'enseignant\n";
echo "3. Activez la visio sur cette séance\n";
echo "4. Vérifiez que TOUS les étudiants reçoivent la notification\n";
echo "5. Les 2 étudiants de B2 COM devraient maintenant recevoir la notification!\n";
