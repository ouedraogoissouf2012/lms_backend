<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Models\Seance;
use App\Services\KlassciProxyService;

echo "====================================\n";
echo "TEST WORKFLOW COMPLET VISIOCONFÉRENCE\n";
echo "====================================\n\n";

// 1. Récupérer l'enseignant
$user = User::where('role', 'enseignant')->first();
if (!$user) {
    echo "❌ Pas d'enseignant dans la BDD\n";
    exit(1);
}

echo "👤 Enseignant: {$user->name} (ID: {$user->id})\n\n";

// 2. Récupérer une séance via l'API
$klassciService = app(KlassciProxyService::class);

try {
    $dashboard = $klassciService->requestWithUserToken(
        $user->klassci_token,
        'me/teacher-dashboard',
        'GET'
    );

    $matieres = collect($dashboard['data']['matieres'] ?? []);
    echo "📚 Matières trouvées: " . $matieres->count() . "\n";

    $seanceId = null;
    foreach ($matieres as $matiere) {
        $matiereDetails = $klassciService->requestWithUserToken(
            $user->klassci_token,
            "matieres/{$matiere['id']}",
            'GET'
        );

        $seances = $matiereDetails['data']['seances_programmees'] ?? [];
        if (!empty($seances)) {
            $seanceId = $seances[0]['id'];
            echo "🎯 Séance sélectionnée: {$seanceId}\n\n";
            break;
        }
    }

    if (!$seanceId) {
        echo "❌ Aucune séance trouvée\n";
        exit(1);
    }

    // 3. Nettoyer l'ancienne entrée si elle existe
    echo "🧹 ÉTAPE 0: Nettoyage...\n";
    $oldVisio = Seance::where('klassci_seance_id', $seanceId)->first();
    if ($oldVisio) {
        echo "   - Suppression ancienne entrée (ID: {$oldVisio->id})\n";
        $oldVisio->forceDelete();
    }
    echo "   ✅ Nettoyage terminé\n\n";

    // 4. ÉTAPE 1: Activer la visio (status = programmee)
    echo "🔧 ÉTAPE 1: Activation visio...\n";
    $controller = app(App\Http\Controllers\API\LMSDataController::class);

    $request = new \Illuminate\Http\Request();
    $request->setUserResolver(function () use ($user) {
        return $user;
    });
    $request->merge([
        'enabled' => true,
        'visio_type' => 'jitsi'
    ]);

    $response = $controller->activateVisio($seanceId, $request);
    $data = $response->getData(true);

    if (!$data['success']) {
        echo "   ❌ ÉCHEC: {$data['message']}\n";
        exit(1);
    }

    $visio = Seance::where('klassci_seance_id', $seanceId)->first();
    echo "   ✅ Visio activée\n";
    echo "   - Status: {$visio->visio_status}\n";
    echo "   - Room ID: {$visio->visio_room_id}\n";
    echo "   - Enabled: " . ($visio->visio_enabled ? 'OUI' : 'NON') . "\n\n";

    if ($visio->visio_status !== 'programmee') {
        echo "   ⚠️  ATTENTION: Status devrait être 'programmee' mais est '{$visio->visio_status}'\n\n";
    }

    // 5. ÉTAPE 2: Démarrer la visio (status = active)
    echo "▶️  ÉTAPE 2: Démarrage visio...\n";

    $request2 = new \Illuminate\Http\Request();
    $request2->setUserResolver(function () use ($user) {
        return $user;
    });

    $response2 = $controller->startVisio($seanceId, $request2);
    $data2 = $response2->getData(true);

    if (!$data2['success']) {
        echo "   ❌ ÉCHEC: {$data2['message']}\n";
        exit(1);
    }

    $visio->refresh();
    echo "   ✅ Visio démarrée\n";
    echo "   - Status: {$visio->visio_status}\n";
    echo "   - Active: " . ($visio->visio_active ? 'OUI' : 'NON') . "\n";
    echo "   - Started at: {$visio->visio_started_at}\n\n";

    if ($visio->visio_status !== 'active') {
        echo "   ⚠️  ATTENTION: Status devrait être 'active' mais est '{$visio->visio_status}'\n\n";
    }

    // 6. ÉTAPE 3: Terminer la visio (status = terminee)
    echo "⏹️  ÉTAPE 3: Terminaison visio...\n";

    $request3 = new \Illuminate\Http\Request();
    $request3->setUserResolver(function () use ($user) {
        return $user;
    });

    $response3 = $controller->endVisio($seanceId, $request3);
    $data3 = $response3->getData(true);

    if (!$data3['success']) {
        echo "   ❌ ÉCHEC: {$data3['message']}\n";
        exit(1);
    }

    $visio->refresh();
    echo "   ✅ Visio terminée\n";
    echo "   - Status: {$visio->visio_status}\n";
    echo "   - Active: " . ($visio->visio_active ? 'OUI' : 'NON') . "\n";
    echo "   - Ended at: {$visio->visio_ended_at}\n\n";

    if ($visio->visio_status !== 'terminee') {
        echo "   ⚠️  ATTENTION: Status devrait être 'terminee' mais est '{$visio->visio_status}'\n\n";
    }

    // 7. Résumé final
    echo "====================================\n";
    echo "RÉSUMÉ WORKFLOW COMPLET\n";
    echo "====================================\n";
    echo "Séance {$seanceId}:\n";
    echo "  ✅ Activation: programmee\n";
    echo "  ✅ Démarrage: active\n";
    echo "  ✅ Terminaison: terminee\n";
    echo "\n🎉 WORKFLOW COMPLET RÉUSSI!\n\n";

} catch (\Exception $e) {
    echo "❌ ERREUR: {$e->getMessage()}\n";
    echo "Trace: {$e->getTraceAsString()}\n";
    exit(1);
}
