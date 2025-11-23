<?php

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use App\Services\KlassciProxyService;
use App\Models\User;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== DIAGNOSTIC COMPLET NOTIFICATIONS ===\n\n";

// 1. Dernière séance dans Klassci
echo "1. DERNIERES SEANCES DANS KLASSCI:\n";
$teacher = User::where('role', 'enseignant')->whereNotNull('klassci_token')->first();
$service = app(KlassciProxyService::class);

$allKlassciSeances = [];

try {
    $matieres = $service->requestWithUserToken($teacher->klassci_token, 'matieres', 'GET');

    foreach ($matieres['data'] as $matiere) {
        $details = $service->requestWithUserToken($teacher->klassci_token, 'matieres/' . $matiere['id'], 'GET');
        $seances = $details['data']['seances_programmees'] ?? [];

        foreach ($seances as $seance) {
            $allKlassciSeances[] = [
                'id' => $seance['id'],
                'matiere' => $matiere['nom'],
                'classe' => $seance['classe']['nom'] ?? 'N/A',
                'salle' => $seance['programmation']['salle'] ?? 'N/A',
            ];
        }
    }

    echo "Total séances dans Klassci: " . count($allKlassciSeances) . "\n";

    foreach ($allKlassciSeances as $s) {
        echo "  - ID {$s['id']}: {$s['matiere']} - Classe {$s['classe']} - Salle: {$s['salle']}\n";
    }

} catch (Exception $e) {
    echo "Erreur: {$e->getMessage()}\n";
}
echo "\n";

// 2. Séances locales
echo "2. SEANCES LOCALES (base de données):\n";
$localSeances = DB::table('seances')->orderBy('id', 'desc')->get();

echo "Total séances locales: " . count($localSeances) . "\n";

foreach ($localSeances as $s) {
    echo "  - ID local {$s->id} - Klassci ID {$s->klassci_seance_id}\n";
    echo "    Matière: {$s->matiere_nom}\n";
    echo "    Visio: " . ($s->visio_enabled ? 'OUI' : 'non') . "\n";
    echo "    Status: {$s->visio_status}\n";
    echo "    Créée: {$s->created_at}\n";
}
echo "\n";

// 3. Comparaison Klassci vs Local
echo "3. COMPARAISON KLASSCI <-> LOCAL:\n";

$klassciIds = array_column($allKlassciSeances, 'id');
$localIds = $localSeances->pluck('klassci_seance_id')->toArray();

echo "Séances dans Klassci: " . count($klassciIds) . "\n";
echo "Séances dans local: " . count($localIds) . "\n";

$missing = array_diff($klassciIds, $localIds);

if (!empty($missing)) {
    echo "\n⚠️  SEANCES MANQUANTES (dans Klassci mais pas en local):\n";
    foreach ($missing as $id) {
        foreach ($allKlassciSeances as $s) {
            if ($s['id'] == $id) {
                echo "  - ID {$id}: {$s['matiere']} - Salle: {$s['salle']}\n";
            }
        }
    }
    echo "\n→ Ces séances n'ont PAS déclenché la détection auto!\n";
} else {
    echo "\n✓ Toutes les séances Klassci sont synchronisées\n";
}
echo "\n";

// 4. Notifications
echo "4. NOTIFICATIONS:\n";
$notifs = DB::table('notifications')
    ->whereIn('type', ['visio_scheduled', 'visio_starting'])
    ->orderBy('id', 'desc')
    ->get();

echo "Total notifications visio: " . count($notifs) . "\n\n";

$notifsBySeance = [];
foreach ($notifs as $n) {
    $data = json_decode($n->data, true);
    $seanceId = $data['seance_id'] ?? 'N/A';

    if (!isset($notifsBySeance[$seanceId])) {
        $notifsBySeance[$seanceId] = 0;
    }
    $notifsBySeance[$seanceId]++;
}

echo "Notifications par séance:\n";
foreach ($notifsBySeance as $seanceId => $count) {
    echo "  - Séance {$seanceId}: {$count} notification(s)\n";
}
echo "\n";

// 5. Logs récents
echo "5. LOGS RECENTS:\n";
$logFile = storage_path('logs/laravel.log');

if (file_exists($logFile)) {
    $handle = fopen($logFile, 'r');
    fseek($handle, -10000, SEEK_END);
    $logs = fread($handle, 10000);
    fclose($handle);

    $lines = explode("\n", $logs);
    $relevantLogs = array_filter($lines, function($line) {
        return stripos($line, 'séance') !== false
            || stripos($line, 'visio') !== false
            || stripos($line, 'notification') !== false
            || stripos($line, 'myTeachingSeances') !== false;
    });

    if (!empty($relevantLogs)) {
        echo "Logs pertinents (dernières 10000 chars):\n";
        foreach (array_slice($relevantLogs, -10) as $log) {
            echo "  " . trim($log) . "\n";
        }
    } else {
        echo "Aucun log pertinent trouvé\n";
    }
} else {
    echo "Fichier de log non trouvé\n";
}
echo "\n";

// 6. Vérifier la classe et les étudiants
echo "6. CLASSES ET ETUDIANTS:\n";
$classes = DB::table('classes')->get();

foreach ($classes as $classe) {
    $studentCount = DB::table('classe_etudiant')
        ->where('classe_id', $classe->id)
        ->where('statut', 'actif')
        ->count();

    echo "  - Classe {$classe->id} (Klassci ID {$classe->klassci_id}): {$classe->libelle}\n";
    echo "    Étudiants actifs: {$studentCount}\n";

    if ($studentCount == 0) {
        echo "    ⚠️  AUCUN ÉTUDIANT ACTIF - Les notifications ne seront PAS envoyées!\n";
    }
}
echo "\n";

// 7. Résumé
echo "=== RESUME DU DIAGNOSTIC ===\n\n";

if (!empty($missing)) {
    echo "❌ PROBLEME 1: Certaines séances Klassci ne sont pas détectées\n";
    echo "   Séances manquantes: " . implode(', ', $missing) . "\n";
    echo "   Cause possible: L'API /api/lms/seances/my-teaching n'est pas appelée\n\n";
}

$classesWithoutStudents = DB::table('classes')
    ->leftJoin('classe_etudiant', function($join) {
        $join->on('classes.id', '=', 'classe_etudiant.classe_id')
             ->where('classe_etudiant.statut', '=', 'actif');
    })
    ->select('classes.*')
    ->groupBy('classes.id')
    ->havingRaw('COUNT(classe_etudiant.user_id) = 0')
    ->count();

if ($classesWithoutStudents > 0) {
    echo "❌ PROBLEME 2: Certaines classes n'ont aucun étudiant actif\n";
    echo "   Classes sans étudiants: {$classesWithoutStudents}\n";
    echo "   Impact: Aucune notification ne sera envoyée pour ces classes\n\n";
}

echo "PROCHAINES ETAPES:\n";
echo "1. Vérifier si l'API est appelée quand vous chargez la page des séances\n";
echo "2. Vérifier que les étudiants sont bien synchronisés dans les classes\n";
echo "3. Vérifier les logs d'erreurs\n";
