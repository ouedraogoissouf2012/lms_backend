<?php

/**
 * Test manuel de notification pour la séance 54
 * Simule la détection et l'envoi de notifications
 */

require __DIR__ . '/vendor/autoload.php';

use App\Services\ClasseSyncService;
use App\Services\NotificationService;
use App\Models\User;
use App\Models\Seance;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Test Manuel Notification Séance 54 ===\n\n";

$teacher = User::where('role', 'enseignant')->whereNotNull('klassci_token')->first();

if (!$teacher) {
    echo "Aucun enseignant trouvé\n";
    exit(1);
}

echo "Enseignant: {$teacher->name}\n\n";

// 1. Vérifier si la séance existe déjà localement
$existingSeance = Seance::where('klassci_seance_id', 54)->first();

if ($existingSeance) {
    echo "⚠ La séance 54 existe déjà localement (ID: {$existingSeance->id})\n";
    echo "Visio enabled: " . ($existingSeance->visio_enabled ? 'OUI' : 'NON') . "\n\n";

    if (!$existingSeance->visio_enabled) {
        echo "Voulez-vous activer la visio sur cette séance? (la détection auto ne se déclenchera pas car elle existe déjà)\n";
    }
} else {
    echo "✓ La séance 54 n'existe pas localement\n\n";
}

// 2. Synchroniser la classe B2 COM (ID Klassci: 1)
echo "1. Synchronisation de la classe B2 COM...\n";

$classeSyncService = app(ClasseSyncService::class);

try {
    $classe = $classeSyncService->syncClasseById(1, $teacher->klassci_token);

    if ($classe) {
        echo "   ✓ Classe synchronisée: {$classe->libelle} (ID local: {$classe->id})\n";

        $studentsCount = DB::table('classe_etudiant')
            ->where('classe_id', $classe->id)
            ->where('statut', 'actif')
            ->count();

        echo "   ✓ Étudiants actifs: {$studentsCount}\n\n";
    } else {
        echo "   ✗ Échec de synchronisation de la classe\n\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "   ✗ Erreur: {$e->getMessage()}\n\n";
    exit(1);
}

// 3. Créer ou mettre à jour la séance localement
echo "2. Création/Mise à jour de la séance locale...\n";

try {
    $seanceData = [
        'klassci_seance_id' => 54,
        'klassci_matiere_id' => 1,
        'klassci_classe_id' => 1,
        'klassci_enseignant_id' => $teacher->klassci_id,
        'enseignant_nom' => $teacher->name,
        'matiere_nom' => 'Marketing digital',
        'visio_enabled' => true,
        'visio_type' => 'jitsi',
        'visio_status' => 'programmee',
        'visio_room_id' => 'lms_seance_54_' . time(),
        'visio_active' => false,
        'created_by' => $teacher->id,
    ];

    $seance = Seance::updateOrCreate(
        ['klassci_seance_id' => 54],
        $seanceData
    );

    echo "   ✓ Séance créée/mise à jour (ID local: {$seance->id})\n";
    echo "   Visio enabled: " . ($seance->visio_enabled ? 'OUI' : 'NON') . "\n";
    echo "   Room ID: {$seance->visio_room_id}\n\n";

} catch (Exception $e) {
    echo "   ✗ Erreur: {$e->getMessage()}\n\n";
    exit(1);
}

// 4. Envoyer les notifications
echo "3. Envoi des notifications...\n";

$notificationService = app(NotificationService::class);

try {
    $count = $notificationService->notifyVisioScheduled(54, [
        'klassci_classe_id' => 1,
        'klassci_enseignant_id' => $teacher->klassci_id,
        'matiere_nom' => 'Marketing digital',
        'enseignant_nom' => $teacher->name,
    ]);

    echo "   ✓ {$count} notification(s) envoyée(s)\n\n";

    if ($count === 0) {
        echo "   ⚠ Aucune notification envoyée!\n";
        echo "   Raisons possibles:\n";
        echo "   - Aucun étudiant actif dans la classe\n";
        echo "   - Classe non synchronisée\n";
        echo "   - Erreur dans NotificationService\n\n";
    }

} catch (Exception $e) {
    echo "   ✗ Erreur: {$e->getMessage()}\n\n";
}

// 5. Vérifier les notifications créées
echo "4. Notifications créées:\n";

$notifications = Notification::whereIn('type', [
        Notification::TYPE_VISIO_SCHEDULED,
        Notification::TYPE_VISIO_STARTING
    ])
    ->with('user')
    ->orderBy('id', 'desc')
    ->limit(5)
    ->get();

foreach ($notifications as $notif) {
    echo "   - [{$notif->type}] Pour: {$notif->user->name} ({$notif->user->role})\n";
    echo "     Titre: {$notif->title}\n";
    echo "     Créée: {$notif->created_at->diffForHumans()}\n\n";
}

echo "=== Test terminé ===\n\n";

echo "IMPORTANT:\n";
echo "Pour que la détection automatique fonctionne à l'avenir:\n";
echo "1. Dans Klassci, lors de la création d'une séance\n";
echo "2. Cochez/Activez l'option 'Visioconférence'\n";
echo "3. L'API Klassci doit retourner 'visio_enabled: true'\n";
echo "4. Ensuite la détection auto fonctionnera quand vous chargerez la page des séances\n";
