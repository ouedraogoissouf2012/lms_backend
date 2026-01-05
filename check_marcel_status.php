<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\ESBTPAttendance;
use App\Models\Seance;
use Carbon\Carbon;

echo "\n========================================\n";
echo "VÉRIFICATION MARCEL OUEDRAOGO\n";
echo "========================================\n\n";

// Trouver Marcel
$marcel = User::where('name', 'LIKE', '%MARCEL%')->first();

if (!$marcel) {
    echo "❌ Marcel non trouvé\n";
    exit(1);
}

echo "User trouvé :\n";
echo "  ID: {$marcel->id}\n";
echo "  Nom: {$marcel->name}\n";
echo "  Email: {$marcel->email}\n";
echo "  Rôle: {$marcel->role}\n\n";

// Trouver ses participations récentes
$attendances = ESBTPAttendance::where('user_id', $marcel->id)
    ->orderBy('updated_at', 'desc')
    ->take(5)
    ->get();

if ($attendances->isEmpty()) {
    echo "Aucune participation trouvée\n";
    exit(0);
}

echo "Participations récentes :\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$now = Carbon::now();

foreach ($attendances as $att) {
    $seance = Seance::find($att->seance_id);

    echo "Séance ID: {$seance->id} (KLASSCI: {$seance->klassci_seance_id})\n";
    echo "  Visio active: " . ($seance->visio_active ? 'OUI' : 'NON') . "\n";
    echo "  Démarrée: " . ($seance->visio_started_at ? $seance->visio_started_at : 'NULL') . "\n";
    echo "  Terminée: " . ($seance->visio_ended_at ? $seance->visio_ended_at : 'NULL') . "\n\n";

    echo "Participation de Marcel :\n";
    echo "  Status: {$att->status}\n";
    echo "  Rejoint à: " . ($att->joined_at ? $att->joined_at : 'NULL') . "\n";
    echo "  Quitté à: " . ($att->left_at ? $att->left_at : 'NULL') . "\n";
    echo "  Dernier heartbeat: " . ($att->last_seen_at ? $att->last_seen_at : 'NULL') . "\n";

    if ($att->last_seen_at) {
        $minutesSinceHeartbeat = $att->last_seen_at->diffInMinutes($now);
        echo "  Temps depuis dernier heartbeat: {$minutesSinceHeartbeat} minutes\n";
    }

    echo "  Durée: " . ($att->duration_minutes ?? 'NULL') . " minutes\n";
    echo "  Observer: " . ($att->is_observer ? 'OUI' : 'NON') . "\n";
    echo "  Mis à jour: {$att->updated_at}\n";

    echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
}

// Vérifier les logs récents
echo "ANALYSE :\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$recentAtt = $attendances->first();
$seance = Seance::find($recentAtt->seance_id);

if ($recentAtt->status === 'disconnected' && $recentAtt->left_at) {
    echo "Marcel a été marqué comme déconnecté.\n\n";

    echo "Causes possibles :\n\n";

    // Cause 1 : DetectDisconnectedParticipants (inactivité 5+ min)
    if ($recentAtt->last_seen_at) {
        $minutesSinceHeartbeat = $recentAtt->last_seen_at->diffInMinutes($now);
        if ($minutesSinceHeartbeat >= 5) {
            echo "✅ CAUSE 1 : Détection d'inactivité (DetectDisconnectedParticipants)\n";
            echo "   - Dernier heartbeat il y a {$minutesSinceHeartbeat} min (seuil : 5 min)\n";
            echo "   - Job détecte l'inactivité et marque comme 'disconnected'\n";
            echo "   - Solution : Le frontend doit envoyer des heartbeats toutes les 30 secondes\n\n";
        }
    }

    // Cause 2 : AutoCloseEmptySeances
    if ($seance->visio_active === false && $seance->visio_ended_at) {
        $seanceEndedRecently = $seance->visio_ended_at->diffInMinutes($now) < 10;
        if ($seanceEndedRecently) {
            echo "✅ CAUSE 2 : Séance fermée automatiquement (AutoCloseEmptySeances)\n";
            echo "   - Séance terminée à : {$seance->visio_ended_at}\n";
            echo "   - Le job a fermé la séance et déconnecté tous les participants\n\n";
        }
    }

    // Cause 3 : FinalizeSeanceAttendances
    if ($seance->visio_active === false && $seance->visio_ended_at) {
        echo "✅ CAUSE 3 POSSIBLE : Finalisation après fin programmée (FinalizeSeanceAttendances)\n";
        echo "   - Séance terminée, participants finalisés\n\n";
    }

    // Cause 4 : Déconnexion volontaire
    if ($recentAtt->left_at && $recentAtt->last_seen_at &&
        abs($recentAtt->left_at->diffInSeconds($recentAtt->last_seen_at)) < 10) {
        echo "✅ CAUSE 4 POSSIBLE : Déconnexion volontaire\n";
        echo "   - left_at et last_seen_at sont très proches\n";
        echo "   - Marcel a peut-être fermé son onglet ou quitté la séance\n\n";
    }

} else if ($recentAtt->status === 'connected') {
    echo "✅ Marcel est actuellement connecté à la séance {$seance->id}\n\n";

    if ($recentAtt->last_seen_at) {
        $minutesSinceHeartbeat = $recentAtt->last_seen_at->diffInMinutes($now);
        echo "Dernier heartbeat : il y a {$minutesSinceHeartbeat} minutes\n";

        if ($minutesSinceHeartbeat >= 5) {
            echo "⚠️  ATTENTION : Heartbeat > 5 min, sera bientôt déconnecté par DetectDisconnectedParticipants\n";
        }
    }
}

echo "\n========================================\n\n";
