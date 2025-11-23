<?php

/**
 * Script de test pour vérifier le système de notifications de séances
 *
 * Ce script teste:
 * 1. La création de notifications lorsqu'une visio est programmée (activateVisio)
 * 2. La création de notifications lorsqu'une visio démarre (startVisio)
 * 3. Que les étudiants de la classe reçoivent bien les notifications
 * 4. Que l'enseignant reçoit aussi la notification de démarrage
 */

require __DIR__ . '/vendor/autoload.php';

use App\Services\NotificationService;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Test du système de notifications pour les séances ===\n\n";

// 1. Vérifier que la table notifications existe
echo "1. Vérification de la table notifications...\n";
try {
    $notificationsCount = DB::table('notifications')->count();
    echo "   ✓ Table notifications existe ({$notificationsCount} notifications)\n\n";
} catch (\Exception $e) {
    echo "   ✗ Erreur: {$e->getMessage()}\n\n";
    exit(1);
}

// 2. Vérifier les types de notifications supportés
echo "2. Vérification des types de notifications...\n";
echo "   - TYPE_VISIO_SCHEDULED: " . Notification::TYPE_VISIO_SCHEDULED . "\n";
echo "   - TYPE_VISIO_STARTING: " . Notification::TYPE_VISIO_STARTING . "\n\n";

// 3. Trouver une séance réelle avec visio activée
echo "3. Recherche d'une séance avec visio activée...\n";
$seance = DB::table('seances')
    ->where('visio_enabled', true)
    ->orderBy('id', 'desc')
    ->first();

if (!$seance) {
    echo "   ⚠ Aucune séance avec visio trouvée\n";
    echo "   Création d'une séance de test...\n";

    // Créer une séance de test
    $seanceId = DB::table('seances')->insertGetId([
        'klassci_seance_id' => 9999,
        'klassci_matiere_id' => 1,
        'klassci_classe_id' => 1,
        'klassci_enseignant_id' => 1,
        'enseignant_nom' => 'Prof Test',
        'matiere_nom' => 'Mathématiques',
        'visio_enabled' => true,
        'visio_type' => 'jitsi',
        'visio_status' => 'programmee',
        'visio_room_id' => 'test_room_' . time(),
        'visio_active' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $seance = DB::table('seances')->where('id', $seanceId)->first();
    echo "   ✓ Séance de test créée (ID: {$seance->id})\n";
} else {
    echo "   ✓ Séance trouvée (ID: {$seance->id}, Matière: {$seance->matiere_nom})\n";
}

echo "\n   Détails de la séance:\n";
echo "   - klassci_seance_id: {$seance->klassci_seance_id}\n";
echo "   - klassci_classe_id: {$seance->klassci_classe_id}\n";
echo "   - klassci_enseignant_id: {$seance->klassci_enseignant_id}\n";
echo "   - matiere_nom: {$seance->matiere_nom}\n";
echo "   - enseignant_nom: {$seance->enseignant_nom}\n\n";

// 4. Trouver les étudiants de la classe
echo "4. Recherche des étudiants de la classe {$seance->klassci_classe_id}...\n";

// Trouver la classe locale
$classe = DB::table('classes')->where('klassci_id', $seance->klassci_classe_id)->first();

if (!$classe) {
    echo "   ⚠ Classe locale non trouvée (klassci_id: {$seance->klassci_classe_id})\n";
    echo "   Les notifications ne pourront pas être envoyées\n\n";
    $students = collect();
} else {
    echo "   ✓ Classe locale trouvée (ID: {$classe->id}, Nom: {$classe->nom})\n";

    $students = DB::table('users')
        ->join('classe_etudiant', 'users.id', '=', 'classe_etudiant.user_id')
        ->where('classe_etudiant.classe_id', $classe->id)
        ->where('classe_etudiant.statut', 'actif')
        ->where('users.role', 'etudiant')
        ->select('users.*')
        ->get();

    echo "   ✓ {$students->count()} étudiant(s) trouvé(s)\n";
    foreach ($students as $student) {
        echo "     - {$student->name} (ID: {$student->id})\n";
    }
}
echo "\n";

// 5. Trouver l'enseignant
echo "5. Recherche de l'enseignant...\n";
$teacher = DB::table('users')
    ->where('klassci_id', $seance->klassci_enseignant_id)
    ->where('role', 'enseignant')
    ->first();

if ($teacher) {
    echo "   ✓ Enseignant trouvé: {$teacher->name} (ID: {$teacher->id})\n\n";
} else {
    echo "   ⚠ Enseignant non trouvé (klassci_id: {$seance->klassci_enseignant_id})\n\n";
}

// 6. Compter les notifications existantes avant le test
echo "6. Notifications existantes AVANT le test...\n";
$notifsBefore = DB::table('notifications')
    ->where('type', Notification::TYPE_VISIO_SCHEDULED)
    ->orWhere('type', Notification::TYPE_VISIO_STARTING)
    ->count();
echo "   - Total notifications visio: {$notifsBefore}\n\n";

// 7. Tester notifyVisioScheduled
echo "7. Test notifyVisioScheduled()...\n";
try {
    $notificationService = app(NotificationService::class);

    $count = $notificationService->notifyVisioScheduled($seance->klassci_seance_id, [
        'klassci_classe_id' => $seance->klassci_classe_id,
        'klassci_enseignant_id' => $seance->klassci_enseignant_id,
        'matiere_nom' => $seance->matiere_nom,
        'enseignant_nom' => $seance->enseignant_nom,
    ]);

    echo "   ✓ {$count} notification(s) créée(s)\n\n";

    // Vérifier les notifications créées
    $newNotifs = DB::table('notifications')
        ->where('type', Notification::TYPE_VISIO_SCHEDULED)
        ->orderBy('id', 'desc')
        ->limit($count)
        ->get();

    echo "   Détails des notifications créées:\n";
    foreach ($newNotifs as $notif) {
        $user = DB::table('users')->where('id', $notif->user_id)->first();
        echo "     - Pour: {$user->name} (ID: {$notif->user_id})\n";
        echo "       Titre: {$notif->title}\n";
        echo "       Message: {$notif->message}\n";
        echo "       Lue: " . ($notif->read_at ? 'Oui' : 'Non') . "\n\n";
    }

} catch (\Exception $e) {
    echo "   ✗ Erreur: {$e->getMessage()}\n";
    echo "   Stack trace: {$e->getTraceAsString()}\n\n";
}

// 8. Tester notifyVisioStarting
echo "8. Test notifyVisioStarting()...\n";
try {
    $count = $notificationService->notifyVisioStarting($seance->klassci_seance_id, [
        'klassci_classe_id' => $seance->klassci_classe_id,
        'klassci_enseignant_id' => $seance->klassci_enseignant_id,
        'matiere_nom' => $seance->matiere_nom,
        'enseignant_nom' => $seance->enseignant_nom,
    ]);

    echo "   ✓ {$count} notification(s) créée(s)\n\n";

    // Vérifier les notifications créées
    $newNotifs = DB::table('notifications')
        ->where('type', Notification::TYPE_VISIO_STARTING)
        ->orderBy('id', 'desc')
        ->limit($count)
        ->get();

    echo "   Détails des notifications créées:\n";
    foreach ($newNotifs as $notif) {
        $user = DB::table('users')->where('id', $notif->user_id)->first();
        echo "     - Pour: {$user->name} (ID: {$notif->user_id})\n";
        echo "       Titre: {$notif->title}\n";
        echo "       Message: {$notif->message}\n";
        echo "       Lue: " . ($notif->read_at ? 'Oui' : 'Non') . "\n\n";
    }

} catch (\Exception $e) {
    echo "   ✗ Erreur: {$e->getMessage()}\n";
    echo "   Stack trace: {$e->getTraceAsString()}\n\n";
}

// 9. Statistiques finales
echo "9. Statistiques finales...\n";
$notifsAfter = DB::table('notifications')
    ->where('type', Notification::TYPE_VISIO_SCHEDULED)
    ->orWhere('type', Notification::TYPE_VISIO_STARTING)
    ->count();
echo "   - Total notifications visio APRÈS: {$notifsAfter}\n";
echo "   - Nouvelles notifications créées: " . ($notifsAfter - $notifsBefore) . "\n\n";

echo "=== Test terminé ===\n";
