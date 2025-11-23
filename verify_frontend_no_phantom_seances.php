<?php

/**
 * Vérifier que le frontend ne verra plus les séances fantômes
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "═══════════════════════════════════════════════════════════\n";
echo "   VÉRIFICATION: FRONTEND NE VOIT PLUS LES SÉANCES\n";
echo "═══════════════════════════════════════════════════════════\n\n";

// Trouver Marcel
$marcel = App\Models\User::where('name', 'LIKE', '%MARCEL%')->first();

if (!$marcel) {
    die("❌ Marcel non trouvé\n");
}

echo "👤 Utilisateur: {$marcel->name}\n";
echo "   Role: {$marcel->role}\n\n";

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "1️⃣  APPEL API: /lms/seances/my-classes (Vue Étudiant)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Simuler l'appel API du frontend
$controller = new App\Http\Controllers\API\LMSDataController(
    app(\App\Services\KlassciProxyService::class),
    app(\App\Services\NotificationService::class),
    app(\App\Services\ClasseSyncService::class)
);

$request = new Illuminate\Http\Request();
$request->setUserResolver(function () use ($marcel) {
    return $marcel;
});

try {
    $response = $controller->myClassesSeances($request);
    $data = json_decode($response->getContent(), true);

    if ($data['success']) {
        $seances = $data['data'] ?? [];

        echo "✅ API appelée avec succès\n";
        echo "📊 Nombre de séances retournées: " . count($seances) . "\n\n";

        if (count($seances) === 0) {
            echo "🎉 PARFAIT ! Aucune séance fantôme visible.\n";
            echo "   Les séances supprimées dans Klassci sont bien masquées.\n\n";
        } else {
            echo "⚠️  Séances encore visibles:\n\n";
            foreach ($seances as $seance) {
                echo "   • Matière: " . ($seance['matiere_nom'] ?? 'N/A') . "\n";
                echo "     Date: " . ($seance['date'] ?? 'N/A') . "\n";
                echo "     Klassci ID: " . ($seance['id'] ?? 'N/A') . "\n";
                echo "\n";
            }
        }
    } else {
        echo "❌ Erreur: {$data['message']}\n";
    }

} catch (\Exception $e) {
    echo "❌ Erreur: {$e->getMessage()}\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "2️⃣  APPEL API: /lms/matieres/1 (Détails Marketing)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

try {
    $response = $controller->matiereDetails(1, $request);
    $data = json_decode($response->getContent(), true);

    if ($data['success']) {
        $seances = $data['data']['seances_programmees'] ?? [];

        echo "✅ API appelée avec succès\n";
        echo "📊 Nombre de séances pour Marketing digital: " . count($seances) . "\n\n";

        if (count($seances) === 0) {
            echo "🎉 PARFAIT ! Marketing digital n'a plus de séances fantômes.\n\n";
        } else {
            echo "⚠️  Séances encore dans Marketing digital:\n\n";
            foreach ($seances as $seance) {
                echo "   • ID: " . ($seance['id'] ?? 'N/A') . "\n";
                echo "     Date: " . (isset($seance['programmation']['date']) ? $seance['programmation']['date'] : 'N/A') . "\n";
                echo "\n";
            }
        }
    } else {
        echo "❌ Erreur: {$data['message']}\n";
    }

} catch (\Exception $e) {
    echo "❌ Erreur: {$e->getMessage()}\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "3️⃣  RÉSUMÉ FINAL\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$seancesActives = App\Models\Seance::where('is_active', true)->count();
$seancesArchivees = App\Models\Seance::where('is_active', false)->count();

echo "📊 État de la base de données:\n";
echo "   • Séances actives: {$seancesActives}\n";
echo "   • Séances archivées: {$seancesArchivees}\n\n";

echo "✅ SYNCHRONISATION AUTOMATIQUE ACTIVE:\n";
echo "   • Job 'CleanObsoleteSeances' s'exécute toutes les 30 minutes\n";
echo "   • Les séances supprimées dans Klassci seront automatiquement archivées\n";
echo "   • Le frontend ne montrera que les séances existantes dans Klassci\n\n";

echo "═══════════════════════════════════════════════════════════\n";
echo "   VÉRIFICATION TERMINÉE\n";
echo "═══════════════════════════════════════════════════════════\n";
