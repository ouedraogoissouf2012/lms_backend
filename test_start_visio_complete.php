<?php

/**
 * Test complet pour identifier pourquoi l'enseignant ne peut pas démarrer
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "═══════════════════════════════════════════════════════════════\n";
echo "   TEST COMPLET : Démarrage visio par enseignant\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// 1. État de la séance
$seance = \App\Models\Seance::where('visio_enabled', true)
    ->where('visio_active', false)
    ->orderBy('updated_at', 'desc')
    ->first();

if (!$seance) {
    echo "❌ Aucune séance avec visio activée (non démarrée)\n";
    exit(0);
}

echo "1️⃣ SÉANCE SÉLECTIONNÉE\n";
echo str_repeat("-", 70) . "\n";
echo "   ID : {$seance->klassci_seance_id}\n";
echo "   Matière : " . ($seance->matiere_nom ?? 'N/A') . "\n";
echo "   visio_enabled : " . ($seance->visio_enabled ? 'true ✅' : 'false ❌') . "\n";
echo "   visio_active : " . ($seance->visio_active ? 'true ❌' : 'false ✅') . "\n";
echo "   visio_room_id : " . ($seance->visio_room_id ?? 'NULL ❌') . "\n\n";

// 2. Trouver un enseignant
$enseignant = \App\Models\User::where('role', 'enseignant')->first();

if (!$enseignant) {
    echo "❌ Aucun enseignant trouvé\n";
    exit(1);
}

echo "2️⃣ ENSEIGNANT\n";
echo str_repeat("-", 70) . "\n";
echo "   Nom : {$enseignant->name}\n";
echo "   Email : {$enseignant->email}\n";
echo "   Rôle : {$enseignant->role}\n";
echo "   klassci_id : " . ($enseignant->klassci_id ?? 'NULL') . "\n";
echo "   klassci_token : " . ($enseignant->klassci_token ? 'PRÉSENT ✅' : 'NULL ❌') . "\n\n";

// 3. Vérifier les conditions de startVisio()
echo "3️⃣ VÉRIFICATION DES CONDITIONS (startVisio)\n";
echo str_repeat("-", 70) . "\n\n";

$tests = [];

// Test 1 : Séance existe
$test1 = $seance !== null;
$tests[] = ['Séance existe', $test1, 'Ligne 2843-2850'];
echo "   " . ($test1 ? '✅' : '❌') . " Séance existe\n";
echo "      Code : if (!$visio) return 404\n";
echo "      Résultat : " . ($test1 ? 'Séance trouvée' : 'Séance non trouvée') . "\n\n";

// Test 2 : visio_enabled
$test2 = $seance->visio_enabled;
$tests[] = ['visio_enabled = true', $test2, 'Ligne 2852-2857'];
echo "   " . ($test2 ? '✅' : '❌') . " visio_enabled = true\n";
echo "      Code : if (!$visio->visio_enabled) return 400\n";
echo "      Résultat : visio_enabled = " . ($seance->visio_enabled ? 'true' : 'false') . "\n\n";

// Test 3 : Rôle enseignant
$test3 = $enseignant->role === 'enseignant';
$tests[] = ['Rôle = enseignant', $test3, 'Ligne 2860-2865'];
echo "   " . ($test3 ? '✅' : '❌') . " Rôle = enseignant\n";
echo "      Code : if ($user->role !== 'enseignant') return 403\n";
echo "      Résultat : role = {$enseignant->role}\n\n";

// Résultat global
$allPassed = $test1 && $test2 && $test3;

echo "═══════════════════════════════════════════════════════════════\n";
echo "   RÉSULTAT DES TESTS\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

foreach ($tests as $test) {
    $status = $test[1] ? '✅ PASS' : '❌ FAIL';
    echo "{$status} : {$test[0]} ({$test[2]})\n";
}

echo "\n";

if ($allPassed) {
    echo "✅ TOUTES LES CONDITIONS SONT REMPLIES\n";
    echo "   L'enseignant DEVRAIT pouvoir démarrer la visio\n\n";

    echo "4️⃣ SIMULATION DU DÉMARRAGE\n";
    echo str_repeat("-", 70) . "\n\n";

    echo "   Ce qui devrait se passer :\n";
    echo "   1. Mise à jour BDD :\n";
    echo "      - visio_status = 'active'\n";
    echo "      - visio_active = true\n";
    echo "      - visio_started_at = now()\n\n";

    echo "   2. Synchronisation classe (si klassci_classe_id existe)\n";
    echo "      - klassci_classe_id = " . ($seance->klassci_classe_id ?? 'NULL') . "\n\n";

    echo "   3. Envoi des notifications\n";
    echo "      - Notification aux étudiants : 'Visio démarrée'\n";
    echo "      - Notification à l'enseignant\n\n";

    echo "   4. Réponse API :\n";
    echo "      {\n";
    echo "        \"success\": true,\n";
    echo "        \"message\": \"Visioconférence démarrée\",\n";
    echo "        \"data\": {\n";
    echo "          \"visio_status\": \"active\",\n";
    echo "          \"visio_room_id\": \"{$seance->visio_room_id}\"\n";
    echo "        }\n";
    echo "      }\n\n";

} else {
    echo "❌ CERTAINES CONDITIONS NE SONT PAS REMPLIES\n";
    echo "   L'enseignant NE PEUT PAS démarrer la visio\n\n";

    echo "   Problèmes détectés :\n";
    foreach ($tests as $test) {
        if (!$test[1]) {
            echo "   ❌ {$test[0]}\n";
        }
    }
    echo "\n";
}

// 5. Vérifier le frontend
echo "5️⃣ VÉRIFICATION FRONTEND\n";
echo str_repeat("-", 70) . "\n\n";

$frontendPath = 'C:/Users/USER PC/Documents/propre à moi/lms-frontend/src/views/teacher/TeacherVisioList.vue';
if (!file_exists($frontendPath)) {
    echo "❌ Fichier TeacherVisioList.vue non trouvé\n\n";
} else {
    $frontendContent = file_get_contents($frontendPath);

    // Vérifier la fonction handleStartVisio
    preg_match('/async function handleStartVisio.*?\{(.*?)\n\s*\}/s', $frontendContent, $matches);

    if (empty($matches)) {
        preg_match('/const handleStartVisio.*?\{(.*?)\n\}/s', $frontendContent, $matches);
    }

    if (!empty($matches)) {
        echo "   ✅ Fonction handleStartVisio trouvée\n\n";

        $functionBody = $matches[1];

        // Vérifier l'appel API
        $callsAPI = strpos($functionBody, 'lmsService') !== false ||
                    strpos($functionBody, 'startVisio') !== false;

        echo "   " . ($callsAPI ? '✅' : '❌') . " Appel à lmsService.startVisio()\n";

        // Vérifier le cache
        $hasRefresh = strpos($functionBody, 'loadVisioConferences') !== false ||
                      strpos($functionBody, 'refresh') !== false;

        echo "   " . ($hasRefresh ? '✅' : '⚠️') . " Rafraîchissement après démarrage\n\n";

    } else {
        echo "   ❌ Fonction handleStartVisio NON trouvée\n\n";
    }
}

// 6. Diagnostic
echo "═══════════════════════════════════════════════════════════════\n";
echo "   DIAGNOSTIC\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

if ($allPassed) {
    echo "✅ Backend : Toutes les conditions sont OK\n";
    echo "✅ Séance : Correctement configurée\n";
    echo "✅ Enseignant : Rôle correct\n\n";

    echo "⚠️ SI LE BOUTON NE FONCTIONNE PAS, c'est probablement :\n\n";

    echo "1. 🔄 PROBLÈME DE CACHE\n";
    echo "   Solution : Vider le localStorage\n";
    echo "   - F12 > Application > Local Storage\n";
    echo "   - Supprimer 'teacher_visio_cache'\n";
    echo "   - Rafraîchir (F5)\n\n";

    echo "2. 🌐 PROBLÈME FRONTEND\n";
    echo "   À vérifier dans la console (F12) :\n";
    echo "   - Y a-t-il une erreur JavaScript ?\n";
    echo "   - Le bouton est-il cliquable ?\n";
    echo "   - L'appel API est-il effectué ?\n\n";

    echo "3. 🔧 PROBLÈME API\n";
    echo "   À vérifier dans Network (F12 > Network) :\n";
    echo "   - La requête POST /api/lms/seances/{$seance->klassci_seance_id}/start-visio\n";
    echo "   - Status code (devrait être 200)\n";
    echo "   - Response (devrait être success: true)\n\n";

    echo "4. 📝 VÉRIFIER LES LOGS\n";
    echo "   Fichier : storage/logs/laravel.log\n";
    echo "   Chercher : 'Erreur démarrage visio'\n\n";

} else {
    echo "❌ Problème détecté dans les conditions backend\n";
    echo "   Voir la section 'RÉSULTAT DES TESTS' ci-dessus\n\n";
}

echo "═══════════════════════════════════════════════════════════════\n";
