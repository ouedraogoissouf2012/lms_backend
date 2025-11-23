<?php

/**
 * Script de test complet pour vérifier:
 * 1. La synchronisation des classes depuis Klassci
 * 2. La synchronisation des étudiants
 * 3. Le système de notifications pour les séances
 */

require __DIR__ . '/vendor/autoload.php';

use App\Services\ClasseSyncService;
use App\Services\NotificationService;
use App\Models\Notification;
use App\Models\Classe;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Test complet: Synchronisation + Notifications ===\n\n";

// 1. Trouver un enseignant avec token Klassci
echo "1. Recherche d'un enseignant avec token Klassci...\n";
$teacher = User::where('role', 'enseignant')
    ->whereNotNull('klassci_token')
    ->whereNotNull('klassci_id')
    ->first();

if (!$teacher) {
    echo "   ✗ Aucun enseignant trouvé avec token Klassci\n";
    echo "   Impossible de tester la synchronisation\n";
    exit(1);
}

echo "   ✓ Enseignant trouvé: {$teacher->name} (ID: {$teacher->id})\n";
echo "   - Klassci ID: {$teacher->klassci_id}\n";
echo "   - Token présent: " . (strlen($teacher->klassci_token) > 10 ? 'Oui' : 'Non') . "\n\n";

// 2. État AVANT synchronisation
echo "2. État AVANT synchronisation...\n";
$classesBefore = Classe::count();
$enrollmentsBefore = DB::table('classe_etudiant')->count();
$studentsBefore = User::where('role', 'etudiant')->count();

echo "   - Classes: {$classesBefore}\n";
echo "   - Inscriptions: {$enrollmentsBefore}\n";
echo "   - Étudiants: {$studentsBefore}\n\n";

// 3. Tester ClasseSyncService
echo "3. Test de ClasseSyncService...\n";
try {
    $classeSyncService = app(ClasseSyncService::class);

    echo "   Lancement de la synchronisation...\n";
    $stats = $classeSyncService->syncUserClasses($teacher->klassci_token, $teacher->role);

    echo "\n   ✓ Synchronisation terminée!\n";
    echo "   - Classes créées: {$stats['classes_created']}\n";
    echo "   - Classes mises à jour: {$stats['classes_updated']}\n";
    echo "   - Étudiants synchronisés: {$stats['students_synced']}\n";
    echo "   - Inscriptions créées: {$stats['enrollments_created']}\n";

    if (!empty($stats['errors'])) {
        echo "   ⚠ Erreurs:\n";
        foreach ($stats['errors'] as $error) {
            echo "     - {$error}\n";
        }
    }
    echo "\n";

} catch (\Exception $e) {
    echo "   ✗ Erreur: {$e->getMessage()}\n";
    echo "   Stack: {$e->getTraceAsString()}\n\n";
    exit(1);
}

// 4. État APRÈS synchronisation
echo "4. État APRÈS synchronisation...\n";
$classesAfter = Classe::count();
$enrollmentsAfter = DB::table('classe_etudiant')->count();
$studentsAfter = User::where('role', 'etudiant')->count();

echo "   - Classes: {$classesAfter} (+" . ($classesAfter - $classesBefore) . ")\n";
echo "   - Inscriptions: {$enrollmentsAfter} (+" . ($enrollmentsAfter - $enrollmentsBefore) . ")\n";
echo "   - Étudiants: {$studentsAfter} (+" . ($studentsAfter - $studentsBefore) . ")\n\n";

// 5. Afficher les classes synchronisées
echo "5. Classes synchronisées:\n";
$classes = Classe::with(['etudiants' => function($q) {
    $q->where('classe_etudiant.statut', 'actif');
}])->get();

foreach ($classes as $classe) {
    echo "   - {$classe->nom} (Klassci ID: {$classe->klassci_id})\n";
    echo "     Étudiants actifs: {$classe->etudiants->count()}\n";
}
echo "\n";

// 6. Tester les notifications avec une classe réelle
echo "6. Test des notifications avec classe synchronisée...\n";

// Trouver une séance avec visio
$seance = DB::table('seances')
    ->where('visio_enabled', true)
    ->whereNotNull('klassci_classe_id')
    ->first();

if (!$seance) {
    echo "   ⚠ Aucune séance avec visio trouvée\n";
    echo "   Fin du test\n";
    exit(0);
}

echo "   Séance trouvée: ID {$seance->id}, Matière: {$seance->matiere_nom}\n";
echo "   Klassci classe ID: {$seance->klassci_classe_id}\n\n";

// Vérifier que la classe existe localement maintenant
$classe = Classe::where('klassci_id', $seance->klassci_classe_id)->first();

if (!$classe) {
    echo "   ⚠ Classe non trouvée localement (klassci_id: {$seance->klassci_classe_id})\n";
    echo "   La synchronisation n'a pas importé cette classe\n\n";
} else {
    echo "   ✓ Classe trouvée: {$classe->nom} (ID local: {$classe->id})\n";

    // Compter les étudiants
    $studentsCount = $classe->etudiants()->where('classe_etudiant.statut', 'actif')->count();
    echo "   - Étudiants actifs: {$studentsCount}\n\n";

    // Tester l'envoi de notifications
    echo "7. Test d'envoi de notifications...\n";

    $notificationService = app(NotificationService::class);

    // Test notification programmée
    echo "   a) Notification 'Visio programmée'...\n";
    try {
        $count = $notificationService->notifyVisioScheduled($seance->klassci_seance_id, [
            'klassci_classe_id' => $seance->klassci_classe_id,
            'klassci_enseignant_id' => $seance->klassci_enseignant_id,
            'matiere_nom' => $seance->matiere_nom,
            'enseignant_nom' => $seance->enseignant_nom,
        ]);

        echo "      ✓ {$count} notification(s) envoyée(s)\n";
    } catch (\Exception $e) {
        echo "      ✗ Erreur: {$e->getMessage()}\n";
    }

    // Test notification démarrée
    echo "   b) Notification 'Visio démarrée'...\n";
    try {
        $count = $notificationService->notifyVisioStarting($seance->klassci_seance_id, [
            'klassci_classe_id' => $seance->klassci_classe_id,
            'klassci_enseignant_id' => $seance->klassci_enseignant_id,
            'matiere_nom' => $seance->matiere_nom,
            'enseignant_nom' => $seance->enseignant_nom,
        ]);

        echo "      ✓ {$count} notification(s) envoyée(s)\n";
    } catch (\Exception $e) {
        echo "      ✗ Erreur: {$e->getMessage()}\n";
    }
    echo "\n";

    // 8. Afficher les dernières notifications créées
    echo "8. Dernières notifications créées:\n";
    $notifications = Notification::whereIn('type', [
            Notification::TYPE_VISIO_SCHEDULED,
            Notification::TYPE_VISIO_STARTING
        ])
        ->with('user')
        ->orderBy('id', 'desc')
        ->limit(10)
        ->get();

    foreach ($notifications as $notif) {
        echo "   - Pour: {$notif->user->name} ({$notif->user->role})\n";
        echo "     Type: {$notif->type}\n";
        echo "     Titre: {$notif->title}\n";
        echo "     Message: {$notif->message}\n";
        echo "     Créée le: {$notif->created_at}\n\n";
    }
}

echo "=== Test terminé avec succès! ===\n";
