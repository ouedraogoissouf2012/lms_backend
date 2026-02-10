<?php

/**
 * Script de diagnostic pour les séances et notifications
 *
 * Usage: php diagnostic_seances_notifications.php
 *
 * Ce script vérifie:
 * 1. Les séances dans la BDD locale
 * 2. Les classes synchronisées
 * 3. Les étudiants liés aux classes
 * 4. Les notifications envoyées
 */

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Seance;
use App\Models\Classe;
use App\Models\User;
use App\Models\Notification;

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║     DIAGNOSTIC SÉANCES, CLASSES ET NOTIFICATIONS                 ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

// ============================================================
// 1. SÉANCES DANS LA BDD
// ============================================================
echo "┌──────────────────────────────────────────────────────────────────┐\n";
echo "│ 1. SÉANCES DANS LA TABLE 'seances'                              │\n";
echo "└──────────────────────────────────────────────────────────────────┘\n\n";

$seances = Seance::orderBy('created_at', 'desc')->limit(10)->get();

if ($seances->isEmpty()) {
    echo "   ⚠️  Aucune séance trouvée dans la BDD\n\n";
} else {
    echo "   Dernières 10 séances:\n\n";

    foreach ($seances as $seance) {
        $status = $seance->is_active ? '✅ Active' : '❌ Archivée';
        $visio = $seance->visio_enabled ? '🟢 Visio ON' : '🔴 Visio OFF';

        echo "   ┌─────────────────────────────────────────────────────────────\n";
        echo "   │ ID Local: {$seance->id} | Klassci ID: {$seance->klassci_seance_id}\n";
        echo "   │ Matière: {$seance->matiere_nom}\n";
        echo "   │ Enseignant: {$seance->enseignant_nom}\n";
        echo "   │ Classe Klassci ID: {$seance->klassci_classe_id}\n";
        echo "   │ Status: {$status} | {$visio}\n";
        echo "   │ Visio Status: " . ($seance->visio_status ?? 'null') . "\n";
        echo "   │ Room ID: " . ($seance->visio_room_id ?? 'null') . "\n";
        echo "   │ Créée le: {$seance->created_at}\n";
        echo "   └─────────────────────────────────────────────────────────────\n\n";
    }
}

// Stats globales
$totalSeances = Seance::count();
$seancesActives = Seance::where('is_active', true)->count();
$seancesVisioEnabled = Seance::where('visio_enabled', true)->count();
$seancesProgrammees = Seance::where('visio_status', 'programmee')->count();

echo "   📊 STATISTIQUES:\n";
echo "   ├─ Total séances: {$totalSeances}\n";
echo "   ├─ Séances actives (is_active=true): {$seancesActives}\n";
echo "   ├─ Séances avec visio activée: {$seancesVisioEnabled}\n";
echo "   └─ Séances programmées: {$seancesProgrammees}\n\n";

// ============================================================
// 2. CLASSES SYNCHRONISÉES
// ============================================================
echo "┌──────────────────────────────────────────────────────────────────┐\n";
echo "│ 2. CLASSES SYNCHRONISÉES (table 'classes')                      │\n";
echo "└──────────────────────────────────────────────────────────────────┘\n\n";

$classes = Classe::orderBy('created_at', 'desc')->limit(10)->get();

if ($classes->isEmpty()) {
    echo "   ⚠️  Aucune classe trouvée dans la BDD\n\n";
} else {
    echo "   Dernières 10 classes:\n\n";

    foreach ($classes as $classe) {
        $etudiantsCount = $classe->etudiants()->count();
        $etudiantsActifs = $classe->etudiantsActifs()->count();

        echo "   ┌─────────────────────────────────────────────────────────────\n";
        echo "   │ ID Local: {$classe->id} | Klassci ID: {$classe->klassci_id}\n";
        echo "   │ Libellé: {$classe->libelle}\n";
        echo "   │ Code: " . ($classe->code ?? 'N/A') . "\n";
        echo "   │ Étudiants total: {$etudiantsCount} | Actifs: {$etudiantsActifs}\n";
        echo "   │ Dernière sync: " . ($classe->last_klassci_sync ?? 'Jamais') . "\n";
        echo "   └─────────────────────────────────────────────────────────────\n\n";
    }
}

$totalClasses = Classe::count();
echo "   📊 Total classes synchronisées: {$totalClasses}\n\n";

// ============================================================
// 3. VÉRIFICATION LIAISON SÉANCES <-> CLASSES
// ============================================================
echo "┌──────────────────────────────────────────────────────────────────┐\n";
echo "│ 3. LIAISON SÉANCES <-> CLASSES                                  │\n";
echo "└──────────────────────────────────────────────────────────────────┘\n\n";

$seancesWithVisio = Seance::where('visio_enabled', true)
    ->where('is_active', true)
    ->get();

foreach ($seancesWithVisio as $seance) {
    $classe = Classe::where('klassci_id', $seance->klassci_classe_id)->first();

    echo "   Séance ID {$seance->id} (Klassci: {$seance->klassci_seance_id}):\n";
    echo "   ├─ Klassci Classe ID: {$seance->klassci_classe_id}\n";

    if ($classe) {
        $etudiantsActifs = $classe->etudiantsActifs()->count();
        echo "   ├─ ✅ Classe trouvée: {$classe->libelle} (ID local: {$classe->id})\n";
        echo "   └─ 👥 Étudiants actifs: {$etudiantsActifs}\n";

        if ($etudiantsActifs == 0) {
            echo "      ⚠️  PROBLÈME: Aucun étudiant actif dans cette classe!\n";
        }
    } else {
        echo "   └─ ❌ PROBLÈME: Classe NON TROUVÉE dans la BDD locale!\n";
    }
    echo "\n";
}

// ============================================================
// 4. TABLE PIVOT CLASSE_ETUDIANT
// ============================================================
echo "┌──────────────────────────────────────────────────────────────────┐\n";
echo "│ 4. TABLE PIVOT 'classe_etudiant'                                │\n";
echo "└──────────────────────────────────────────────────────────────────┘\n\n";

$pivotStats = DB::table('classe_etudiant')
    ->select('statut', DB::raw('count(*) as count'))
    ->groupBy('statut')
    ->get();

if ($pivotStats->isEmpty()) {
    echo "   ⚠️  Table classe_etudiant VIDE - Aucun étudiant lié à des classes!\n\n";
} else {
    echo "   Répartition par statut:\n";
    foreach ($pivotStats as $stat) {
        $icon = $stat->statut === 'actif' ? '✅' : '⚪';
        echo "   ├─ {$icon} {$stat->statut}: {$stat->count}\n";
    }
    echo "\n";
}

$totalPivot = DB::table('classe_etudiant')->count();
echo "   📊 Total entrées classe_etudiant: {$totalPivot}\n\n";

// ============================================================
// 5. UTILISATEURS (ÉTUDIANTS)
// ============================================================
echo "┌──────────────────────────────────────────────────────────────────┐\n";
echo "│ 5. UTILISATEURS (ÉTUDIANTS)                                     │\n";
echo "└──────────────────────────────────────────────────────────────────┘\n\n";

$etudiantsTotal = User::where('role', 'etudiant')->count();
$etudiantsAvecKlassciId = User::where('role', 'etudiant')->whereNotNull('klassci_id')->count();
$etudiantsSansKlassciId = User::where('role', 'etudiant')->whereNull('klassci_id')->count();

echo "   📊 STATISTIQUES ÉTUDIANTS:\n";
echo "   ├─ Total étudiants: {$etudiantsTotal}\n";
echo "   ├─ Avec Klassci ID: {$etudiantsAvecKlassciId}\n";
echo "   └─ Sans Klassci ID: {$etudiantsSansKlassciId}\n\n";

// ============================================================
// 6. NOTIFICATIONS ENVOYÉES
// ============================================================
echo "┌──────────────────────────────────────────────────────────────────┐\n";
echo "│ 6. NOTIFICATIONS ENVOYÉES                                       │\n";
echo "└──────────────────────────────────────────────────────────────────┘\n\n";

// Vérifier si la table notifications existe et a des données
try {
    $notifTypes = DB::table('notifications')
        ->select('type', DB::raw('count(*) as count'))
        ->groupBy('type')
        ->get();

    if ($notifTypes->isEmpty()) {
        echo "   ⚠️  Aucune notification dans la BDD\n\n";
    } else {
        echo "   Répartition par type:\n";
        foreach ($notifTypes as $type) {
            echo "   ├─ {$type->type}: {$type->count}\n";
        }
        echo "\n";
    }

    // Dernières notifications visio
    $lastVisioNotifs = DB::table('notifications')
        ->where('type', 'visio_scheduled')
        ->orderBy('created_at', 'desc')
        ->limit(5)
        ->get();

    if ($lastVisioNotifs->isNotEmpty()) {
        echo "   Dernières notifications 'visio_scheduled':\n\n";
        foreach ($lastVisioNotifs as $notif) {
            $data = json_decode($notif->data, true);
            echo "   ┌─────────────────────────────────────────────────────────────\n";
            echo "   │ ID: {$notif->id}\n";
            echo "   │ User ID: {$notif->user_id}\n";
            echo "   │ Titre: {$notif->title}\n";
            echo "   │ Séance ID: " . ($data['seance_id'] ?? 'N/A') . "\n";
            echo "   │ Créée le: {$notif->created_at}\n";
            echo "   └─────────────────────────────────────────────────────────────\n\n";
        }
    }

    $totalNotifs = DB::table('notifications')->count();
    $visioNotifs = DB::table('notifications')->where('type', 'visio_scheduled')->count();
    echo "   📊 Total notifications: {$totalNotifs}\n";
    echo "   📊 Notifications visio_scheduled: {$visioNotifs}\n\n";

} catch (\Exception $e) {
    echo "   ❌ Erreur lecture notifications: " . $e->getMessage() . "\n\n";
}

// ============================================================
// 7. DIAGNOSTIC FINAL
// ============================================================
echo "┌──────────────────────────────────────────────────────────────────┐\n";
echo "│ 7. DIAGNOSTIC FINAL                                             │\n";
echo "└──────────────────────────────────────────────────────────────────┘\n\n";

$problems = [];

// Vérifier séances sans classe locale
$seancesSansClasse = Seance::where('visio_enabled', true)
    ->where('is_active', true)
    ->get()
    ->filter(function ($seance) {
        return !Classe::where('klassci_id', $seance->klassci_classe_id)->exists();
    });

if ($seancesSansClasse->count() > 0) {
    $problems[] = "❌ {$seancesSansClasse->count()} séance(s) avec visio activée mais SANS classe synchronisée";
}

// Vérifier classes sans étudiants
$classesSansEtudiants = Classe::all()->filter(function ($classe) {
    return $classe->etudiantsActifs()->count() == 0;
});

if ($classesSansEtudiants->count() > 0) {
    $problems[] = "⚠️  {$classesSansEtudiants->count()} classe(s) synchronisée(s) SANS étudiants actifs";
}

// Vérifier si des étudiants existent mais ne sont dans aucune classe
$etudiantsSansClasse = User::where('role', 'etudiant')
    ->whereDoesntHave('classes')
    ->count();

if ($etudiantsSansClasse > 0) {
    $problems[] = "⚠️  {$etudiantsSansClasse} étudiant(s) NON liés à aucune classe";
}

// Afficher problèmes
if (empty($problems)) {
    echo "   ✅ AUCUN PROBLÈME DÉTECTÉ!\n\n";
} else {
    echo "   PROBLÈMES DÉTECTÉS:\n\n";
    foreach ($problems as $problem) {
        echo "   {$problem}\n";
    }
    echo "\n";
}

// ============================================================
// 8. RECOMMANDATIONS
// ============================================================
echo "┌──────────────────────────────────────────────────────────────────┐\n";
echo "│ 8. RECOMMANDATIONS                                              │\n";
echo "└──────────────────────────────────────────────────────────────────┘\n\n";

if ($seancesSansClasse->count() > 0) {
    echo "   Pour synchroniser les classes manquantes:\n";
    echo "   → L'enseignant doit activer à nouveau la visio\n";
    echo "   → Ou exécuter: php artisan tinker\n";
    echo "     puis: App\\Jobs\\SyncKlassciSeances::dispatch()\n\n";
}

if ($classesSansEtudiants->count() > 0) {
    echo "   Pour synchroniser les étudiants:\n";
    echo "   → Vérifier que l'API KLASSCI /classes/{id} retourne les étudiants\n";
    echo "   → Les étudiants doivent se connecter au moins une fois\n\n";
}

echo "   Pour forcer la re-synchronisation d'une classe spécifique:\n";
echo "   → php artisan tinker\n";
echo "   → \$service = app(App\\Services\\ClasseSyncService::class);\n";
echo "   → \$service->syncClasseById(KLASSCI_CLASSE_ID, 'TOKEN_KLASSCI');\n\n";

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║                    FIN DU DIAGNOSTIC                             ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";
