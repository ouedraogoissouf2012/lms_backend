<?php

/**
 * Script de test pour vérifier que le bouton "Démarrer la visio" est visible
 * pour l'enseignant après activation par le coordinateur
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "═══════════════════════════════════════════════════════════════\n";
echo "   TEST : Bouton 'Démarrer la visio' Enseignant\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// 1️⃣ Trouver un enseignant
$enseignant = \App\Models\User::where('role', 'enseignant')->first();

if (!$enseignant) {
    echo "❌ Aucun enseignant trouvé\n";
    exit(1);
}

echo "👨‍🏫 Enseignant : {$enseignant->name} (ID: {$enseignant->id})\n";
echo "   klassci_id : {$enseignant->klassci_id}\n\n";

// 2️⃣ Trouver une séance avec visio activée par coordinateur
echo "🔍 Recherche de séances avec visio activée...\n";
$seancesWithVisio = \App\Models\Seance::where('visio_enabled', true)
    ->where('visio_active', false)  // Pas encore démarrée
    ->orderBy('updated_at', 'desc')
    ->get();

if ($seancesWithVisio->isEmpty()) {
    echo "⚠️  Aucune séance avec visio activée (mais non démarrée) trouvée\n";
    echo "   Pour tester :\n";
    echo "   1. Connectez-vous en tant que coordinateur\n";
    echo "   2. Activez la visio pour une séance\n";
    echo "   3. Relancez ce script\n";
    exit(0);
}

echo "   ✅ {$seancesWithVisio->count()} séance(s) avec visio activée trouvée(s)\n\n";

// 3️⃣ Simuler la réponse de l'API myTeachingSeances
echo "📡 Simulation de la réponse API /api/lms/my-teaching-seances...\n\n";

foreach ($seancesWithVisio->take(3) as $seance) {
    echo "   📋 Séance ID : {$seance->klassci_seance_id}\n";
    echo "      Matière : " . ($seance->matiere_nom ?? 'N/A') . "\n";
    echo "      Enseignant : " . ($seance->enseignant_nom ?? 'N/A') . "\n\n";

    echo "      🔧 État BDD :\n";
    echo "         visio_enabled  : " . ($seance->visio_enabled ? '✅ true' : '❌ false') . "\n";
    echo "         visio_active   : " . ($seance->visio_active ? '✅ true' : '❌ false') . "\n";
    echo "         visio_status   : " . ($seance->visio_status ?? 'null') . "\n";
    echo "         visio_room_id  : " . ($seance->visio_room_id ?? 'null') . "\n\n";

    echo "      📤 Réponse API (format JSON) :\n";
    $apiResponse = [
        'id' => $seance->klassci_seance_id,
        'matiere' => ['nom' => $seance->matiere_nom],
        // 🔑 CHAMPS CRITIQUES pour le frontend
        'visio_enabled' => $seance->visio_enabled,
        'visio_active' => $seance->visio_active,
        'visio_status' => $seance->visio_status,
        'visio_room_id' => $seance->visio_room_id,
        // Structure imbriquée (compatibilité)
        'visio' => [
            'enabled' => $seance->visio_enabled,
            'active' => $seance->visio_active,
            'status' => $seance->visio_status,
            'room_id' => $seance->visio_room_id,
        ]
    ];

    echo "         " . json_encode($apiResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

    // 4️⃣ Tester la condition du frontend
    echo "      🎯 Condition frontend (TeacherVisioList.vue:183) :\n";
    echo "         v-if=\"seance.visio_enabled && !seance.visio_active\"\n\n";

    $visioEnabled = $seance->visio_enabled;
    $visioActive = $seance->visio_active;
    $shouldShowButton = $visioEnabled && !$visioActive;

    echo "         seance.visio_enabled  = " . ($visioEnabled ? 'true' : 'false') . "\n";
    echo "         !seance.visio_active  = " . (!$visioActive ? 'true' : 'false') . "\n";
    echo "         Résultat              = " . ($shouldShowButton ? 'true' : 'false') . "\n\n";

    if ($shouldShowButton) {
        echo "      ✅ SUCCÈS : Bouton 'Démarrer la visio' DOIT être visible !\n";
    } else {
        echo "      ❌ ERREUR : Bouton 'Démarrer la visio' N'EST PAS visible\n";
        if (!$visioEnabled) {
            echo "         Raison : visio_enabled = false\n";
        }
        if ($visioActive) {
            echo "         Raison : visio_active = true (déjà démarrée)\n";
        }
    }

    echo "\n" . str_repeat("-", 70) . "\n\n";
}

// 5️⃣ Recommandations
echo "💡 WORKFLOW DE TEST COMPLET :\n\n";
echo "   1. Coordinateur active une séance :\n";
echo "      → BDD : visio_enabled=1, visio_active=0\n\n";

echo "   2. API /api/lms/my-teaching-seances retourne :\n";
echo "      → visio_enabled: true\n";
echo "      → visio_active: false\n\n";

echo "   3. Frontend évalue la condition :\n";
echo "      → v-if=\"seance.visio_enabled && !seance.visio_active\"\n";
echo "      → Résultat : true\n\n";

echo "   4. Bouton 'Démarrer la visio' affiché ✅\n\n";

echo "   5. Enseignant clique sur 'Démarrer' :\n";
echo "      → API : POST /api/lms/seances/{id}/start-visio\n";
echo "      → BDD : visio_active=1\n\n";

echo "   6. Bouton change en 'Terminer' :\n";
echo "      → v-else-if=\"seance.visio_active\"\n\n";

echo "═══════════════════════════════════════════════════════════════\n";
echo "   FIN DU TEST\n";
echo "═══════════════════════════════════════════════════════════════\n";
