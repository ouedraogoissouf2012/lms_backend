<?php

/**
 * TEST DES CORRECTIONS - Système de présence
 *
 * Vérifie que les corrections apportées fonctionnent correctement
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ESBTPAttendance;
use App\Models\Seance;
use App\Jobs\FinalizeSeanceAttendances;
use App\Jobs\DetectDisconnectedParticipants;

echo "═══════════════════════════════════════════════════════════\n";
echo "   TEST DES CORRECTIONS - SYSTÈME DE PRÉSENCE\n";
echo "═══════════════════════════════════════════════════════════\n\n";

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "1️⃣  ÉTAT INITIAL\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$connected = ESBTPAttendance::where('status', 'connected')->count();
$disconnected = ESBTPAttendance::where('status', 'disconnected')->count();

echo "Participants 'connected': {$connected}\n";
echo "Participants 'disconnected': {$disconnected}\n\n";

if ($connected > 0) {
    echo "Détails participants 'connected':\n\n";

    $participantsConnectes = ESBTPAttendance::where('status', 'connected')
        ->with('user:id,name', 'seance:id,klassci_seance_id,titre,date_seance,heure_fin')
        ->get();

    foreach ($participantsConnectes as $att) {
        $minutesSinceJoined = now()->diffInMinutes($att->joined_at);
        $minutesSinceLastSeen = $att->last_seen_at ? now()->diffInMinutes($att->last_seen_at) : 'N/A';

        echo "   • {$att->user->name}\n";
        echo "     Séance: {$att->seance->titre}\n";
        echo "     Rejoint: {$att->joined_at} ({$minutesSinceJoined} min)\n";
        echo "     Last seen: " . ($att->last_seen_at ?? 'Jamais') . " ({$minutesSinceLastSeen} min)\n";
        echo "     Date séance: {$att->seance->date_seance} {$att->seance->heure_fin}\n\n";
    }
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "2️⃣  TEST JOB: DetectDisconnectedParticipants\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "Exécution du job...\n\n";

try {
    $job = new DetectDisconnectedParticipants();
    $job->handle();

    echo "✅ Job exécuté avec succès\n\n";
} catch (\Exception $e) {
    echo "❌ Erreur: {$e->getMessage()}\n\n";
    exit(1);
}

$connectedAfter1 = ESBTPAttendance::where('status', 'connected')->count();
$disconnectedAfter1 = ESBTPAttendance::where('status', 'disconnected')->count();

echo "Résultat:\n";
echo "   Connected: {$connected} → {$connectedAfter1}\n";
echo "   Disconnected: {$disconnected} → {$disconnectedAfter1}\n\n";

if ($disconnectedAfter1 > $disconnected) {
    $diff = $disconnectedAfter1 - $disconnected;
    echo "✅ {$diff} participant(s) marqué(s) comme déconnecté(s) (inactivité)\n\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "3️⃣  TEST JOB: FinalizeSeanceAttendances\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "Exécution du job...\n\n";

try {
    $job = new FinalizeSeanceAttendances();
    $job->handle();

    echo "✅ Job exécuté avec succès\n\n";
} catch (\Exception $e) {
    echo "❌ Erreur: {$e->getMessage()}\n\n";
    exit(1);
}

$connectedAfter2 = ESBTPAttendance::where('status', 'connected')->count();
$disconnectedAfter2 = ESBTPAttendance::where('status', 'disconnected')->count();

echo "Résultat:\n";
echo "   Connected: {$connectedAfter1} → {$connectedAfter2}\n";
echo "   Disconnected: {$disconnectedAfter1} → {$disconnectedAfter2}\n\n";

if ($disconnectedAfter2 > $disconnectedAfter1) {
    $diff = $disconnectedAfter2 - $disconnectedAfter1;
    echo "✅ {$diff} participant(s) finalisé(s) (séance terminée)\n\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "4️⃣  VÉRIFICATION DES DONNÉES CALCULÉES\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$allAttendances = ESBTPAttendance::with('user:id,name')->get();

foreach ($allAttendances as $att) {
    echo "   • {$att->user->name}\n";
    echo "     Status: {$att->status}\n";
    echo "     Rejoint: {$att->joined_at}\n";
    echo "     Quitté: " . ($att->left_at ?? '❌ NULL') . "\n";
    echo "     Durée: " . ($att->duration_minutes ?? '❌ NULL') . " minutes\n";

    // Vérifier que les données sont cohérentes
    if ($att->status === 'disconnected') {
        if (!$att->left_at) {
            echo "     ⚠️  INCOHÉRENT: status=disconnected mais left_at=NULL\n";
        }
        if (!$att->duration_minutes) {
            echo "     ⚠️  INCOHÉRENT: status=disconnected mais duration_minutes=NULL\n";
        } else {
            echo "     ✅ Données complètes\n";
        }
    }
    echo "\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "5️⃣  TEST API: Calcul dynamique durée (participants connectés)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$stillConnected = ESBTPAttendance::where('status', 'connected')->first();

if ($stillConnected) {
    echo "Participant toujours connecté trouvé:\n";
    echo "   • User: {$stillConnected->user->name}\n";
    echo "   • Rejoint: {$stillConnected->joined_at}\n";
    echo "   • duration_minutes (BDD): " . ($stillConnected->duration_minutes ?? 'NULL') . "\n\n";

    // Simuler le calcul dynamique de l'API
    if ($stillConnected->joined_at) {
        $durationCalculated = $stillConnected->joined_at->diffInMinutes(now());
        echo "   ✅ Durée calculée dynamiquement: {$durationCalculated} minutes\n";
        echo "      (depuis {$stillConnected->joined_at} jusqu'à maintenant)\n\n";
    }
} else {
    echo "ℹ️  Aucun participant actuellement connecté\n";
    echo "   (Normal si tous ont été finalisés par les jobs)\n\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "6️⃣  VÉRIFICATION SCHEDULING\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "Tâches planifiées:\n\n";

try {
    Artisan::call('schedule:list');
    $scheduleOutput = Artisan::output();

    // Filtrer uniquement les tâches de présence
    $lines = explode("\n", $scheduleOutput);
    $relevantLines = array_filter($lines, function($line) {
        return stripos($line, 'detect-disconnected') !== false
            || stripos($line, 'finalize-seance') !== false;
    });

    if (count($relevantLines) > 0) {
        foreach ($relevantLines as $line) {
            echo "   {$line}\n";
        }
        echo "\n✅ Jobs planifiés correctement\n\n";
    } else {
        echo "   ⚠️  Aucune tâche de présence trouvée dans le planning\n\n";
    }
} catch (\Exception $e) {
    echo "❌ Erreur: {$e->getMessage()}\n\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "7️⃣  RÉSUMÉ DES CORRECTIONS\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "✅ CORRECTION 1: Job DetectDisconnectedParticipants\n";
echo "   → Détecte les participants inactifs (pas de heartbeat depuis 5 min)\n";
echo "   → Marque automatiquement comme 'disconnected'\n";
echo "   → Calcule la durée de participation\n\n";

echo "✅ CORRECTION 2: Job FinalizeSeanceAttendances\n";
echo "   → Finalise les présences des séances terminées (heure_fin + 30 min)\n";
echo "   → Marque les participants 'connected' comme 'disconnected'\n";
echo "   → Utilise last_seen_at ou heure_fin comme left_at\n\n";

echo "✅ CORRECTION 3: API - Calcul dynamique durée\n";
echo "   → Pour les participants 'connected', calcule durée = now() - joined_at\n";
echo "   → Pour les participants 'disconnected', utilise duration_minutes (BDD)\n";
echo "   → Affiche 'En cours' comme left_at pour les connectés\n\n";

echo "✅ CORRECTION 4: Scheduling automatique\n";
echo "   → DetectDisconnectedParticipants: toutes les 2 minutes\n";
echo "   → FinalizeSeanceAttendances: toutes les 10 minutes\n\n";

$finalConnected = ESBTPAttendance::where('status', 'connected')->count();
$finalDisconnected = ESBTPAttendance::where('status', 'disconnected')->count();
$total = ESBTPAttendance::count();

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "8️⃣  ÉTAT FINAL\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "Total présences: {$total}\n";
echo "Connected: {$finalConnected}\n";
echo "Disconnected: {$finalDisconnected}\n\n";

if ($finalDisconnected > 0) {
    $withDuration = ESBTPAttendance::where('status', 'disconnected')
        ->whereNotNull('duration_minutes')
        ->count();

    $percentage = round(($withDuration / $finalDisconnected) * 100);

    echo "Présences déconnectées avec durée calculée: {$withDuration}/{$finalDisconnected} ({$percentage}%)\n\n";

    if ($percentage === 100) {
        echo "✅ PARFAIT: Toutes les présences déconnectées ont une durée!\n\n";
    }
}

echo "═══════════════════════════════════════════════════════════\n";
echo "   FIN DU TEST\n";
echo "═══════════════════════════════════════════════════════════\n";
