<?php

/**
 * Analyser le workflow de synchronisation Klassci → LMS
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "═══════════════════════════════════════════════════════════\n";
echo "   ANALYSE DU WORKFLOW DE SYNCHRONISATION\n";
echo "═══════════════════════════════════════════════════════════\n\n";

$klassciService = app(\App\Services\KlassciProxyService::class);

// Trouver Marcel
$marcel = App\Models\User::where('name', 'LIKE', '%MARCEL%')->first();

if (!$marcel) {
    die("❌ Marcel non trouvé\n");
}

echo "👤 Utilisateur: {$marcel->name}\n";
echo "   Role: {$marcel->role}\n\n";

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "1️⃣  SÉANCES DANS LA BDD LOCALE LMS\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$localSeances = App\Models\Seance::where('is_active', true)->get();

echo "Total séances actives: {$localSeances->count()}\n\n";

$klassciIds = $localSeances->pluck('klassci_seance_id')->filter()->unique()->values();
echo "IDs Klassci uniques: " . $klassciIds->implode(', ') . "\n\n";

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "2️⃣  VÉRIFIER SI CES SÉANCES EXISTENT DANS KLASSCI\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Essayer d'appeler directement chaque séance
foreach ($klassciIds as $klassciId) {
    echo "\n🔍 Séance Klassci #{$klassciId}:\n";

    try {
        // Essayer d'abord GET /seances/{id}
        $response = $klassciService->requestWithUserToken(
            $marcel->klassci_token,
            "seances/{$klassciId}",
            'GET'
        );

        if ($response && isset($response['data'])) {
            $seance = $response['data'];

            echo "   ✅ TROUVÉE dans Klassci!\n";
            echo "   Matière: " . ($seance['matiere']['nom'] ?? 'N/A') . "\n";

            if (isset($seance['programmation'])) {
                $prog = $seance['programmation'];
                echo "   📅 Date: " . ($prog['date'] ?? 'N/A') . "\n";
                echo "   ⏰ Heure: " . ($prog['heure_debut'] ?? 'N/A') . " - " . ($prog['heure_fin'] ?? 'N/A') . "\n";
                echo "   🏫 Salle: " . ($prog['salle'] ?? 'N/A') . "\n";
            } else {
                echo "   ⚠️  Pas d'objet 'programmation'\n";
            }

            echo "   📊 Statut: " . ($seance['statut'] ?? 'N/A') . "\n";

        } else {
            echo "   ⚠️  Réponse vide\n";
        }

    } catch (\Exception $e) {
        echo "   ❌ Erreur: " . substr($e->getMessage(), 0, 100) . "\n";
    }
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "3️⃣  ESSAYER D'AUTRES ENDPOINTS POUR TROUVER LES SÉANCES\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Essayer me/dashboard avec dates étendues
$endpoints = [
    'me/dashboard',
    'me/seances',
    'me/emploi-du-temps',
    'seances?matiere_id=1',
];

foreach ($endpoints as $endpoint) {
    echo "\n🌐 Endpoint: {$endpoint}\n";

    try {
        $response = $klassciService->requestWithUserToken(
            $marcel->klassci_token,
            $endpoint,
            'GET'
        );

        // Chercher des séances dans la réponse
        $seances = [];

        if (isset($response['data']['seances_programmees'])) {
            $seances = $response['data']['seances_programmees'];
        } elseif (isset($response['data']['seances'])) {
            $seances = $response['data']['seances'];
        } elseif (isset($response['data']) && is_array($response['data'])) {
            // Vérifier si c'est directement un tableau de séances
            $firstItem = reset($response['data']);
            if ($firstItem && isset($firstItem['programmation'])) {
                $seances = $response['data'];
            }
        }

        if (!empty($seances)) {
            echo "   ✅ Trouvé " . count($seances) . " séances!\n";

            // Afficher les IDs
            $ids = array_map(fn($s) => $s['id'] ?? '?', array_slice($seances, 0, 5));
            echo "   IDs (5 premiers): " . implode(', ', $ids) . "\n";

            // Vérifier si nos séances locales sont dedans
            $foundLocal = 0;
            foreach ($seances as $s) {
                if (in_array($s['id'] ?? null, $klassciIds->toArray())) {
                    $foundLocal++;
                }
            }

            if ($foundLocal > 0) {
                echo "   🎯 {$foundLocal} de nos séances locales TROUVÉES dans cette réponse!\n";
            } else {
                echo "   ⚠️  Aucune de nos séances locales dans cette réponse\n";
            }
        } else {
            echo "   ⚠️  Aucune séance dans la réponse\n";
        }

    } catch (\Exception $e) {
        echo "   ❌ Erreur: " . substr($e->getMessage(), 0, 100) . "\n";
    }
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "4️⃣  CONCLUSION\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "📊 RÉSUMÉ:\n";
echo "   • Séances dans BDD locale LMS: {$localSeances->count()}\n";
echo "   • IDs Klassci référencés: " . $klassciIds->count() . "\n";
echo "   • Ces séances existent-elles encore dans Klassci? → Voir résultats ci-dessus\n\n";

echo "🔧 QUESTION CLÉ À RÉSOUDRE:\n";
echo "   1. Ces séances sont-elles dans le PASSÉ dans Klassci?\n";
echo "   2. Y a-t-il un filtre de date côté Klassci qui les masque?\n";
echo "   3. Comment l'enseignant a-t-il créé ces séances initialement?\n";
echo "   4. Quel est le workflow prévu pour synchroniser?\n\n";

echo "═══════════════════════════════════════════════════════════\n";
echo "   FIN DE L'ANALYSE\n";
echo "═══════════════════════════════════════════════════════════\n";
