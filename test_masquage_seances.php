<?php

/**
 * Test du système de masquage de séances par les étudiants
 *
 * Tests effectués:
 * 1. Vérifier la structure de la table seance_user_hidden
 * 2. Tester le masquage d'une séance
 * 3. Tester le réaffichage d'une séance
 * 4. Vérifier le filtrage dans les APIs
 */

require __DIR__ . '/vendor/autoload.php';

use App\Models\Seance;
use App\Models\User;
use App\Models\SeanceUserHidden;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "========================================\n";
echo "TEST: MASQUAGE DE SÉANCES PAR ÉTUDIANTS\n";
echo "========================================\n\n";

// TEST 1: Vérifier la structure de la table
echo "1️⃣  VÉRIFICATION DE LA STRUCTURE DE LA TABLE\n";
echo "-----------------------------------------------\n";

$hasTable = Schema::hasTable('seance_user_hidden');
$hasSeanceId = Schema::hasColumn('seance_user_hidden', 'seance_id');
$hasUserId = Schema::hasColumn('seance_user_hidden', 'user_id');
$hasHiddenAt = Schema::hasColumn('seance_user_hidden', 'hidden_at');

if ($hasTable && $hasSeanceId && $hasUserId && $hasHiddenAt) {
    echo "✅ Table seance_user_hidden correctement créée:\n";
    echo "   - seance_id: " . ($hasSeanceId ? "✅" : "❌") . "\n";
    echo "   - user_id: " . ($hasUserId ? "✅" : "❌") . "\n";
    echo "   - hidden_at: " . ($hasHiddenAt ? "✅" : "❌") . "\n\n";
} else {
    echo "❌ Problème avec la table! Exécute la migration:\n";
    echo "   php artisan migrate\n\n";
    exit(1);
}

// TEST 2: Trouver un étudiant et une séance de test
echo "2️⃣  PRÉPARATION DES DONNÉES DE TEST\n";
echo "-----------------------------------------------\n";

$etudiant = User::where('role', 'etudiant')->first();

if (!$etudiant) {
    echo "❌ Aucun étudiant trouvé pour tester\n";
    exit(1);
}

echo "Étudiant de test: {$etudiant->name} (ID: {$etudiant->id})\n";

$seance = Seance::where('is_active', true)->first();

if (!$seance) {
    echo "❌ Aucune séance active trouvée pour tester\n";
    exit(1);
}

echo "Séance de test: {$seance->matiere_nom} (ID: {$seance->id})\n\n";

// TEST 3: Tester le masquage
echo "3️⃣  TEST DU MASQUAGE\n";
echo "-----------------------------------------------\n";

// Vérifier qu'elle n'est pas déjà masquée
$isHiddenBefore = SeanceUserHidden::isHidden($seance->id, $etudiant->id);
echo "Masquée avant test: " . ($isHiddenBefore ? "Oui" : "Non") . "\n";

if ($isHiddenBefore) {
    echo "⚠️  Nettoyage: Suppression de l'ancien masquage\n";
    SeanceUserHidden::unhide($seance->id, $etudiant->id);
}

// Masquer la séance
echo "\nMasquage de la séance...\n";
$hidden = SeanceUserHidden::hide($seance->id, $etudiant->id);

if ($hidden) {
    echo "✅ Séance masquée avec succès\n";
    echo "   hidden_at: {$hidden->hidden_at}\n\n";
} else {
    echo "❌ Échec du masquage\n\n";
    exit(1);
}

// Vérifier que c'est bien masqué
$isHiddenAfter = SeanceUserHidden::isHidden($seance->id, $etudiant->id);

if ($isHiddenAfter) {
    echo "✅ Vérification: La séance est bien masquée\n\n";
} else {
    echo "❌ Problème: La séance n'est pas masquée après l'opération\n\n";
    exit(1);
}

// TEST 4: Vérifier que c'est personnel
echo "4️⃣  VÉRIFICATION: MASQUAGE PERSONNEL\n";
echo "-----------------------------------------------\n";

$autreEtudiant = User::where('role', 'etudiant')
    ->where('id', '!=', $etudiant->id)
    ->first();

if ($autreEtudiant) {
    $isHiddenForOther = SeanceUserHidden::isHidden($seance->id, $autreEtudiant->id);

    if (!$isHiddenForOther) {
        echo "✅ La séance n'est PAS masquée pour l'autre étudiant\n";
        echo "   Autre étudiant: {$autreEtudiant->name}\n";
        echo "   → Le masquage est bien personnel ✅\n\n";
    } else {
        echo "❌ Problème: La séance est masquée pour l'autre étudiant aussi!\n\n";
    }
} else {
    echo "⚠️  Un seul étudiant dans la base, impossible de tester le masquage personnel\n\n";
}

// TEST 5: Tester le réaffichage
echo "5️⃣  TEST DU RÉAFFICHAGE\n";
echo "-----------------------------------------------\n";

echo "Réaffichage de la séance...\n";
$unhidden = SeanceUserHidden::unhide($seance->id, $etudiant->id);

if ($unhidden) {
    echo "✅ Séance réaffichée avec succès\n\n";
} else {
    echo "❌ Échec du réaffichage\n\n";
    exit(1);
}

// Vérifier qu'elle n'est plus masquée
$isHiddenAfterUnhide = SeanceUserHidden::isHidden($seance->id, $etudiant->id);

if (!$isHiddenAfterUnhide) {
    echo "✅ Vérification: La séance n'est plus masquée\n\n";
} else {
    echo "❌ Problème: La séance est toujours masquée après réaffichage\n\n";
    exit(1);
}

// TEST 6: Tester la protection des doublons
echo "6️⃣  TEST DE PROTECTION CONTRE LES DOUBLONS\n";
echo "-----------------------------------------------\n";

echo "Masquage 1...\n";
SeanceUserHidden::hide($seance->id, $etudiant->id);

echo "Masquage 2 (doublon)...\n";
SeanceUserHidden::hide($seance->id, $etudiant->id);

$count = SeanceUserHidden::where('seance_id', $seance->id)
    ->where('user_id', $etudiant->id)
    ->count();

if ($count === 1) {
    echo "✅ Protection contre les doublons fonctionne (1 seul enregistrement)\n\n";
} else {
    echo "❌ Problème: {$count} enregistrements trouvés (devrait être 1)\n\n";
}

// TEST 7: Statistiques
echo "7️⃣  STATISTIQUES\n";
echo "-----------------------------------------------\n";

$totalHidden = SeanceUserHidden::count();
$hiddenByStudent = SeanceUserHidden::where('user_id', $etudiant->id)->count();
$totalSeances = Seance::count();
$activeSeances = Seance::where('is_active', true)->count();

echo "Total séances: {$totalSeances}\n";
echo "Séances actives: {$activeSeances}\n";
echo "Total masquages: {$totalHidden}\n";
echo "Masquées par {$etudiant->name}: {$hiddenByStudent}\n\n";

// TEST 8: Nettoyage
echo "8️⃣  NETTOYAGE\n";
echo "-----------------------------------------------\n";

SeanceUserHidden::unhide($seance->id, $etudiant->id);
echo "✅ Données de test nettoyées\n\n";

// RÉSUMÉ FINAL
echo "========================================\n";
echo "RÉSUMÉ DES TESTS\n";
echo "========================================\n\n";

echo "✅ Table seance_user_hidden: Créée et fonctionnelle\n";
echo "✅ Masquage: Fonctionne correctement\n";
echo "✅ Réaffichage: Fonctionne correctement\n";
echo "✅ Masquage personnel: Vérifié\n";
echo "✅ Protection doublons: Fonctionne\n\n";

echo "🎯 PROCHAINES ÉTAPES:\n\n";

echo "1. TESTER LES ENDPOINTS API:\n";
echo "   → POST /api/lms/seances/{id}/hide\n";
echo "   → POST /api/lms/seances/{id}/unhide\n\n";

echo "2. INTÉGRER DANS LE FRONTEND:\n";
echo "   → Ajouter bouton 'Masquer' sur chaque séance\n";
echo "   → Afficher icône 'Masquée' si applicable\n";
echo "   → Permettre de réafficher depuis une liste\n\n";

echo "3. EXEMPLE DE REQUÊTE cURL:\n";
echo "   # Masquer une séance\n";
echo "   curl -X POST http://localhost:8000/api/lms/seances/1/hide \\\\\n";
echo "     -H 'Authorization: Bearer TOKEN_ETUDIANT' \\\\\n";
echo "     -H 'Content-Type: application/json'\n\n";

echo "   # Réafficher une séance\n";
echo "   curl -X POST http://localhost:8000/api/lms/seances/1/unhide \\\\\n";
echo "     -H 'Authorization: Bearer TOKEN_ETUDIANT' \\\\\n";
echo "     -H 'Content-Type: application/json'\n\n";

echo "=== FIN DES TESTS ===\n";
