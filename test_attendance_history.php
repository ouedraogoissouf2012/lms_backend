<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ESBTPAttendance;
use App\Models\User;
use App\Models\Seance;

echo "=== TEST HISTORIQUE DES PRÉSENCES ===\n\n";

// 1. Vérifier qu'il y a des données dans esbtp_attendance
echo "1. Vérification des données dans esbtp_attendance:\n";
$totalAttendances = ESBTPAttendance::count();
echo "   Total participations: $totalAttendances\n";

if ($totalAttendances === 0) {
    echo "   ❌ Aucune participation trouvée\n";
    echo "   💡 Créez des participations en rejoignant une visioconférence\n";
    exit;
}

echo "   ✅ $totalAttendances participations trouvées\n\n";

// 2. Afficher les dernières participations
echo "2. Dernières participations (10 plus récentes):\n";
$recentAttendances = ESBTPAttendance::with(['user', 'seance'])
    ->orderBy('joined_at', 'desc')
    ->limit(10)
    ->get();

foreach ($recentAttendances as $attendance) {
    $duration = null;
    if ($attendance->joined_at && $attendance->left_at) {
        $duration = $attendance->joined_at->diffInMinutes($attendance->left_at);
    }

    echo sprintf(
        "   %s | User: %s | Séance: %d | Joined: %s | Left: %s | Durée: %s\n",
        $attendance->status === 'connected' ? '🟢' : '🔴',
        $attendance->user->name ?? 'N/A',
        $attendance->seance_id,
        $attendance->joined_at?->format('Y-m-d H:i:s'),
        $attendance->left_at?->format('Y-m-d H:i:s') ?? 'En cours',
        $duration !== null ? "{$duration} min" : 'N/A'
    );
}

// 3. Tester le filtrage par date
echo "\n3. Test filtrage par date (dernières 24h):\n";
$last24h = ESBTPAttendance::where('joined_at', '>=', now()->subDay())
    ->count();
echo "   Participations dernières 24h: $last24h\n";

// 4. Tester le filtrage par utilisateur (exemple avec le premier utilisateur trouvé)
echo "\n4. Test filtrage par utilisateur:\n";
$firstUser = User::whereIn('role', ['etudiant', 'enseignant'])->first();

if ($firstUser) {
    $userAttendances = ESBTPAttendance::where('user_id', $firstUser->id)->count();
    echo "   Utilisateur: {$firstUser->name} (ID: {$firstUser->id})\n";
    echo "   Nombre de participations: $userAttendances\n";
}

// 5. Tester l'enrichissement avec données KLASSCI
echo "\n5. Test enrichissement données KLASSCI:\n";
$seanceWithData = Seance::whereNotNull('klassci_seance_id')->first();

if ($seanceWithData) {
    echo "   Séance trouvée: ID local = {$seanceWithData->id}, KLASSCI ID = {$seanceWithData->klassci_seance_id}\n";
    echo "   Date séance: {$seanceWithData->date_seance}\n";

    $attendancesForSeance = ESBTPAttendance::where('seance_id', $seanceWithData->id)->count();
    echo "   Participations pour cette séance: $attendancesForSeance\n";
} else {
    echo "   ⚠️  Aucune séance avec KLASSCI ID trouvée\n";
}

// 6. Vérifier les statistiques
echo "\n6. Statistiques globales:\n";
$connectedCount = ESBTPAttendance::where('status', 'connected')->count();
$disconnectedCount = ESBTPAttendance::where('status', 'disconnected')->count();
$avgDuration = ESBTPAttendance::whereNotNull('joined_at')
    ->whereNotNull('left_at')
    ->get()
    ->avg(function($attendance) {
        return $attendance->joined_at->diffInMinutes($attendance->left_at);
    });

echo "   Actuellement connectés: $connectedCount\n";
echo "   Déconnectés: $disconnectedCount\n";
echo "   Durée moyenne: " . round($avgDuration, 2) . " minutes\n";

// 7. Simuler l'appel API
echo "\n7. Simulation appel API GET /api/attendance/history:\n";
echo "   Paramètres testables:\n";
echo "   - page=1&per_page=50 (pagination)\n";
echo "   - date_from=2025-11-01&date_to=2025-11-21 (filtrage date)\n";
echo "   - seance_id=123 (filtrage par séance KLASSCI)\n\n";

echo "   💡 Pour tester via HTTP:\n";
echo "   curl -H \"Authorization: Bearer YOUR_TOKEN\" \\\n";
echo "        \"http://localhost:8000/api/lms/attendance/history?page=1&per_page=10\"\n";

echo "\n=== FIN DES TESTS ===\n";
