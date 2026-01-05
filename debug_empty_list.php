<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "═══════════════════════════════════════════════════════════════════\n";
echo "   DEBUG - POURQUOI LA LISTE EST VIDE ?\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$seance = DB::table('seances')->where('klassci_seance_id', 61)->first();

echo "SÉANCE #61:\n";
echo "  seance_id (local): {$seance->id}\n\n";

// Test 1: Tous les participants bruts
echo "1️⃣  TOUS LES PARTICIPANTS (brut):\n";
$all = DB::table('esbtp_attendance')
    ->where('seance_id', $seance->id)
    ->get();
echo "  Total: {$all->count()}\n";
foreach ($all as $p) {
    echo "    • {$p->nom} - duration: " . ($p->duration_minutes ?? 'NULL') . "\n";
}

// Test 2: Après filtre durée
echo "\n2️⃣  APRÈS FILTRE DURÉE (> 0 OU NULL):\n";
$filtered = $all->filter(function($p) {
    return $p->duration_minutes === null || $p->duration_minutes > 0;
});
echo "  Total: {$filtered->count()}\n";
foreach ($filtered as $p) {
    echo "    • {$p->nom} - duration: " . ($p->duration_minutes ?? 'NULL') . "\n";
}

// Test 3: Avec JOIN users
echo "\n3️⃣  AVEC JOIN users.role:\n";
$withUsers = DB::table('esbtp_attendance')
    ->where('seance_id', $seance->id)
    ->leftJoin('users', 'esbtp_attendance.user_id', '=', 'users.id')
    ->select('esbtp_attendance.*', 'users.role as user_role')
    ->get();

echo "  Total: {$withUsers->count()}\n";
foreach ($withUsers as $p) {
    echo "    • {$p->nom} - role: " . ($p->user_role ?? 'NULL') . " - duration: " . ($p->duration_minutes ?? 'NULL') . "\n";
}

// Test 4: Simuler le filtre du contrôleur
echo "\n4️⃣  SIMULATION FILTRE CONTRÔLEUR:\n";
echo "  Filtre: duration > 0 OU NULL, puis exclure role = 'enseignant' ou 'coordinateur'\n\n";

$students = $withUsers->filter(function($p) {
    // Filtre durée
    if (!($p->duration_minutes === null || $p->duration_minutes > 0)) {
        return false;
    }
    // Exclure enseignant et coordinateur
    if (in_array($p->user_role, ['enseignant', 'coordinateur'])) {
        return false;
    }
    return true;
});

echo "  Total étudiants: {$students->count()}\n";
foreach ($students as $s) {
    echo "    • {$s->nom} - role: {$s->user_role} - duration: " . ($s->duration_minutes ?? 'NULL') . "\n";
}

// Test 5: Le problème du ->with('user')
echo "\n5️⃣  PROBLÈME POTENTIEL - Relation 'user' dans ESBTPAttendance:\n";
$modelTest = \App\Models\ESBTPAttendance::where('seance_id', $seance->id)->first();
if ($modelTest) {
    echo "  Test avec ->with('user')...\n";
    try {
        $testWithUser = \App\Models\ESBTPAttendance::where('seance_id', $seance->id)
            ->with('user')
            ->get();
        echo "  ✅ Relation 'user' existe\n";
        echo "  Total: {$testWithUser->count()}\n";

        // Vérifier si $attendance->user existe
        foreach ($testWithUser as $att) {
            $userInfo = $att->user ? "role={$att->user->role}" : "NULL";
            echo "    • {$att->nom} - user: {$userInfo}\n";
        }
    } catch (\Exception $e) {
        echo "  ❌ ERREUR: " . $e->getMessage() . "\n";
        echo "  ➤ La relation 'user' n'existe peut-être pas dans ESBTPAttendance model!\n";
    }
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "DIAGNOSTIC:\n";
echo "═══════════════════════════════════════════════════════════════════\n";
