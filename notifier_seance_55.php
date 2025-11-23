<?php

/**
 * Notifier manuellement la séance 55
 */

require __DIR__ . '/vendor/autoload.php';

use App\Services\ClasseSyncService;
use App\Services\NotificationService;
use App\Models\User;
use App\Models\Seance;
use Illuminate\Support\Facades\DB;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Notification Séance 55 ===\n\n";

$teacher = User::where('role', 'enseignant')->whereNotNull('klassci_token')->first();

echo "Enseignant: {$teacher->name}\n\n";

// 1. Synchroniser la classe
echo "1. Synchronisation de la classe B2 COM...\n";
$classeSyncService = app(ClasseSyncService::class);

try {
    $classe = $classeSyncService->syncClasseById(1, $teacher->klassci_token);

    if ($classe) {
        echo "   ✓ Classe: {$classe->libelle}\n";

        $studentsCount = $classe->etudiants()->wherePivot('statut', 'actif')->count();
        echo "   ✓ Étudiants actifs: {$studentsCount}\n\n";

        // Afficher les étudiants
        $students = $classe->etudiants()->wherePivot('statut', 'actif')->get();
        foreach ($students as $student) {
            echo "      - {$student->name} ({$student->email})\n";
        }
        echo "\n";
    }
} catch (Exception $e) {
    echo "   ✗ Erreur: {$e->getMessage()}\n\n";
    exit(1);
}

// 2. Créer/Mettre à jour la séance 55
echo "2. Création de la séance 55...\n";

$seance = Seance::updateOrCreate(
    ['klassci_seance_id' => 55],
    [
        'klassci_matiere_id' => 1,
        'klassci_classe_id' => 1,
        'klassci_enseignant_id' => $teacher->klassci_id,
        'enseignant_nom' => $teacher->name,
        'matiere_nom' => 'Marketing digital',
        'visio_enabled' => true,
        'visio_type' => 'jitsi',
        'visio_status' => 'programmee',
        'visio_room_id' => 'lms_seance_55_' . time(),
        'visio_active' => false,
        'created_by' => $teacher->id,
    ]
);

echo "   ✓ Séance créée (ID local: {$seance->id})\n";
echo "   Room: {$seance->visio_room_id}\n\n";

// 3. Envoyer les notifications
echo "3. Envoi des notifications...\n";

$notificationService = app(NotificationService::class);

$count = $notificationService->notifyVisioScheduled(55, [
    'klassci_classe_id' => 1,
    'klassci_enseignant_id' => $teacher->klassci_id,
    'matiere_nom' => 'Marketing digital',
    'enseignant_nom' => $teacher->name,
]);

echo "   ✓ {$count} notification(s) envoyée(s)\n\n";

// 4. Afficher les notifications
echo "4. Dernières notifications:\n";

$notifications = DB::table('notifications')
    ->whereIn('type', ['visio_scheduled', 'visio_starting'])
    ->orderBy('id', 'desc')
    ->limit(3)
    ->get();

foreach ($notifications as $notif) {
    $user = DB::table('users')->find($notif->user_id);
    echo "   - Pour: " . ($user ? $user->name : "User {$notif->user_id}") . " ({$user->role})\n";
    echo "     [{$notif->type}] {$notif->title}\n";
    echo "     {$notif->message}\n";
    echo "     Créée: {$notif->created_at}\n\n";
}

echo "=== Terminé ===\n\n";

echo "La notification a été envoyée pour la séance 55!\n";
echo "Les étudiants peuvent maintenant la voir dans leur interface.\n";
