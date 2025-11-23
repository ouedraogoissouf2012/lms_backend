<?php

/**
 * Test détaillé de la séance 60 pour comprendre pourquoi elle n'apparaît pas
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "═══════════════════════════════════════════════════════════════\n";
echo "   TEST DÉTAILLÉ : Séance 60 (Algorithme)\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// 1. Récupérer la séance dans la BDD
$seance = \App\Models\Seance::where('klassci_seance_id', 60)->first();

if (!$seance) {
    echo "❌ Séance 60 non trouvée dans la BDD locale\n";
    exit(1);
}

echo "✅ Séance 60 trouvée dans la BDD\n\n";
echo "📋 Détails BDD :\n";
echo "   ID local         : {$seance->id}\n";
echo "   klassci_seance_id : {$seance->klassci_seance_id}\n";
echo "   klassci_matiere_id : " . ($seance->klassci_matiere_id ?? 'NULL') . "\n";
echo "   klassci_classe_id : " . ($seance->klassci_classe_id ?? 'NULL') . "\n";
echo "   klassci_enseignant_id : " . ($seance->klassci_enseignant_id ?? 'NULL') . "\n";
echo "   matiere_nom      : " . ($seance->matiere_nom ?? 'NULL') . "\n";
echo "   enseignant_nom   : " . ($seance->enseignant_nom ?? 'NULL') . "\n";
echo "   visio_enabled    : " . ($seance->visio_enabled ? 'TRUE' : 'FALSE') . "\n";
echo "   visio_active     : " . ($seance->visio_active ? 'TRUE' : 'FALSE') . "\n";
echo "   visio_status     : " . ($seance->visio_status ?? 'NULL') . "\n";
echo "   visio_room_id    : " . ($seance->visio_room_id ?? 'NULL') . "\n";
echo "   created_at       : {$seance->created_at}\n";
echo "   updated_at       : {$seance->updated_at}\n\n";

// 2. Vérifier si klassci_matiere_id=2 existe dans une matière d'un enseignant
echo "🔍 Vérification : Cette séance apparaîtra-t-elle dans myTeachingSeances() ?\n\n";

if (!$seance->klassci_matiere_id) {
    echo "❌ klassci_matiere_id est NULL\n";
    echo "   La séance ne peut pas être associée à une matière\n";
    echo "   Elle N'APPARAÎTRA PAS dans myTeachingSeances()\n\n";
    exit(0);
}

echo "   klassci_matiere_id = {$seance->klassci_matiere_id}\n";
echo "   Cette séance apparaîtra dans myTeachingSeances() SI :\n";
echo "   - L'enseignant enseigne la matière ID {$seance->klassci_matiere_id}\n";
echo "   - L'API Klassci /me/teacher-dashboard inclut cette matière\n";
echo "   - L'API Klassci /matieres/{$seance->klassci_matiere_id} inclut la séance 60\n\n";

// 3. Chercher les enseignants qui enseignent la matière 2
echo "👨‍🏫 Enseignants qui pourraient voir cette séance :\n\n";

// Regarder dans la table matiere_enseignant
$matiereEnseignants = \App\Models\MatiereEnseignant::where('klassci_matiere_id', $seance->klassci_matiere_id)
    ->with('user')
    ->get();

if ($matiereEnseignants->isEmpty()) {
    echo "   ℹ️  Aucune association trouvée dans matiere_enseignant\n";
    echo "   (Cette table n'est peut-être pas utilisée)\n\n";
} else {
    foreach ($matiereEnseignants as $me) {
        if ($me->user) {
            echo "   - {$me->user->name} (ID: {$me->user->id}, klassci_id: {$me->user->klassci_id})\n";
        }
    }
    echo "\n";
}

// 4. Simuler la réponse API que le frontend devrait recevoir
echo "📤 Réponse API que le frontend devrait recevoir :\n\n";

$apiSimulation = [
    'id' => $seance->klassci_seance_id,
    'matiere' => [
        'id' => $seance->klassci_matiere_id,
        'nom' => $seance->matiere_nom
    ],
    'programmation' => [
        'date' => '2025-11-21',  // Exemple
        'heure_debut' => '08:00',
        'heure_fin' => '10:00'
    ],
    // 🔑 CHAMPS CRITIQUES
    'visio_enabled' => $seance->visio_enabled,
    'visio_active' => $seance->visio_active,
    'visio_status' => $seance->visio_status,
    'visio_room_id' => $seance->visio_room_id,
    'visio' => [
        'enabled' => $seance->visio_enabled,
        'active' => $seance->visio_active,
        'status' => $seance->visio_status,
        'room_id' => $seance->visio_room_id
    ]
];

echo json_encode($apiSimulation, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo "\n\n";

// 5. Test condition frontend
echo "🎯 Test de la condition v-if dans TeacherVisioList.vue :\n";
echo "   v-if=\"seance.visio_enabled && !seance.visio_active\"\n\n";

$result = $seance->visio_enabled && !$seance->visio_active;

echo "   seance.visio_enabled = " . ($seance->visio_enabled ? 'true' : 'false') . "\n";
echo "   !seance.visio_active = " . (!$seance->visio_active ? 'true' : 'false') . "\n";
echo "   Résultat final = " . ($result ? 'true ✅' : 'false ❌') . "\n\n";

if ($result) {
    echo "   ✅ Le bouton 'Démarrer la visio' DEVRAIT être visible\n\n";
} else {
    echo "   ❌ Le bouton ne sera PAS visible\n\n";
}

// 6. Instructions de débogage
echo "═══════════════════════════════════════════════════════════════\n";
echo "   INSTRUCTIONS DE DÉBOGAGE\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

echo "1️⃣ Vérifier que l'enseignant voit la séance dans la liste :\n";
echo "   - Se connecter en tant qu'enseignant qui enseigne Algorithme (matière ID 2)\n";
echo "   - Aller dans Visioconférences\n";
echo "   - Vérifier que la séance 60 apparaît dans la liste\n\n";

echo "2️⃣ Vider le cache du navigateur :\n";
echo "   - F12 > Application > Local Storage\n";
echo "   - Supprimer 'teacher_visio_cache'\n";
echo "   - Rafraîchir (F5)\n\n";

echo "3️⃣ Vérifier la réponse API dans Network :\n";
echo "   - F12 > Network\n";
echo "   - Chercher 'my-teaching-seances'\n";
echo "   - Vérifier que la réponse contient :\n";
echo "     { \"id\": 60, \"visio_enabled\": true, \"visio_active\": false }\n\n";

echo "4️⃣ Vérifier les logs dans Console :\n";
echo "   - F12 > Console\n";
echo "   - Chercher les logs de chargement des séances\n";
echo "   - Vérifier s'il y a des erreurs\n\n";

echo "═══════════════════════════════════════════════════════════════\n";
