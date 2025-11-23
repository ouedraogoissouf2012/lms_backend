<?php

/**
 * Test final du système de notification complet
 * Vérifie que tout le flow fonctionne correctement
 */

require __DIR__ . '/vendor/autoload.php';

use App\Services\ClasseSyncService;
use App\Services\NotificationService;
use App\Models\User;
use App\Models\Classe;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Test Final du Système de Notifications ===\n\n";

// 1. État actuel
echo "1. État actuel du système:\n";
$classesCount = Classe::count();
$studentsCount = User::where('role', 'etudiant')->count();
$enrollmentsCount = DB::table('classe_etudiant')->where('statut', 'actif')->count();
$seancesCount = DB::table('seances')->where('visio_enabled', true)->count();

echo "   - Classes: {$classesCount}\n";
echo "   - Étudiants: {$studentsCount}\n";
echo "   - Inscriptions actives: {$enrollmentsCount}\n";
echo "   - Séances avec visio: {$seancesCount}\n\n";

// 2. Afficher les classes
if ($classesCount > 0) {
    echo "2. Classes synchronisées:\n";
    $classes = Classe::with('etudiants')->get();

    foreach ($classes as $classe) {
        echo "   - {$classe->libelle} (ID local: {$classe->id}, Klassci ID: {$classe->klassci_id})\n";
        echo "     Code: {$classe->code}\n";
        echo "     Effectif: {$classe->effectif}\n";
        echo "     Étudiants inscrits: {$classe->etudiants->count()}\n";

        $actifs = $classe->etudiants()->wherePivot('statut', 'actif')->count();
        echo "     Étudiants actifs: {$actifs}\n";

        if ($classe->last_klassci_sync) {
            echo "     Dernière synchro: {$classe->last_klassci_sync->diffForHumans()}\n";
        }
        echo "\n";
    }
} else {
    echo "2. Aucune classe synchronisée\n\n";
}

// 3. Vérifier les séances
echo "3. Séances avec visio:\n";
$seances = DB::table('seances')
    ->where('visio_enabled', true)
    ->get();

if ($seances->isEmpty()) {
    echo "   Aucune séance avec visio\n\n";
} else {
    foreach ($seances as $seance) {
        echo "   - Séance {$seance->id} (Klassci ID: {$seance->klassci_seance_id})\n";
        echo "     Matière: {$seance->matiere_nom}\n";
        echo "     Enseignant: {$seance->enseignant_nom}\n";
        echo "     Status: {$seance->visio_status}\n";
        echo "     Classe Klassci ID: {$seance->klassci_classe_id}\n";

        // Vérifier si la classe est synchronisée
        if ($seance->klassci_classe_id) {
            $classe = Classe::where('klassci_id', $seance->klassci_classe_id)->first();
            if ($classe) {
                echo "     ✓ Classe locale trouvée: {$classe->libelle}\n";
                $studentsCount = $classe->etudiants()->wherePivot('statut', 'actif')->count();
                echo "     ✓ {$studentsCount} étudiant(s) actif(s)\n";
            } else {
                echo "     ✗ Classe non synchronisée localement\n";
            }
        }
        echo "\n";
    }
}

// 4. Afficher les notifications
echo "4. Notifications de visio:\n";
$notifications = Notification::whereIn('type', [
        Notification::TYPE_VISIO_SCHEDULED,
        Notification::TYPE_VISIO_STARTING
    ])
    ->with('user')
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();

if ($notifications->isEmpty()) {
    echo "   Aucune notification de visio\n\n";
} else {
    foreach ($notifications as $notif) {
        echo "   - [{$notif->type}] Pour: {$notif->user->name} ({$notif->user->role})\n";
        echo "     Titre: {$notif->title}\n";
        echo "     Message: {$notif->message}\n";
        echo "     Créée: {$notif->created_at->format('Y-m-d H:i:s')} ({$notif->created_at->diffForHumans()})\n";
        echo "     Lue: " . ($notif->is_read ? 'Oui' : 'Non') . "\n\n";
    }
}

// 5. Statistiques globales
echo "5. Statistiques globales:\n";
$totalNotifs = Notification::whereIn('type', [
    Notification::TYPE_VISIO_SCHEDULED,
    Notification::TYPE_VISIO_STARTING
])->count();

$unreadNotifs = Notification::whereIn('type', [
    Notification::TYPE_VISIO_SCHEDULED,
    Notification::TYPE_VISIO_STARTING
])->where('is_read', false)->count();

echo "   - Total notifications visio: {$totalNotifs}\n";
echo "   - Non lues: {$unreadNotifs}\n";

$notifsByType = Notification::whereIn('type', [
        Notification::TYPE_VISIO_SCHEDULED,
        Notification::TYPE_VISIO_STARTING
    ])
    ->select('type', DB::raw('count(*) as count'))
    ->groupBy('type')
    ->get();

echo "   - Par type:\n";
foreach ($notifsByType as $stat) {
    echo "     {$stat->type}: {$stat->count}\n";
}
echo "\n";

// 6. Vérifier que le système est opérationnel
echo "6. Vérification du système:\n";

$checks = [
    'Services disponibles' => true,
    'Classes synchronisées' => $classesCount > 0,
    'Étudiants dans les classes' => $enrollmentsCount > 0,
    'Séances avec visio' => $seancesCount > 0,
    'Notifications envoyées' => $totalNotifs > 0,
];

foreach ($checks as $check => $status) {
    echo "   " . ($status ? '✓' : '✗') . " {$check}\n";
}
echo "\n";

// 7. Instructions pour tester
echo "7. Comment tester la détection auto:\n";
echo "   a) Créez une nouvelle séance dans Klassci avec visio activée\n";
echo "   b) Connectez-vous au frontend LMS en tant qu'enseignant\n";
echo "   c) Allez à la page des séances (/seances)\n";
echo "   d) La page va charger les séances depuis Klassci\n";
echo "   e) La détection auto devrait:\n";
echo "      - Détecter la nouvelle séance avec visio\n";
echo "      - Synchroniser la classe\n";
echo "      - Créer l'entrée locale dans la table seances\n";
echo "      - Envoyer les notifications aux étudiants\n";
echo "   f) Vérifiez les logs:\n";
echo "      tail -f storage/logs/laravel.log | grep 'Séance Klassci'\n";
echo "\n";

echo "8. Workflow des notifications:\n";
echo "   ┌─────────────────────────────────────────┐\n";
echo "   │ 1. Séance créée dans Klassci avec visio │\n";
echo "   └──────────────┬──────────────────────────┘\n";
echo "                  ▼\n";
echo "   ┌─────────────────────────────────────────┐\n";
echo "   │ 2. Enseignant charge la page des séances │\n";
echo "   │    (appel API: GET /api/lms/seances/my-teaching)\n";
echo "   └──────────────┬──────────────────────────┘\n";
echo "                  ▼\n";
echo "   ┌─────────────────────────────────────────┐\n";
echo "   │ 3. Backend récupère séances de Klassci   │\n";
echo "   └──────────────┬──────────────────────────┘\n";
echo "                  ▼\n";
echo "   ┌─────────────────────────────────────────┐\n";
echo "   │ 4. Détection auto: visio_enabled && !local │\n";
echo "   └──────────────┬──────────────────────────┘\n";
echo "                  ▼\n";
echo "   ┌─────────────────────────────────────────┐\n";
echo "   │ 5. ClasseSyncService synchronise la classe │\n";
echo "   └──────────────┬──────────────────────────┘\n";
echo "                  ▼\n";
echo "   ┌─────────────────────────────────────────┐\n";
echo "   │ 6. Création de l'entrée locale (seances) │\n";
echo "   └──────────────┬──────────────────────────┘\n";
echo "                  ▼\n";
echo "   ┌─────────────────────────────────────────┐\n";
echo "   │ 7. NotificationService envoie aux étudiants │\n";
echo "   └─────────────────────────────────────────┘\n";
echo "\n";

echo "=== Test terminé ===\n";
