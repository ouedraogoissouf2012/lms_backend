<?php

$logFile = 'storage/logs/laravel.log';

if (!file_exists($logFile)) {
    echo "❌ Fichier de log introuvable\n";
    exit;
}

$lines = file($logFile);
$lastLines = array_slice($lines, -500);

echo "=== LOGS DES JOBS AUTOMATIQUES (500 dernières lignes) ===\n\n";

$autoCloseLines = [];
$detectDisconnectedLines = [];

foreach ($lastLines as $line) {
    if (stripos($line, 'AutoCloseEmptySeances') !== false) {
        $autoCloseLines[] = $line;
    }
    if (stripos($line, 'DetectDisconnectedParticipants') !== false) {
        $detectDisconnectedLines[] = $line;
    }
}

echo "--- AutoCloseEmptySeances ---\n";
if (empty($autoCloseLines)) {
    echo "❌ AUCUN LOG trouvé\n";
} else {
    echo "✅ Trouvé " . count($autoCloseLines) . " logs\n";
    foreach (array_slice($autoCloseLines, -10) as $line) {
        echo $line;
    }
}

echo "\n--- DetectDisconnectedParticipants ---\n";
if (empty($detectDisconnectedLines)) {
    echo "❌ AUCUN LOG trouvé\n";
} else {
    echo "✅ Trouvé " . count($detectDisconnectedLines) . " logs\n";
    foreach (array_slice($detectDisconnectedLines, -10) as $line) {
        echo $line;
    }
}

if (empty($autoCloseLines) && empty($detectDisconnectedLines)) {
    echo "\n\n🚨 PROBLÈME DÉTECTÉ 🚨\n";
    echo "Le scheduler Laravel ne semble PAS tourner !\n";
    echo "Les jobs automatiques ne s'exécutent pas.\n\n";
    echo "Pour démarrer le scheduler :\n";
    echo "php artisan schedule:work\n";
}
