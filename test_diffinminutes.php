<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Carbon\Carbon;

echo "═══════════════════════════════════════════════════════════════════\n";
echo "   TEST diffInMinutes() - Pourquoi des FLOAT?\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// Test avec les vraies données
$joined = Carbon::parse('2025-11-20 13:31:52');
$left = Carbon::parse('2025-11-20 19:12:44');

echo "Test 1: LOSSENI KABIROU COULIBALY\n";
echo "  Joined: {$joined}\n";
echo "  Left: {$left}\n";
echo "  diffInMinutes(): " . $joined->diffInMinutes($left) . " (type: " . gettype($joined->diffInMinutes($left)) . ")\n";
echo "  diffInSeconds(): " . $joined->diffInSeconds($left) . "\n";
echo "  diffInSeconds() / 60 = " . ($joined->diffInSeconds($left) / 60) . "\n";

$diff = $joined->diff($left);
echo "  Manual calc: ({$diff->h} * 60) + {$diff->i} + ({$diff->days} * 24 * 60) = " . (($diff->h * 60) + $diff->i + ($diff->days * 24 * 60)) . "\n";

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "DÉCOUVERTE:\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "diffInMinutes() retourne les MINUTES COMPLÈTES (INTEGER)\n";
echo "Mais le problème vient de diffInMinutes() qui compte les secondes aussi!\n";
echo "\nSOLUTION: Utiliser round() ou floor() ou int() lors du stockage\n";
echo "═══════════════════════════════════════════════════════════════════\n";
