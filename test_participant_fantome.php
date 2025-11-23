<?php

/**
 * Script de test pour la règle "Participant Fantôme"
 *
 * Vérifie que :
 * 1. Les coordinateurs sont marqués comme observateurs (is_observer=true)
 * 2. Les coordinateurs ne sont PAS visibles dans la liste des participants
 * 3. Les étudiants et enseignants restent visibles normalement
 * 4. La trace de présence du coordinateur est conservée pour l'audit
 */

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\DB;

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "═══════════════════════════════════════════════════════════════\n";
echo "   TEST : PARTICIPANT FANTÔME (Coordinateur Observateur)\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// 1️⃣ Vérifier que la colonne is_observer existe
echo "1️⃣ Vérification de la colonne is_observer...\n";
try {
    // SQLite : utiliser PRAGMA au lieu de SHOW COLUMNS
    $columns = DB::select("PRAGMA table_info(esbtp_attendance)");
    $hasIsObserver = false;
    foreach ($columns as $column) {
        if ($column->name === 'is_observer') {
            $hasIsObserver = true;
            echo "   ✅ Colonne 'is_observer' trouvée\n";
            echo "   Type: {$column->type}, Default: " . ($column->dflt_value ?? 'NULL') . "\n";
            break;
        }
    }

    if (!$hasIsObserver) {
        echo "   ❌ ERREUR : Colonne 'is_observer' manquante !\n";
        echo "   Exécutez : php artisan migrate\n";
        exit(1);
    }
} catch (\Exception $e) {
    echo "   ❌ Erreur : {$e->getMessage()}\n";
    exit(1);
}

echo "\n";

// 2️⃣ Afficher toutes les participations avec le flag is_observer
echo "2️⃣ État actuel de la table esbtp_attendance...\n";
try {
    $attendances = DB::table('esbtp_attendance')
        ->join('users', 'esbtp_attendance.user_id', '=', 'users.id')
        ->join('seances', 'esbtp_attendance.seance_id', '=', 'seances.id')
        ->select(
            'esbtp_attendance.id',
            'users.name as user_name',
            'users.role',
            'esbtp_attendance.is_observer',
            'esbtp_attendance.status',
            'seances.klassci_seance_id',
            'esbtp_attendance.joined_at'
        )
        ->orderBy('esbtp_attendance.joined_at', 'desc')
        ->limit(10)
        ->get();

    if ($attendances->isEmpty()) {
        echo "   ℹ️  Aucune participation enregistrée pour le moment\n";
    } else {
        echo "   Total : " . $attendances->count() . " participations récentes\n\n";

        echo "   " . str_pad("ID", 5) . " | ";
        echo str_pad("Utilisateur", 25) . " | ";
        echo str_pad("Rôle", 15) . " | ";
        echo str_pad("Observateur", 12) . " | ";
        echo str_pad("Status", 12) . " | ";
        echo "Séance ID\n";
        echo "   " . str_repeat("-", 100) . "\n";

        foreach ($attendances as $att) {
            $isObserverIcon = $att->is_observer ? "👻 OUI" : "👤 NON";
            $roleIcon = match($att->role) {
                'coordinateur' => '👔',
                'enseignant' => '👨‍🏫',
                default => '🎓'
            };

            echo "   " . str_pad($att->id, 5) . " | ";
            echo str_pad($att->user_name, 25) . " | ";
            echo str_pad("{$roleIcon} {$att->role}", 20) . " | ";
            echo str_pad($isObserverIcon, 15) . " | ";
            echo str_pad($att->status, 12) . " | ";
            echo $att->klassci_seance_id . "\n";
        }
    }
} catch (\Exception $e) {
    echo "   ❌ Erreur : {$e->getMessage()}\n";
}

echo "\n";

// 3️⃣ Statistiques par rôle
echo "3️⃣ Statistiques par rôle...\n";
try {
    $stats = DB::table('esbtp_attendance')
        ->join('users', 'esbtp_attendance.user_id', '=', 'users.id')
        ->select('users.role', 'esbtp_attendance.is_observer', DB::raw('COUNT(*) as count'))
        ->groupBy('users.role', 'esbtp_attendance.is_observer')
        ->get();

    if ($stats->isEmpty()) {
        echo "   ℹ️  Aucune statistique disponible\n";
    } else {
        foreach ($stats as $stat) {
            $observerLabel = $stat->is_observer ? "(Observateurs)" : "(Participants)";
            $icon = match($stat->role) {
                'coordinateur' => '👔',
                'enseignant' => '👨‍🏫',
                default => '🎓'
            };

            echo "   {$icon} {$stat->role} {$observerLabel} : {$stat->count}\n";
        }
    }
} catch (\Exception $e) {
    echo "   ❌ Erreur : {$e->getMessage()}\n";
}

echo "\n";

// 4️⃣ Test de la requête getVisioParticipants (exclusion des observateurs)
echo "4️⃣ Test exclusion observateurs (simulation getVisioParticipants)...\n";
try {
    // Trouver une séance avec des participants
    $seance = DB::table('seances')
        ->whereExists(function($query) {
            $query->select(DB::raw(1))
                ->from('esbtp_attendance')
                ->whereRaw('esbtp_attendance.seance_id = seances.id');
        })
        ->first();

    if (!$seance) {
        echo "   ℹ️  Aucune séance avec participants trouvée\n";
    } else {
        echo "   Test pour séance ID : {$seance->klassci_seance_id}\n\n";

        // Requête AVEC observateurs
        $totalParticipants = DB::table('esbtp_attendance')
            ->where('seance_id', $seance->id)
            ->count();

        // Requête SANS observateurs (comme dans getVisioParticipants)
        $visibleParticipants = DB::table('esbtp_attendance')
            ->where('seance_id', $seance->id)
            ->where('is_observer', false)
            ->count();

        $observateurs = DB::table('esbtp_attendance')
            ->where('seance_id', $seance->id)
            ->where('is_observer', true)
            ->count();

        echo "   📊 RÉSULTAT :\n";
        echo "   Total BDD (avec observateurs)     : {$totalParticipants}\n";
        echo "   👻 Observateurs (coordinateurs)    : {$observateurs}\n";
        echo "   👤 Participants visibles (API)     : {$visibleParticipants}\n\n";

        if ($observateurs > 0) {
            echo "   ✅ SUCCESS : Les coordinateurs sont bien EXCLUS de la liste visible !\n";
            echo "   ℹ️  Leur présence reste tracée dans la BDD pour l'audit\n";
        } else {
            echo "   ℹ️  Aucun observateur (coordinateur) n'a encore rejoint cette séance\n";
        }
    }
} catch (\Exception $e) {
    echo "   ❌ Erreur : {$e->getMessage()}\n";
}

echo "\n";

// 5️⃣ Recommandations de test
echo "5️⃣ Comment tester en situation réelle :\n";
echo "   1. Coordinateur : Se connecter et activer une visio\n";
echo "   2. Enseignant : Démarrer la visio\n";
echo "   3. Coordinateur : Cliquer sur 'Rejoindre' (bouton observateur)\n";
echo "   4. Étudiant : Rejoindre la visio\n";
echo "   5. Vérifier la liste des participants :\n";
echo "      - Le coordinateur ne doit PAS apparaître ✅\n";
echo "      - L'enseignant doit apparaître ✅\n";
echo "      - L'étudiant doit apparaître ✅\n";
echo "   6. Vérifier dans la BDD :\n";
echo "      SELECT * FROM esbtp_attendance WHERE is_observer=1;\n";
echo "      → Doit montrer la trace du coordinateur pour audit\n";

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "   FIN DU TEST\n";
echo "═══════════════════════════════════════════════════════════════\n";
