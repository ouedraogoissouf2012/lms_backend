<?php

/**
 * Debug : Pourquoi l'enseignant n'arrive pas à démarrer après activation coordinateur
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "═══════════════════════════════════════════════════════════════\n";
echo "   DEBUG : Démarrage visio par enseignant\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// 1. Trouver une séance activée par coordinateur
echo "1️⃣ Recherche d'une séance activée mais non démarrée...\n\n";

$seance = \App\Models\Seance::where('visio_enabled', true)
    ->where('visio_active', false)
    ->orderBy('updated_at', 'desc')
    ->first();

if (!$seance) {
    echo "❌ Aucune séance avec visio_enabled=true et visio_active=false\n";
    echo "   Veuillez d'abord activer une visio via le coordinateur\n";
    exit(0);
}

echo "✅ Séance trouvée : ID {$seance->klassci_seance_id}\n";
echo "   Matière : " . ($seance->matiere_nom ?? 'N/A') . "\n";
echo "   Created at : {$seance->created_at}\n";
echo "   Updated at : {$seance->updated_at}\n\n";

echo "📊 État actuel de la séance :\n";
echo "   visio_enabled        : " . ($seance->visio_enabled ? '✅ 1 (true)' : '❌ 0 (false)') . "\n";
echo "   visio_active         : " . ($seance->visio_active ? '⚠️ 1 (true)' : '✅ 0 (false)') . "\n";
echo "   visio_status         : " . ($seance->visio_status ?? 'NULL') . "\n";
echo "   visio_type           : " . ($seance->visio_type ?? 'NULL') . "\n";
echo "   visio_room_id        : " . ($seance->visio_room_id ?? 'NULL') . "\n";
echo "   visio_started_at     : " . ($seance->visio_started_at ?? 'NULL') . "\n";
echo "   visio_ended_at       : " . ($seance->visio_ended_at ?? 'NULL') . "\n";
echo "   klassci_matiere_id   : " . ($seance->klassci_matiere_id ?? 'NULL') . "\n";
echo "   klassci_classe_id    : " . ($seance->klassci_classe_id ?? 'NULL') . "\n";
echo "   klassci_enseignant_id: " . ($seance->klassci_enseignant_id ?? 'NULL') . "\n";
echo "   created_by           : " . ($seance->created_by ?? 'NULL') . "\n\n";

// 2. Vérifier le code de startVisioSeance
echo "2️⃣ Vérification du code startVisioSeance()...\n\n";

$controllerPath = 'C:/Users/USER PC/Documents/propre à moi/lms-backend/app/Http/Controllers/API/LMSDataController.php';
$controllerContent = file_get_contents($controllerPath);

$startVisioPos = strpos($controllerContent, 'public function startVisioSeance');
if ($startVisioPos === false) {
    echo "❌ Fonction startVisioSeance non trouvée !\n";
    exit(1);
}

$startVisioCode = substr($controllerContent, $startVisioPos, 3000);

// Vérifier les conditions requises
echo "🔍 Conditions vérifiées dans startVisioSeance() :\n\n";

$checks = [
    'visio_enabled' => strpos($startVisioCode, 'visio_enabled') !== false,
    'visio_active' => strpos($startVisioCode, 'visio_active') !== false,
    'klassci_enseignant_id' => strpos($startVisioCode, 'klassci_enseignant_id') !== false,
    'role enseignant' => strpos($startVisioCode, "role, ['enseignant'") !== false ||
                         strpos($startVisioCode, "role === 'enseignant'") !== false,
];

foreach ($checks as $condition => $found) {
    $status = $found ? '✅ VÉRIFIE' : '⚠️ PAS VÉRIFIÉ';
    echo "   {$status} : {$condition}\n";
}

echo "\n";

// 3. Simuler l'appel startVisioSeance
echo "3️⃣ Simulation de l'appel startVisioSeance()...\n\n";

// Trouver un enseignant
$enseignant = \App\Models\User::where('role', 'enseignant')
    ->whereNotNull('klassci_token')
    ->first();

if (!$enseignant) {
    echo "❌ Aucun enseignant trouvé\n";
    exit(1);
}

echo "👨‍🏫 Enseignant : {$enseignant->name}\n";
echo "   ID : {$enseignant->id}\n";
echo "   Klassci ID : {$enseignant->klassci_id}\n\n";

// Vérifier les conditions une par une
echo "🎯 Vérification des conditions pour démarrer :\n\n";

$canStart = true;
$errors = [];

// Condition 1 : Séance existe
if (!$seance) {
    $canStart = false;
    $errors[] = "Séance non trouvée";
} else {
    echo "   ✅ Séance existe (ID {$seance->klassci_seance_id})\n";
}

// Condition 2 : Visio activée
if (!$seance->visio_enabled) {
    $canStart = false;
    $errors[] = "Visio non activée (visio_enabled=false)";
} else {
    echo "   ✅ Visio activée (visio_enabled=true)\n";
}

// Condition 3 : Visio pas déjà active
if ($seance->visio_active) {
    $canStart = false;
    $errors[] = "Visio déjà active (visio_active=true)";
} else {
    echo "   ✅ Visio pas encore active (visio_active=false)\n";
}

// Condition 4 : Room ID existe
if (!$seance->visio_room_id) {
    $canStart = false;
    $errors[] = "Room ID manquant";
} else {
    echo "   ✅ Room ID existe ({$seance->visio_room_id})\n";
}

// Condition 5 : Type visio défini
if (!$seance->visio_type) {
    echo "   ⚠️ Type visio non défini (sera 'jitsi' par défaut)\n";
} else {
    echo "   ✅ Type visio défini ({$seance->visio_type})\n";
}

// Condition 6 : Enseignant (pas de vérification stricte normalement)
if ($seance->klassci_enseignant_id && $seance->klassci_enseignant_id != $enseignant->klassci_id) {
    echo "   ⚠️ Enseignant différent de celui assigné\n";
    echo "      Assigné : {$seance->klassci_enseignant_id}\n";
    echo "      Actuel  : {$enseignant->klassci_id}\n";
    echo "      Note : La règle dit qu'on ne doit PAS bloquer dans ce cas\n";
} else {
    echo "   ✅ Pas de restriction enseignant (klassci_enseignant_id NULL ou match)\n";
}

echo "\n";

if ($canStart) {
    echo "✅ TOUTES LES CONDITIONS SONT REMPLIES\n";
    echo "   L'enseignant DEVRAIT pouvoir démarrer la visio\n\n";
} else {
    echo "❌ CONDITIONS NON REMPLIES\n";
    echo "   Erreurs :\n";
    foreach ($errors as $error) {
        echo "   - {$error}\n";
    }
    echo "\n";
}

// 4. Vérifier la route API
echo "4️⃣ Vérification de la route API...\n\n";

$routesPath = 'C:/Users/USER PC/Documents/propre à moi/lms-backend/routes/api.php';
$routesContent = file_get_contents($routesPath);

$hasStartVisioRoute = strpos($routesContent, 'start-visio') !== false ||
                      strpos($routesContent, 'startVisioSeance') !== false;

echo "   " . ($hasStartVisioRoute ? '✅' : '❌') . " Route POST /seances/{id}/start-visio : " .
     ($hasStartVisioRoute ? 'EXISTE' : 'MANQUANTE') . "\n\n";

if (!$hasStartVisioRoute) {
    echo "   ⚠️ La route pourrait être manquante !\n";
    echo "   Vérifier dans routes/api.php\n\n";
}

// 5. Vérifier le frontend
echo "5️⃣ Vérification du frontend (TeacherVisioList.vue)...\n\n";

$frontendPath = 'C:/Users/USER PC/Documents/propre à moi/lms-frontend/src/views/teacher/TeacherVisioList.vue';
if (file_exists($frontendPath)) {
    $frontendContent = file_get_contents($frontendPath);

    $hasStartFunction = strpos($frontendContent, 'handleStartVisio') !== false;
    $hasStartAPI = strpos($frontendContent, 'startVisio') !== false ||
                   strpos($frontendContent, 'start-visio') !== false;

    echo "   " . ($hasStartFunction ? '✅' : '❌') . " Fonction handleStartVisio : " .
         ($hasStartFunction ? 'EXISTE' : 'MANQUANTE') . "\n";
    echo "   " . ($hasStartAPI ? '✅' : '❌') . " Appel API startVisio : " .
         ($hasStartAPI ? 'EXISTE' : 'MANQUANT') . "\n\n";
}

// 6. Instructions de débogage
echo "═══════════════════════════════════════════════════════════════\n";
echo "   INSTRUCTIONS DE DÉBOGAGE\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

echo "🔍 ÉTAPE 1 : Vérifier côté frontend\n";
echo "   1. Se connecter en tant qu'enseignant\n";
echo "   2. Aller dans Visioconférences\n";
echo "   3. Vider le cache : F12 > Application > Local Storage > Supprimer 'teacher_visio_cache'\n";
echo "   4. Rafraîchir (F5)\n";
echo "   5. Ouvrir F12 > Console\n";
echo "   6. Cliquer sur 'Démarrer la visio'\n";
echo "   7. Noter l'erreur affichée dans la console\n\n";

echo "🔍 ÉTAPE 2 : Vérifier la requête API\n";
echo "   1. F12 > Network\n";
echo "   2. Cliquer sur 'Démarrer la visio'\n";
echo "   3. Chercher la requête 'start-visio' ou 'startVisio'\n";
echo "   4. Vérifier :\n";
echo "      - Status code (200 = OK, 400/403/500 = erreur)\n";
echo "      - Response (message d'erreur)\n";
echo "      - Request payload (données envoyées)\n\n";

echo "🔍 ÉTAPE 3 : Vérifier les logs backend\n";
echo "   1. Ouvrir : storage/logs/laravel.log\n";
echo "   2. Chercher : 'Erreur démarrage visio'\n";
echo "   3. Chercher : 'startVisioSeance'\n";
echo "   4. Noter le message d'erreur exact\n\n";

echo "📋 INFORMATIONS À PARTAGER :\n";
echo "   - Message d'erreur dans la console (F12 > Console)\n";
echo "   - Status code de la requête API (F12 > Network)\n";
echo "   - Réponse JSON de l'API\n";
echo "   - Logs Laravel (storage/logs/laravel.log)\n\n";

echo "═══════════════════════════════════════════════════════════════\n";
