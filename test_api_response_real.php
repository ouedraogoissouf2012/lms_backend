<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "═══════════════════════════════════════════════════════════════════\n";
echo "   TEST RÉPONSE API RÉELLE\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$seanceId = 16; // ID local de la séance #61

// Simuler EXACTEMENT ce que fait le contrôleur
echo "1️⃣  Récupération participants avec ->with('user'):\n";

$attendances = \App\Models\ESBTPAttendance::where('seance_id', $seanceId)
    ->with('user')
    ->where(function($query) {
        $query->where('duration_minutes', '>', 0)
              ->orWhereNull('duration_minutes');
    })
    ->orderBy('joined_at', 'desc')
    ->get();

echo "   Total AVANT filter: {$attendances->count()}\n";
foreach ($attendances as $att) {
    $role = $att->user ? $att->user->role : 'NULL';
    echo "     • {$att->nom} - role: {$role}\n";
}

echo "\n2️⃣  Après ->filter() (sans ->values()):\n";

$filtered = $attendances->filter(function($attendance) {
    if (!$attendance->user) return true;
    return !in_array($attendance->user->role, ['enseignant', 'coordinateur']);
});

echo "   Total APRÈS filter: {$filtered->count()}\n";
echo "   Clés de la collection: " . json_encode($filtered->keys()->toArray()) . "\n";
echo "   Contenu:\n";
foreach ($filtered as $key => $att) {
    echo "     [{$key}] {$att->nom}\n";
}

echo "\n3️⃣  En JSON (sans ->values()):\n";
$jsonWithout = json_encode($filtered);
echo "   " . substr($jsonWithout, 0, 200) . "...\n";
echo "   Type: " . (json_decode($jsonWithout) instanceof \stdClass ? 'OBJET {}' : 'ARRAY []') . "\n";

echo "\n4️⃣  Après ->values():\n";

$withValues = $filtered->values();

echo "   Total: {$withValues->count()}\n";
echo "   Clés: " . json_encode($withValues->keys()->toArray()) . "\n";
echo "   Contenu:\n";
foreach ($withValues as $key => $att) {
    echo "     [{$key}] {$att->nom}\n";
}

echo "\n5️⃣  En JSON (avec ->values()):\n";
$jsonWith = json_encode($withValues);
echo "   " . substr($jsonWith, 0, 200) . "...\n";
$decoded = json_decode($jsonWith);
echo "   Type: " . (is_array($decoded) ? 'ARRAY [] ✅' : 'OBJET {} ❌') . "\n";
echo "   Count: " . (is_array($decoded) ? count($decoded) : 'N/A') . "\n";

echo "\n6️⃣  Test complet avec enrichissement:\n";

$enrichedData = $withValues->map(function($attendance) {
    return [
        'id' => $attendance->id,
        'nom' => $attendance->nom ?? '-',
        'email' => $attendance->email ?? '-',
        'joined_at' => $attendance->joined_at?->format('Y-m-d H:i:s'),
        'duration_minutes' => $attendance->duration_minutes ?? 0,
    ];
});

echo "   enrichedData count: {$enrichedData->count()}\n";
echo "   JSON preview: " . substr(json_encode($enrichedData), 0, 150) . "...\n";

$response = [
    'success' => true,
    'attendances' => $enrichedData,
];

echo "\n7️⃣  Réponse finale:\n";
$finalJson = json_encode($response, JSON_PRETTY_PRINT);
echo $finalJson;

echo "\n\n═══════════════════════════════════════════════════════════════════\n";
echo "DIAGNOSTIC:\n";
echo "═══════════════════════════════════════════════════════════════════\n";

if (is_array(json_decode(json_encode($enrichedData)))) {
    echo "✅ La réponse devrait contenir un ARRAY d'étudiants\n";
    echo "✅ Le frontend devrait voir " . $enrichedData->count() . " étudiants\n";
} else {
    echo "❌ PROBLÈME: La réponse est un OBJET au lieu d'un ARRAY!\n";
    echo "   Le frontend ne pourra pas itérer sur attendances.attendances\n";
}

echo "═══════════════════════════════════════════════════════════════════\n";
