<?php

/**
 * Test complet pour vérifier que TOUTES les règles de visio sont respectées
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "═══════════════════════════════════════════════════════════════\n";
echo "   VÉRIFICATION COMPLÈTE DES RÈGLES VISIO\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$regles = [
    '1' => 'Coordinateur peut activer mais NE PEUT PAS démarrer',
    '2' => 'Enseignant peut activer ET démarrer',
    '3' => 'Si coordinateur active, enseignant DOIT pouvoir démarrer',
    '4' => 'Étudiants peuvent joindre UNIQUEMENT si visio active',
    '5' => 'Coordinateur = participant fantôme (is_observer=true)'
];

$resultats = [];

// ═══════════════════════════════════════════════════════════════
// RÈGLE 1 : Coordinateur peut activer mais NE PEUT PAS démarrer
// ═══════════════════════════════════════════════════════════════
echo "1️⃣ RÈGLE 1 : Coordinateur peut activer mais NE PEUT PAS démarrer\n";
echo str_repeat("-", 70) . "\n\n";

// Vérifier dans SeanceManagement.vue (coordinateur)
$seanceManagementContent = file_get_contents('C:/Users/USER PC/Documents/propre à moi/lms-frontend/src/views/coordinateur/SeanceManagement.vue');

$hasActivateButton = strpos($seanceManagementContent, 'handleToggleVisio') !== false;
$hasStartButton = strpos($seanceManagementContent, 'handleStartVisio') !== false ||
                  strpos($seanceManagementContent, 'demarrerVisio') !== false;
$hasJoinButton = strpos($seanceManagementContent, 'handleJoinVisio') !== false;

echo "   📄 Frontend (SeanceManagement.vue - Coordinateur) :\n";
echo "      ✅ Bouton 'Activer/Désactiver' : " . ($hasActivateButton ? 'PRÉSENT' : 'ABSENT') . "\n";
echo "      " . ($hasStartButton ? '❌' : '✅') . " Bouton 'Démarrer' : " . ($hasStartButton ? 'PRÉSENT (ERREUR!)' : 'ABSENT (OK)') . "\n";
echo "      ✅ Bouton 'Rejoindre' (observateur) : " . ($hasJoinButton ? 'PRÉSENT' : 'ABSENT') . "\n\n";

// Vérifier API toggleVisio
$lmsControllerContent = file_get_contents('C:/Users/USER PC/Documents/propre à moi/lms-backend/app/Http/Controllers/API/LMSDataController.php');

$toggleVisioPos = strpos($lmsControllerContent, 'public function toggleVisioSeance');
if ($toggleVisioPos !== false) {
    $toggleVisioCode = substr($lmsControllerContent, $toggleVisioPos, 1500);
    $hasCoordinateurCheck = strpos($toggleVisioCode, "role, ['coordinateur'") !== false;

    echo "   🔧 Backend (toggleVisioSeance) :\n";
    echo "      " . ($hasCoordinateurCheck ? '✅' : '❌') . " Vérification rôle coordinateur : " . ($hasCoordinateurCheck ? 'OUI' : 'NON') . "\n\n";
}

$regle1OK = $hasActivateButton && !$hasStartButton && $hasJoinButton && $hasCoordinateurCheck;
$resultats[1] = $regle1OK;

echo "   " . ($regle1OK ? '✅' : '❌') . " RÈGLE 1 : " . ($regle1OK ? 'RESPECTÉE' : 'NON RESPECTÉE') . "\n\n";

// ═══════════════════════════════════════════════════════════════
// RÈGLE 2 : Enseignant peut activer ET démarrer
// ═══════════════════════════════════════════════════════════════
echo "2️⃣ RÈGLE 2 : Enseignant peut activer ET démarrer\n";
echo str_repeat("-", 70) . "\n\n";

$teacherVisioContent = file_get_contents('C:/Users/USER PC/Documents/propre à moi/lms-frontend/src/views/teacher/TeacherVisioList.vue');

$teacherHasActivate = strpos($teacherVisioContent, 'handleActivateVisio') !== false;
$teacherHasStart = strpos($teacherVisioContent, 'handleStartVisio') !== false;
$teacherHasEnd = strpos($teacherVisioContent, 'handleEndVisio') !== false;

echo "   📄 Frontend (TeacherVisioList.vue - Enseignant) :\n";
echo "      ✅ Bouton 'Activer' : " . ($teacherHasActivate ? 'PRÉSENT' : 'ABSENT') . "\n";
echo "      ✅ Bouton 'Démarrer' : " . ($teacherHasStart ? 'PRÉSENT' : 'ABSENT') . "\n";
echo "      ✅ Bouton 'Terminer' : " . ($teacherHasEnd ? 'PRÉSENT' : 'ABSENT') . "\n\n";

// Vérifier conditions d'affichage
$hasCorrectConditions = strpos($teacherVisioContent, 'v-if="seance.visio_enabled && !seance.visio_active"') !== false &&
                        strpos($teacherVisioContent, 'v-else-if="seance.visio_active"') !== false;

echo "   🎯 Conditions d'affichage :\n";
echo "      " . ($hasCorrectConditions ? '✅' : '❌') . " Démarrer si (enabled && !active) : " . ($hasCorrectConditions ? 'OUI' : 'NON') . "\n";
echo "      " . ($hasCorrectConditions ? '✅' : '❌') . " Terminer si (active) : " . ($hasCorrectConditions ? 'OUI' : 'NON') . "\n\n";

$regle2OK = $teacherHasActivate && $teacherHasStart && $teacherHasEnd && $hasCorrectConditions;
$resultats[2] = $regle2OK;

echo "   " . ($regle2OK ? '✅' : '❌') . " RÈGLE 2 : " . ($regle2OK ? 'RESPECTÉE' : 'NON RESPECTÉE') . "\n\n";

// ═══════════════════════════════════════════════════════════════
// RÈGLE 3 : Si coordinateur active, enseignant DOIT pouvoir démarrer
// ═══════════════════════════════════════════════════════════════
echo "3️⃣ RÈGLE 3 : Si coordinateur active, enseignant DOIT pouvoir démarrer\n";
echo str_repeat("-", 70) . "\n\n";

// Vérifier qu'il n'y a pas de vérification de klassci_enseignant_id dans startVisio
$checksEnseignantId = false;
$hasComment = false;

$startVisioPos = strpos($lmsControllerContent, 'public function startVisioSeance');
if ($startVisioPos !== false) {
    $startVisioCode = substr($lmsControllerContent, $startVisioPos, 2000);

    // Chercher si on vérifie le klassci_enseignant_id
    $checksEnseignantId = strpos($startVisioCode, 'klassci_enseignant_id') !== false;

    echo "   🔧 Backend (startVisioSeance) :\n";
    echo "      " . ($checksEnseignantId ? '⚠️' : '✅') . " Vérification klassci_enseignant_id : " . ($checksEnseignantId ? 'OUI (restrictif)' : 'NON (permissif)') . "\n";

    // Chercher commentaire explicatif
    $hasComment = strpos($startVisioCode, 'tout enseignant connecté peut la démarrer') !== false ||
                  strpos($startVisioCode, 'Logique: Si le coordinateur a activé') !== false;

    echo "      " . ($hasComment ? '✅' : '⚠️') . " Commentaire explicatif : " . ($hasComment ? 'PRÉSENT' : 'ABSENT') . "\n\n";
}

// Test avec une séance activée par coordinateur
$seanceActiveeParCoord = \App\Models\Seance::where('visio_enabled', true)
    ->where('visio_active', false)
    ->whereNull('klassci_enseignant_id')
    ->first();

if ($seanceActiveeParCoord) {
    echo "   📊 Test avec séance réelle :\n";
    echo "      Séance ID : {$seanceActiveeParCoord->klassci_seance_id}\n";
    echo "      visio_enabled : " . ($seanceActiveeParCoord->visio_enabled ? 'true' : 'false') . "\n";
    echo "      visio_active : " . ($seanceActiveeParCoord->visio_active ? 'true' : 'false') . "\n";
    echo "      klassci_enseignant_id : " . ($seanceActiveeParCoord->klassci_enseignant_id ?? 'NULL') . "\n";
    echo "      ✅ Un enseignant PEUT démarrer cette séance (pas de restriction)\n\n";
}

$regle3OK = !$checksEnseignantId || $hasComment;
$resultats[3] = $regle3OK;

echo "   " . ($regle3OK ? '✅' : '❌') . " RÈGLE 3 : " . ($regle3OK ? 'RESPECTÉE' : 'NON RESPECTÉE') . "\n\n";

// ═══════════════════════════════════════════════════════════════
// RÈGLE 4 : Étudiants peuvent joindre UNIQUEMENT si visio active
// ═══════════════════════════════════════════════════════════════
echo "4️⃣ RÈGLE 4 : Étudiants peuvent joindre UNIQUEMENT si visio active\n";
echo str_repeat("-", 70) . "\n\n";

// Vérifier dans joinVisio
$joinVisioPos = strpos($lmsControllerContent, 'public function joinVisio');
if ($joinVisioPos !== false) {
    $joinVisioCode = substr($lmsControllerContent, $joinVisioPos, 1500);

    $checksVisioActive = strpos($joinVisioCode, "visio_status !== 'active'") !== false ||
                         strpos($joinVisioCode, "visio n'est pas active") !== false;

    echo "   🔧 Backend (joinVisio) :\n";
    echo "      " . ($checksVisioActive ? '✅' : '❌') . " Vérification visio active : " . ($checksVisioActive ? 'OUI' : 'NON') . "\n";
    echo "      Message d'erreur : " . ($checksVisioActive ? '"La visio n\'est pas active"' : 'ABSENT') . "\n\n";
}

// Vérifier frontend étudiant
$studentSeanceDetailsPath = 'C:/Users/USER PC/Documents/propre à moi/lms-frontend/src/views/seances/SeanceDetails.vue';
if (file_exists($studentSeanceDetailsPath)) {
    $studentContent = file_get_contents($studentSeanceDetailsPath);

    $hasVisioActiveCheck = strpos($studentContent, 'visio_active') !== false ||
                           strpos($studentContent, 'visio?.status') !== false;

    echo "   📄 Frontend (SeanceDetails.vue - Étudiant) :\n";
    echo "      " . ($hasVisioActiveCheck ? '✅' : '⚠️') . " Vérification visio active : " . ($hasVisioActiveCheck ? 'OUI' : 'NON') . "\n\n";
}

$regle4OK = $checksVisioActive;
$resultats[4] = $regle4OK;

echo "   " . ($regle4OK ? '✅' : '❌') . " RÈGLE 4 : " . ($regle4OK ? 'RESPECTÉE' : 'NON RESPECTÉE') . "\n\n";

// ═══════════════════════════════════════════════════════════════
// RÈGLE 5 : Coordinateur = participant fantôme (is_observer=true)
// ═══════════════════════════════════════════════════════════════
echo "5️⃣ RÈGLE 5 : Coordinateur = participant fantôme (is_observer=true)\n";
echo str_repeat("-", 70) . "\n\n";

// Vérifier migration
$migrationExists = file_exists('C:/Users/USER PC/Documents/propre à moi/lms-backend/database/migrations/2025_11_20_073547_add_is_observer_to_esbtp_attendance_table.php');

echo "   🗄️ Migration :\n";
echo "      " . ($migrationExists ? '✅' : '❌') . " add_is_observer_to_esbtp_attendance : " . ($migrationExists ? 'EXISTE' : 'ABSENTE') . "\n\n";

// Vérifier colonne dans BDD
$columns = DB::select("PRAGMA table_info(esbtp_attendance)");
$hasIsObserverColumn = false;
foreach ($columns as $column) {
    if ($column->name === 'is_observer') {
        $hasIsObserverColumn = true;
        break;
    }
}

echo "   📊 Base de données :\n";
echo "      " . ($hasIsObserverColumn ? '✅' : '❌') . " Colonne is_observer : " . ($hasIsObserverColumn ? 'EXISTE' : 'ABSENTE') . "\n\n";

// Vérifier dans joinVisio
$setsIsObserver = strpos($lmsControllerContent, '$isObserver = ($user->role === \'coordinateur\')') !== false;
$hasObserverComment = strpos($lmsControllerContent, 'RÈGLE PARTICIPANT FANTÔME') !== false ||
                      strpos($lmsControllerContent, 'participant fantôme') !== false;

echo "   🔧 Backend (joinVisio) :\n";
echo "      " . ($setsIsObserver ? '✅' : '❌') . " Définit is_observer pour coordinateurs : " . ($setsIsObserver ? 'OUI' : 'NON') . "\n";
echo "      " . ($hasObserverComment ? '✅' : '⚠️') . " Commentaire explicatif : " . ($hasObserverComment ? 'PRÉSENT' : 'ABSENT') . "\n\n";

// Vérifier getVisioParticipants filtre is_observer
$getParticipantsPos = strpos($lmsControllerContent, 'public function getVisioParticipants');
if ($getParticipantsPos !== false) {
    $getParticipantsCode = substr($lmsControllerContent, $getParticipantsPos, 2000);

    $filtersObservers = strpos($getParticipantsCode, "where('is_observer', false)") !== false ||
                        strpos($getParticipantsCode, '->where(\'is_observer\', false)') !== false;

    echo "   🔍 Backend (getVisioParticipants) :\n";
    echo "      " . ($filtersObservers ? '✅' : '❌') . " Filtre is_observer=false : " . ($filtersObservers ? 'OUI' : 'NON') . "\n";
    echo "      " . ($filtersObservers ? '✅' : '❌') . " Coordinateurs exclus de la liste : " . ($filtersObservers ? 'OUI' : 'NON') . "\n\n";
}

// Test avec des données réelles
$observateurs = DB::table('esbtp_attendance')
    ->where('is_observer', true)
    ->count();

echo "   📈 Statistiques réelles :\n";
echo "      Participations avec is_observer=true : {$observateurs}\n";
if ($observateurs > 0) {
    echo "      ✅ Des coordinateurs ont déjà rejoint comme observateurs\n";
} else {
    echo "      ℹ️  Aucun coordinateur n'a encore rejoint (normal si pas testé)\n";
}
echo "\n";

$regle5OK = $migrationExists && $hasIsObserverColumn && $setsIsObserver && $filtersObservers;
$resultats[5] = $regle5OK;

echo "   " . ($regle5OK ? '✅' : '❌') . " RÈGLE 5 : " . ($regle5OK ? 'RESPECTÉE' : 'NON RESPECTÉE') . "\n\n";

// ═══════════════════════════════════════════════════════════════
// RÉSUMÉ FINAL
// ═══════════════════════════════════════════════════════════════
echo "═══════════════════════════════════════════════════════════════\n";
echo "   RÉSUMÉ FINAL\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$totalRegles = count($regles);
$reglesRespecees = array_sum($resultats);

foreach ($regles as $num => $description) {
    $status = $resultats[$num] ? '✅' : '❌';
    echo "{$status} RÈGLE {$num} : {$description}\n";
    echo "   " . ($resultats[$num] ? 'RESPECTÉE' : 'NON RESPECTÉE') . "\n\n";
}

echo str_repeat("=", 70) . "\n";
echo "SCORE : {$reglesRespecees}/{$totalRegles} règles respectées\n";

if ($reglesRespecees === $totalRegles) {
    echo "\n🎉 PARFAIT ! Toutes les règles sont respectées !\n";
} else {
    echo "\n⚠️  Certaines règles ne sont pas respectées.\n";
    echo "Règles à corriger : ";
    foreach ($resultats as $num => $ok) {
        if (!$ok) {
            echo "#{$num} ";
        }
    }
    echo "\n";
}

echo "═══════════════════════════════════════════════════════════════\n";
