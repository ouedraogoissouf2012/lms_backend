<?php

/**
 * Test de la détection automatique pour la séance 55
 * Simule le chargement de la page des séances
 */

require __DIR__ . '/vendor/autoload.php';

use App\Http\Controllers\API\LMSDataController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Test Détection Auto Séance 55 ===\n\n";

// 1. Vérifier l'état avant
echo "1. État AVANT:\n";
$seance55Local = DB::table('seances')->where('klassci_seance_id', 55)->first();

if ($seance55Local) {
    echo "   ⚠ La séance 55 existe déjà localement (ID: {$seance55Local->id})\n";
    echo "   Suppression pour tester la détection auto...\n";
    DB::table('seances')->where('klassci_seance_id', 55)->delete();
    echo "   ✓ Supprimée\n\n";
} else {
    echo "   ✓ La séance 55 n'existe pas localement\n\n";
}

// Compter les notifications avant
$notifsBefore = DB::table('notifications')
    ->whereIn('type', ['visio_scheduled', 'visio_starting'])
    ->count();
echo "   Notifications visio avant: {$notifsBefore}\n\n";

// 2. Trouver l'enseignant
$teacher = User::where('role', 'enseignant')->whereNotNull('klassci_token')->first();

if (!$teacher) {
    echo "Aucun enseignant trouvé\n";
    exit(1);
}

echo "2. Enseignant: {$teacher->name}\n\n";

// 3. Simuler l'appel à myTeachingSeances
echo "3. Simulation appel API GET /api/lms/seances/my-teaching...\n\n";

try {
    // Créer une requête simulée
    $request = Request::create('/api/lms/seances/my-teaching', 'GET');
    $request->setUserResolver(function () use ($teacher) {
        return $teacher;
    });

    // Appeler le contrôleur
    $controller = app(LMSDataController::class);
    $response = $controller->myTeachingSeances($request);

    $statusCode = $response->getStatusCode();
    echo "   Status: {$statusCode}\n";

    if ($statusCode === 200) {
        echo "   ✓ Requête réussie\n\n";

        $data = json_decode($response->getContent(), true);
        $seancesCount = 0;

        if (isset($data['data'])) {
            foreach ($data['data'] as $matiereData) {
                if (isset($matiereData['seances_programmees'])) {
                    $seancesCount += count($matiereData['seances_programmees']);
                }
            }
        }

        echo "   Séances retournées: {$seancesCount}\n\n";
    } else {
        echo "   ✗ Erreur: {$statusCode}\n";
        echo "   " . $response->getContent() . "\n\n";
    }

} catch (Exception $e) {
    echo "   ✗ Exception: {$e->getMessage()}\n";
    echo "   Trace: {$e->getTraceAsString()}\n\n";
}

// 4. Vérifier l'état après
echo "4. État APRÈS:\n";
$seance55After = DB::table('seances')->where('klassci_seance_id', 55)->first();

if ($seance55After) {
    echo "   ✓✓✓ La séance 55 a été créée localement!\n";
    echo "   ID local: {$seance55After->id}\n";
    echo "   Visio enabled: " . ($seance55After->visio_enabled ? 'OUI' : 'NON') . "\n";
    echo "   Room ID: {$seance55After->visio_room_id}\n\n";
} else {
    echo "   ✗ La séance 55 n'a PAS été créée\n";
    echo "   La détection auto n'a pas fonctionné\n\n";
}

// Compter les notifications après
$notifsAfter = DB::table('notifications')
    ->whereIn('type', ['visio_scheduled', 'visio_starting'])
    ->count();

$newNotifs = $notifsAfter - $notifsBefore;

echo "   Notifications visio après: {$notifsAfter}\n";
echo "   Nouvelles notifications: {$newNotifs}\n\n";

if ($newNotifs > 0) {
    echo "   ✓✓✓ {$newNotifs} notification(s) créée(s)!\n\n";

    // Afficher les dernières notifications
    $latestNotifs = DB::table('notifications')
        ->whereIn('type', ['visio_scheduled', 'visio_starting'])
        ->orderBy('id', 'desc')
        ->limit($newNotifs)
        ->get();

    echo "5. Notifications créées:\n";
    foreach ($latestNotifs as $notif) {
        $user = DB::table('users')->find($notif->user_id);
        echo "   - Pour: " . ($user ? $user->name : "User {$notif->user_id}") . "\n";
        echo "     Type: {$notif->type}\n";
        echo "     Titre: {$notif->title}\n";
        echo "     Message: {$notif->message}\n\n";
    }
} else {
    echo "   ✗ Aucune notification créée\n\n";
}

// 6. Vérifier les logs
echo "6. Vérification des logs:\n";
$logFile = storage_path('logs/laravel.log');

if (file_exists($logFile)) {
    $logs = shell_exec("tail -20 {$logFile} | grep -i 'séance\|visio\|notification'");

    if ($logs) {
        echo "   Logs récents:\n";
        echo "   " . str_replace("\n", "\n   ", trim($logs)) . "\n\n";
    } else {
        echo "   Aucun log récent trouvé\n\n";
    }
}

echo "=== Test terminé ===\n";
