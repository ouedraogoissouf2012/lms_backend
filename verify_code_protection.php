<?php

/**
 * Script de vérification de la protection du code
 *
 * Vérifie que les lignes critiques du code n'ont pas été modifiées
 * Basé sur le fichier .code-protection-rules.json
 */

$rulesFile = __DIR__ . '/../.code-protection-rules.json';

if (!file_exists($rulesFile)) {
    echo "❌ Fichier de règles non trouvé : $rulesFile\n";
    exit(1);
}

$rules = json_decode(file_get_contents($rulesFile), true);

if (!$rules) {
    echo "❌ Erreur de lecture du fichier JSON\n";
    exit(1);
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "   VÉRIFICATION DE LA PROTECTION DU CODE\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

echo "Version des règles : " . $rules['version'] . "\n";
echo "Dernière mise à jour : " . $rules['lastUpdate'] . "\n\n";

$totalChecks = 0;
$passedChecks = 0;
$failedChecks = 0;
$warnings = [];

// ═══════════════════════════════════════════════════════════════
// VÉRIFICATION DES FONCTIONS PROTÉGÉES
// ═══════════════════════════════════════════════════════════════

echo "🔍 VÉRIFICATION DES FONCTIONS PROTÉGÉES\n";
echo str_repeat("-", 70) . "\n\n";

foreach ($rules['protectedFunctions'] as $func) {
    $filePath = __DIR__ . '/' . $func['file'];

    if (!file_exists($filePath)) {
        echo "❌ Fichier non trouvé : {$func['file']}\n";
        $failedChecks++;
        continue;
    }

    $lines = file($filePath);

    echo "📁 {$func['file']}\n";
    echo "   Fonction : {$func['function']} (lignes {$func['startLine']}-{$func['endLine']})\n";
    echo "   Raison : {$func['reason']}\n\n";

    foreach ($func['criticalLines'] as $critical) {
        $totalChecks++;
        $lineNumber = $critical['line'];
        $expectedCode = $critical['code'];

        // Ligne array est 0-indexed
        $actualLine = trim($lines[$lineNumber - 1]);

        // Vérifier si le code attendu est présent
        if (strpos($actualLine, $expectedCode) !== false) {
            echo "   ✅ Ligne {$lineNumber} : OK\n";
            echo "      " . $critical['reason'] . "\n";
            $passedChecks++;
        } else {
            echo "   ❌ Ligne {$lineNumber} : MODIFIÉE !\n";
            echo "      Attendu : {$expectedCode}\n";
            echo "      Actuel  : {$actualLine}\n";
            echo "      Raison  : {$critical['reason']}\n";
            $failedChecks++;

            $warnings[] = [
                'file' => $func['file'],
                'line' => $lineNumber,
                'reason' => $critical['reason']
            ];
        }
    }

    echo "\n";
}

// ═══════════════════════════════════════════════════════════════
// VÉRIFICATION DES CHAMPS BDD
// ═══════════════════════════════════════════════════════════════

echo "🗄️ VÉRIFICATION DES CHAMPS BDD PROTÉGÉS\n";
echo str_repeat("-", 70) . "\n\n";

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

foreach ($rules['protectedDatabaseFields'] as $field) {
    $totalChecks++;

    try {
        // Vérifier que la colonne existe
        $exists = \Illuminate\Support\Facades\Schema::hasColumn($field['table'], $field['field']);

        if ($exists) {
            echo "   ✅ {$field['table']}.{$field['field']} : EXISTE\n";
            echo "      Type : {$field['type']}\n";
            echo "      Raison : {$field['reason']}\n";
            $passedChecks++;
        } else {
            echo "   ❌ {$field['table']}.{$field['field']} : MANQUANT !\n";
            echo "      Raison : {$field['reason']}\n";
            $failedChecks++;

            $warnings[] = [
                'table' => $field['table'],
                'field' => $field['field'],
                'reason' => $field['reason']
            ];
        }
    } catch (\Exception $e) {
        echo "   ⚠️ {$field['table']}.{$field['field']} : Erreur vérification\n";
        echo "      " . $e->getMessage() . "\n";
        $failedChecks++;
    }
}

echo "\n";

// ═══════════════════════════════════════════════════════════════
// VÉRIFICATION DES RÈGLES MÉTIER
// ═══════════════════════════════════════════════════════════════

echo "📋 VÉRIFICATION DES RÈGLES MÉTIER\n";
echo str_repeat("-", 70) . "\n\n";

foreach ($rules['businessRules'] as $rule) {
    echo "   {$rule['id']} : {$rule['title']}\n";
    echo "   Status : " . ($rule['status'] === 'IMPLEMENTED' ? '✅ IMPLÉMENTÉE' : '❌ NON IMPLÉMENTÉE') . "\n";

    foreach ($rule['implementation'] as $impl) {
        echo "   - {$impl}\n";
    }

    if (!empty($rule['tests'])) {
        echo "   Tests : " . implode(', ', $rule['tests']) . "\n";
    }

    echo "\n";
}

// ═══════════════════════════════════════════════════════════════
// RÉSUMÉ
// ═══════════════════════════════════════════════════════════════

echo "═══════════════════════════════════════════════════════════════\n";
echo "   RÉSUMÉ\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$percentage = $totalChecks > 0 ? round(($passedChecks / $totalChecks) * 100, 2) : 0;

echo "Total vérifications : {$totalChecks}\n";
echo "Réussies            : {$passedChecks}\n";
echo "Échouées            : {$failedChecks}\n";
echo "Taux de réussite    : {$percentage}%\n\n";

if ($failedChecks > 0) {
    echo "⚠️ ATTENTION : DES MODIFICATIONS CRITIQUES ONT ÉTÉ DÉTECTÉES !\n\n";

    echo "Liste des problèmes :\n";
    foreach ($warnings as $i => $warning) {
        echo ($i + 1) . ". ";
        if (isset($warning['file'])) {
            echo "{$warning['file']}:{$warning['line']} - {$warning['reason']}\n";
        } else {
            echo "{$warning['table']}.{$warning['field']} - {$warning['reason']}\n";
        }
    }

    echo "\n❌ VÉRIFICATION ÉCHOUÉE\n";
    exit(1);
} else {
    echo "✅ TOUTES LES VÉRIFICATIONS SONT PASSÉES\n";
    echo "Le code protégé n'a pas été modifié.\n";
    exit(0);
}
