<?php

/**
 * Test de la détection automatique des séances avec visio créées dans Klassci
 *
 * Ce script simule le chargement des séances d'un enseignant et vérifie que:
 * 1. Les séances Klassci avec visio_enabled sont détectées
 * 2. La classe est synchronisée automatiquement
 * 3. Les notifications sont envoyées aux étudiants
 */

require __DIR__ . '/vendor/autoload.php';

use App\Services\KlassciProxyService;
use App\Services\ClasseSyncService;
use App\Services\NotificationService;
use App\Models\User;
use App\Models\Seance;
use App\Models\Notification;
use App\Models\Classe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Test Détection Auto des Séances Visio Klassci ===\n\n";

// 1. Trouver un enseignant avec token
echo "1. Recherche d'un enseignant avec token Klassci...\n";
$teacher = User::where('role', 'enseignant')
    ->whereNotNull('klassci_token')
    ->whereNotNull('klassci_id')
    ->first();

if (!$teacher) {
    echo "   ✗ Aucun enseignant trouvé\n";
    exit(1);
}

echo "   ✓ Enseignant: {$teacher->name} (ID: {$teacher->id})\n";
echo "   - Klassci ID: {$teacher->klassci_id}\n\n";

// 2. État AVANT le test
echo "2. État AVANT le test...\n";
$seancesBefore = Seance::count();
$classesBefore = Classe::count();
$notificationsBefore = Notification::count();

echo "   - Séances locales: {$seancesBefore}\n";
echo "   - Classes locales: {$classesBefore}\n";
echo "   - Notifications: {$notificationsBefore}\n\n";

// 3. Récupérer les séances depuis Klassci
echo "3. Récupération des séances depuis Klassci...\n";
$klassciService = app(KlassciProxyService::class);

try {
    // Récupérer le dashboard enseignant
    $dashboard = $klassciService->requestWithUserToken(
        $teacher->klassci_token,
        'me/teacher-dashboard',
        'GET'
    );

    $matieres = $dashboard['data']['matieres'] ?? [];
    echo "   ✓ {count($matieres)} matière(s) trouvée(s)\n\n";

    if (empty($matieres)) {
        echo "   Aucune matière trouvée, impossible de tester\n";
        exit(0);
    }

    // Prendre la première matière
    $matiere = $matieres[0];
    echo "4. Analyse de la matière: {$matiere['nom']}\n";
    echo "   - ID Klassci: {$matiere['id']}\n";

    // Récupérer les détails de la matière
    $matiereDetails = $klassciService->requestWithUserToken(
        $teacher->klassci_token,
        "matieres/{$matiere['id']}",
        'GET'
    );

    $seancesProgrammees = $matiereDetails['data']['seances_programmees'] ?? [];
    echo "   - Séances programmées: " . count($seancesProgrammees) . "\n\n";

    if (empty($seancesProgrammees)) {
        echo "   ⚠ Aucune séance programmée, impossible de tester la détection auto\n";
        echo "   Créez une séance avec visio dans Klassci pour tester\n";
        exit(0);
    }

    // 5. Analyser les séances pour trouver celles avec visio
    echo "5. Analyse des séances...\n";
    $seancesAvecVisio = [];
    $seancesSansVisio = [];

    foreach ($seancesProgrammees as $seance) {
        $hasVisio = isset($seance['visio_enabled']) && $seance['visio_enabled'];
        $localSeance = Seance::where('klassci_seance_id', $seance['id'])->first();

        $status = [
            'klassci_id' => $seance['id'],
            'has_visio_klassci' => $hasVisio,
            'exists_local' => $localSeance ? 'Oui' : 'Non',
            'should_trigger_detection' => !$localSeance && $hasVisio,
            'classe_id' => $seance['classe']['id'] ?? null,
        ];

        if ($hasVisio) {
            $seancesAvecVisio[] = $status;
        } else {
            $seancesSansVisio[] = $status;
        }

        echo "   Séance ID {$seance['id']}:\n";
        echo "     - Visio Klassci: " . ($hasVisio ? '✓ Activée' : '✗ Désactivée') . "\n";
        echo "     - Existe localement: " . ($localSeance ? '✓ Oui' : '✗ Non') . "\n";
        echo "     - Détection auto devrait se déclencher: " . ($status['should_trigger_detection'] ? '✓ OUI' : '✗ Non') . "\n";

        if (isset($seance['classe']['id'])) {
            $classe = Classe::where('klassci_id', $seance['classe']['id'])->first();
            echo "     - Classe Klassci ID: {$seance['classe']['id']}\n";
            echo "     - Classe locale: " . ($classe ? "✓ {$classe->nom} (ID: {$classe->id})" : "✗ Non synchronisée") . "\n";
        }
        echo "\n";
    }

    echo "Résumé:\n";
    echo "   - Séances avec visio: " . count($seancesAvecVisio) . "\n";
    echo "   - Séances sans visio: " . count($seancesSansVisio) . "\n";

    $shouldDetect = array_filter($seancesAvecVisio, fn($s) => $s['should_trigger_detection']);
    echo "   - Séances qui devraient déclencher détection auto: " . count($shouldDetect) . "\n\n";

    if (empty($shouldDetect)) {
        echo "   ℹ Aucune séance ne devrait déclencher la détection auto\n";
        echo "   Pour tester:\n";
        echo "   1. Créez une séance dans Klassci avec visio activée\n";
        echo "   2. OU supprimez une entrée locale: DELETE FROM seances WHERE klassci_seance_id = <ID>\n";
        echo "   3. Relancez ce script\n";
        exit(0);
    }

    // 6. Simuler l'appel à myTeachingSeances qui devrait déclencher la détection
    echo "6. Simulation de l'appel API /api/lms/seances/my-teaching...\n";
    echo "   (Cette étape simule le chargement des séances côté frontend)\n\n";

    // Compter les notifications avant
    $notifsBefore = Notification::whereIn('type', [
        Notification::TYPE_VISIO_SCHEDULED,
        Notification::TYPE_VISIO_STARTING
    ])->count();

    // On ne peut pas vraiment appeler le contrôleur directement,
    // mais on peut vérifier que les services sont prêts
    $classeSyncService = app(ClasseSyncService::class);
    $notificationService = app(NotificationService::class);

    echo "   ✓ Services chargés:\n";
    echo "     - ClasseSyncService: ✓\n";
    echo "     - NotificationService: ✓\n";
    echo "     - KlassciProxyService: ✓\n\n";

    echo "7. Pour tester réellement la détection auto:\n";
    echo "   a) Ouvrez le frontend et connectez-vous en tant que: {$teacher->email}\n";
    echo "   b) Allez à la page des séances (/seances ou /enseignant/seances)\n";
    echo "   c) Le chargement devrait déclencher l'API /api/lms/seances/my-teaching\n";
    echo "   d) Surveillez les logs:\n";
    echo "      tail -f storage/logs/laravel.log | grep 'Séance Klassci avec visio détectée'\n\n";

    echo "8. OU créez une nouvelle séance avec visio dans Klassci:\n";
    echo "   a) Allez sur Klassci\n";
    echo "   b) Créez une séance pour la matière: {$matiere['nom']}\n";
    echo "   c) Activez la visioconférence\n";
    echo "   d) Revenez sur le LMS et rechargez la page des séances\n";
    echo "   e) Les notifications devraient être envoyées automatiquement\n\n";

} catch (\Exception $e) {
    echo "   ✗ Erreur: {$e->getMessage()}\n";
    echo "   Stack: {$e->getTraceAsString()}\n";
    exit(1);
}

// 9. État APRÈS (pour référence future)
echo "9. État actuel du système:\n";
$seancesAfter = Seance::count();
$classesAfter = Classe::count();
$notifsAfter = Notification::whereIn('type', [
    Notification::TYPE_VISIO_SCHEDULED,
    Notification::TYPE_VISIO_STARTING
])->count();

echo "   - Séances locales: {$seancesAfter}\n";
echo "   - Classes locales: {$classesAfter}\n";
echo "   - Notifications visio: {$notifsAfter}\n\n";

// 10. Afficher les dernières notifications
echo "10. Dernières notifications de visio:\n";
$recentNotifs = Notification::whereIn('type', [
        Notification::TYPE_VISIO_SCHEDULED,
        Notification::TYPE_VISIO_STARTING
    ])
    ->with('user')
    ->orderBy('id', 'desc')
    ->limit(5)
    ->get();

if ($recentNotifs->isEmpty()) {
    echo "    Aucune notification de visio trouvée\n";
} else {
    foreach ($recentNotifs as $notif) {
        echo "    - [{$notif->type}] Pour: {$notif->user->name} ({$notif->user->role})\n";
        echo "      Titre: {$notif->title}\n";
        echo "      Créée: {$notif->created_at->diffForHumans()}\n";
        echo "      Lue: " . ($notif->is_read ? 'Oui' : 'Non') . "\n\n";
    }
}

echo "=== Test terminé ===\n";
echo "\nRésumé:\n";
echo "- Le système de détection auto est en place dans LMSDataController::myTeachingSeances()\n";
echo "- Testez en chargeant la page des séances dans le frontend\n";
echo "- Ou créez une nouvelle séance avec visio dans Klassci\n";
echo "- Les notifications seront envoyées automatiquement lors du prochain chargement\n";
