<?php

/**
 * Script pour simuler un vrai appel API et voir exactement ce qui est retourné
 */

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Http\Request;

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "═══════════════════════════════════════════════════════════════\n";
echo "   TEST : Réponse réelle de l'API myTeachingSeances\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// Trouver un enseignant
$enseignant = \App\Models\User::where('role', 'enseignant')
    ->whereNotNull('klassci_token')
    ->first();

if (!$enseignant) {
    echo "❌ Aucun enseignant avec klassci_token trouvé\n";
    exit(1);
}

echo "👨‍🏫 Enseignant : {$enseignant->name}\n";
echo "   ID : {$enseignant->id}\n";
echo "   Klassci ID : {$enseignant->klassci_id}\n\n";

// Créer une instance du contrôleur
$controller = new \App\Http\Controllers\API\LMSDataController(
    app(\App\Services\KlassciService::class),
    app(\App\Services\NotificationService::class),
    app(\App\Services\ClasseSyncService::class)
);

// Créer une requête mock
$request = Request::create('/api/lms/my-teaching-seances', 'GET');
$request->setUserResolver(function () use ($enseignant) {
    return $enseignant;
});

echo "🔄 Appel de l'API myTeachingSeances()...\n\n";

try {
    $response = $controller->myTeachingSeances($request);
    $data = json_decode($response->getContent(), true);

    if (!$data['success']) {
        echo "❌ Erreur API : " . ($data['message'] ?? 'Inconnue') . "\n";
        exit(1);
    }

    $seances = $data['data'] ?? [];
    echo "✅ API Success - {count($seances)} séance(s) retournée(s)\n\n";

    // Filtrer pour ne montrer que les séances avec visio activée
    $seancesAvecVisio = array_filter($seances, function($s) {
        return isset($s['visio_enabled']) && $s['visio_enabled'] === true;
    });

    if (empty($seancesAvecVisio)) {
        echo "⚠️  Aucune séance avec visio_enabled=true trouvée\n";
        echo "   Toutes les séances ont visio_enabled=false\n\n";

        // Afficher un échantillon
        echo "📋 Échantillon des 2 premières séances :\n\n";
        foreach (array_slice($seances, 0, 2) as $idx => $seance) {
            echo "   Séance #{$idx} - ID: " . ($seance['id'] ?? 'N/A') . "\n";
            echo "   Matière: " . ($seance['matiere']['nom'] ?? 'N/A') . "\n";
            echo "   visio_enabled: " . (isset($seance['visio_enabled']) ? ($seance['visio_enabled'] ? 'true' : 'false') : 'ABSENT') . "\n";
            echo "   visio_active: " . (isset($seance['visio_active']) ? ($seance['visio_active'] ? 'true' : 'false') : 'ABSENT') . "\n";
            echo "   visio object: " . (isset($seance['visio']) ? 'PRESENT' : 'ABSENT') . "\n\n";
        }

        exit(0);
    }

    echo "📋 Séances avec visio activée :\n\n";

    foreach (array_slice($seancesAvecVisio, 0, 3) as $seance) {
        echo str_repeat("=", 70) . "\n";
        echo "🎯 Séance ID : " . ($seance['id'] ?? 'N/A') . "\n";
        echo "   Matière : " . ($seance['matiere']['nom'] ?? 'N/A') . "\n";
        echo "   Date : " . ($seance['programmation']['date'] ?? 'N/A') . "\n";
        echo "   Heure : " . ($seance['programmation']['heure_debut'] ?? 'N/A') . "\n\n";

        echo "🔑 CHAMPS CRITIQUES (format plat) :\n";
        echo "   visio_enabled : " . (isset($seance['visio_enabled']) ? json_encode($seance['visio_enabled']) : 'ABSENT') . "\n";
        echo "   visio_active  : " . (isset($seance['visio_active']) ? json_encode($seance['visio_active']) : 'ABSENT') . "\n";
        echo "   visio_status  : " . (isset($seance['visio_status']) ? json_encode($seance['visio_status']) : 'ABSENT') . "\n";
        echo "   visio_room_id : " . (isset($seance['visio_room_id']) ? json_encode($seance['visio_room_id']) : 'ABSENT') . "\n\n";

        echo "📦 Objet visio (format imbriqué) :\n";
        if (isset($seance['visio'])) {
            echo "   " . json_encode($seance['visio'], JSON_PRETTY_PRINT) . "\n\n";
        } else {
            echo "   ABSENT\n\n";
        }

        echo "🎯 CONDITION FRONTEND : v-if=\"seance.visio_enabled && !seance.visio_active\"\n";
        $visioEnabled = $seance['visio_enabled'] ?? false;
        $visioActive = $seance['visio_active'] ?? false;
        $shouldShow = $visioEnabled && !$visioActive;

        echo "   seance.visio_enabled  = " . ($visioEnabled ? 'true' : 'false') . "\n";
        echo "   !seance.visio_active  = " . (!$visioActive ? 'true' : 'false') . "\n";
        echo "   Résultat              = " . ($shouldShow ? 'true ✅' : 'false ❌') . "\n\n";

        if (!$shouldShow) {
            echo "   ⚠️ PROBLÈME DÉTECTÉ :\n";
            if (!$visioEnabled) {
                echo "      → visio_enabled est false (devrait être true)\n";
            }
            if ($visioActive) {
                echo "      → visio_active est true (devrait être false)\n";
            }
        }

        echo "\n";
    }

    echo str_repeat("=", 70) . "\n";

} catch (\Exception $e) {
    echo "❌ Erreur : {$e->getMessage()}\n";
    echo "   Trace : " . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n═══════════════════════════════════════════════════════════════\n";
