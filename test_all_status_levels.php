<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

echo "═══════════════════════════════════════════════════════════════════\n";
echo "   TEST DES 4 NIVEAUX DE STATUT\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// Fonction pour calculer le statut
function calculateStatus($studentMin, $seanceMin) {
    if (!$studentMin || !$seanceMin) return ['status' => 'Absent', 'level' => 'absent', 'pct' => 0];
    $pct = ($studentMin / $seanceMin) * 100;

    if ($pct >= 80) return ['status' => 'Présent', 'level' => 'present', 'pct' => round($pct, 1)];
    if ($pct >= 50) return ['status' => 'Partiel', 'level' => 'partial', 'pct' => round($pct, 1)];
    if ($pct >= 20) return ['status' => 'Faible', 'level' => 'low', 'pct' => round($pct, 1)];
    return ['status' => 'Absent', 'level' => 'absent', 'pct' => round($pct, 1)];
}

// Simuler une séance de 60 minutes avec différents cas
$seanceDuration = 60;

$testCases = [
    ['nom' => 'Alice DUPONT', 'duration' => 55, 'expected' => 'Présent (91.7%)'],
    ['nom' => 'Bob MARTIN', 'duration' => 48, 'expected' => 'Présent (80.0%)'],
    ['nom' => 'Claire BERNARD', 'duration' => 45, 'expected' => 'Partiel (75.0%)'],
    ['nom' => 'David PETIT', 'duration' => 30, 'expected' => 'Partiel (50.0%)'],
    ['nom' => 'Emma DUBOIS', 'duration' => 25, 'expected' => 'Faible (41.7%)'],
    ['nom' => 'François LEROY', 'duration' => 12, 'expected' => 'Faible (20.0%)'],
    ['nom' => 'Gisèle MOREAU', 'duration' => 10, 'expected' => 'Absent (16.7%)'],
    ['nom' => 'Henri SIMON', 'duration' => 2, 'expected' => 'Absent (3.3%)'],
];

echo "SÉANCE SIMULÉE: 60 minutes\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

echo "📋 RÉSULTATS PAR ÉTUDIANT:\n";
echo str_repeat('─', 100) . "\n";
printf("%-25s %-15s %-15s %-30s\n", "Nom", "Durée", "Participation", "Statut");
echo str_repeat('─', 100) . "\n";

foreach ($testCases as $test) {
    $result = calculateStatus($test['duration'], $seanceDuration);

    $emoji = match($result['level']) {
        'present' => '✅',
        'partial' => '🟡',
        'low' => '🟠',
        'absent' => '❌',
        default => '⚪',
    };

    printf("%-25s %-15s %-15s %s %-25s\n",
        $test['nom'],
        $test['duration'] . ' min',
        $result['pct'] . '%',
        $emoji,
        $result['status']
    );
}

echo str_repeat('─', 100) . "\n\n";

echo "═══════════════════════════════════════════════════════════════════\n";
echo "RÉSUMÉ DES SEUILS:\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "✅ Présent (vert)     : ≥ 80% (48+ min sur 60 min)\n";
echo "🟡 Partiel (orange)   : 50-79% (30-47 min sur 60 min)\n";
echo "🟠 Faible (rouge clair): 20-49% (12-29 min sur 60 min)\n";
echo "❌ Absent (rouge)     : < 20% (0-11 min sur 60 min)\n";
echo "🔵 En cours (bleu)    : Séance non terminée\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// Statistiques
$presents = count(array_filter($testCases, fn($t) => calculateStatus($t['duration'], $seanceDuration)['level'] === 'present'));
$partiels = count(array_filter($testCases, fn($t) => calculateStatus($t['duration'], $seanceDuration)['level'] === 'partial'));
$faibles = count(array_filter($testCases, fn($t) => calculateStatus($t['duration'], $seanceDuration)['level'] === 'low'));
$absents = count(array_filter($testCases, fn($t) => calculateStatus($t['duration'], $seanceDuration)['level'] === 'absent'));

echo "STATISTIQUES:\n";
echo "  Total étudiants: " . count($testCases) . "\n";
echo "  ✅ Présents: {$presents}\n";
echo "  🟡 Partiels: {$partiels}\n";
echo "  🟠 Faibles: {$faibles}\n";
echo "  ❌ Absents: {$absents}\n";
echo "  Taux de présence (≥80%): " . round(($presents / count($testCases)) * 100) . "%\n";
echo "═══════════════════════════════════════════════════════════════════\n";
