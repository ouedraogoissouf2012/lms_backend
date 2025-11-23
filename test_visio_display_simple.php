<?php

/**
 * Test simple : Vérifier ce qu'une séance contient dans la BDD
 * et ce qui sera envoyé au frontend
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "═══════════════════════════════════════════════════════════════\n";
echo "   DIAGNOSTIC : Pourquoi le bouton n'est pas visible ?\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// 1. Chercher une séance avec visio activée
echo "1️⃣ Recherche d'une séance avec visio activée...\n\n";

$seance = \App\Models\Seance::where('visio_enabled', true)
    ->where('visio_active', false)
    ->orderBy('updated_at', 'desc')
    ->first();

if (!$seance) {
    echo "❌ Aucune séance avec visio_enabled=true et visio_active=false\n";
    echo "   Il faut d'abord qu'un coordinateur active une visio\n";
    exit(0);
}

echo "✅ Séance trouvée : ID {$seance->klassci_seance_id}\n";
echo "   Matière : " . ($seance->matiere_nom ?? 'N/A') . "\n";
echo "   Enseignant : " . ($seance->enseignant_nom ?? 'N/A') . "\n\n";

// 2. État dans la BDD
echo "2️⃣ État dans la BDD (table seances) :\n";
echo "   visio_enabled    : " . ($seance->visio_enabled ? '✅ 1 (true)' : '❌ 0 (false)') . "\n";
echo "   visio_active     : " . ($seance->visio_active ? '⚠️ 1 (true)' : '✅ 0 (false)') . "\n";
echo "   visio_status     : " . ($seance->visio_status ?? 'NULL') . "\n";
echo "   visio_room_id    : " . ($seance->visio_room_id ?? 'NULL') . "\n";
echo "   klassci_matiere_id : " . ($seance->klassci_matiere_id ?? 'NULL') . "\n";
echo "   klassci_enseignant_id : " . ($seance->klassci_enseignant_id ?? 'NULL') . "\n\n";

// 3. Ce que l'API devrait retourner (simulation)
echo "3️⃣ Ce que l'API myTeachingSeances() devrait retourner :\n\n";

$apiResponse = [
    'id' => $seance->klassci_seance_id,
    'matiere' => ['nom' => $seance->matiere_nom],
    'visio_enabled' => $seance->visio_enabled,
    'visio_active' => $seance->visio_active,
    'visio_status' => $seance->visio_status,
    'visio_room_id' => $seance->visio_room_id,
    'visio' => [
        'enabled' => $seance->visio_enabled,
        'active' => $seance->visio_active,
        'status' => $seance->visio_status,
        'room_id' => $seance->visio_room_id,
    ]
];

echo json_encode($apiResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo "\n\n";

// 4. Test de la condition frontend
echo "4️⃣ Test de la condition frontend :\n";
echo "   Condition : v-if=\"seance.visio_enabled && !seance.visio_active\"\n\n";

$visioEnabled = $seance->visio_enabled;
$visioActive = $seance->visio_active;
$shouldShow = $visioEnabled && !$visioActive;

echo "   seance.visio_enabled = " . ($visioEnabled ? 'true' : 'false') . "\n";
echo "   seance.visio_active  = " . ($visioActive ? 'true' : 'false') . "\n";
echo "   !seance.visio_active = " . (!$visioActive ? 'true' : 'false') . "\n";
echo "   Condition && = " . ($shouldShow ? 'true' : 'false') . "\n\n";

if ($shouldShow) {
    echo "   ✅ RÉSULTAT : Le bouton 'Démarrer la visio' DEVRAIT être visible\n\n";
} else {
    echo "   ❌ RÉSULTAT : Le bouton 'Démarrer la visio' NE SERA PAS visible\n\n";
    echo "   🔍 Raisons possibles :\n";
    if (!$visioEnabled) {
        echo "      → visio_enabled = false (il faut que le coordinateur active)\n";
    }
    if ($visioActive) {
        echo "      → visio_active = true (visio déjà démarrée)\n";
    }
}

// 5. Vérifier le cache frontend
echo "5️⃣ Vérification du problème de cache :\n\n";
echo "   Le frontend utilise un cache de 5 minutes (clé: 'teacher_visio_cache')\n";
echo "   Si les données étaient en cache AVANT la correction de l'API,\n";
echo "   le frontend continue d'utiliser les anciennes données.\n\n";

echo "   ⚠️  ACTIONS REQUISES :\n";
echo "   1. Ouvrir la console du navigateur (F12)\n";
echo "   2. Aller dans Application > Local Storage\n";
echo "   3. Supprimer la clé 'teacher_visio_cache'\n";
echo "   4. Rafraîchir la page (F5)\n\n";

echo "   OU attendre 5 minutes que le cache expire\n\n";

// 6. Vérifier si l'enseignant correspond
echo "6️⃣ Vérification de l'enseignant :\n\n";

if (!$seance->klassci_enseignant_id) {
    echo "   ⚠️  klassci_enseignant_id est NULL\n";
    echo "   Cette séance n'est pas associée à un enseignant spécifique\n";
    echo "   Elle devrait quand même apparaître dans la liste\n\n";
} else {
    $enseignant = \App\Models\User::where('klassci_id', $seance->klassci_enseignant_id)
        ->where('role', 'enseignant')
        ->first();

    if ($enseignant) {
        echo "   ✅ Enseignant trouvé : {$enseignant->name}\n";
        echo "      Email : {$enseignant->email}\n";
        echo "      Cette séance devrait apparaître dans sa liste\n\n";
    } else {
        echo "   ⚠️  Aucun enseignant trouvé avec klassci_id = {$seance->klassci_enseignant_id}\n";
        echo "   Cette séance n'apparaîtra pas dans myTeachingSeances()\n\n";
    }
}

// 7. Diagnostic final
echo "7️⃣ DIAGNOSTIC FINAL :\n\n";

$issues = [];

if (!$seance->visio_enabled) {
    $issues[] = "visio_enabled est false → Le coordinateur doit activer la visio";
}

if ($seance->visio_active) {
    $issues[] = "visio_active est true → La visio est déjà démarrée, le bouton affiche 'Terminer'";
}

if (!$seance->klassci_matiere_id) {
    $issues[] = "klassci_matiere_id est NULL → La séance n'apparaîtra pas dans myTeachingSeances()";
}

if (empty($issues)) {
    echo "   ✅ TOUT EST CORRECT DANS LA BDD\n\n";
    echo "   Si le bouton n'est toujours pas visible, le problème vient du :\n";
    echo "   1. Cache frontend (vider le localStorage)\n";
    echo "   2. API qui ne retourne pas les bons champs\n";
    echo "   3. Frontend qui ne reçoit pas les données\n\n";

    echo "   🔧 PROCHAINE ÉTAPE :\n";
    echo "   1. Vider le cache du navigateur (localStorage)\n";
    echo "   2. Ouvrir la console du navigateur (F12)\n";
    echo "   3. Aller dans l'onglet Network\n";
    echo "   4. Rafraîchir la page\n";
    echo "   5. Chercher la requête 'my-teaching-seances'\n";
    echo "   6. Vérifier la réponse JSON\n";
    echo "   7. Vérifier si les champs visio_enabled et visio_active sont présents\n\n";
} else {
    echo "   ⚠️  PROBLÈMES DÉTECTÉS :\n\n";
    foreach ($issues as $idx => $issue) {
        echo "   " . ($idx + 1) . ". " . $issue . "\n";
    }
    echo "\n";
}

echo "═══════════════════════════════════════════════════════════════\n";
