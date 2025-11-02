<?php
/**
 * Script de test pour vérifier la configuration serveur
 * Pour la conversion de fichiers (PowerPoint, Word, PDF)
 *
 * Usage: php test_conversion.php
 */

echo "╔═══════════════════════════════════════════════════════╗\n";
echo "║   Test Configuration Serveur - LMS File Conversion   ║\n";
echo "╚═══════════════════════════════════════════════════════╝\n\n";

$errors = [];
$warnings = [];
$success = [];

// ============================================
// 1. Vérifier LibreOffice (OBLIGATOIRE)
// ============================================
echo "📊 LibreOffice (OBLIGATOIRE pour PowerPoint et Word):\n";
echo str_repeat("-", 60) . "\n";

$libreOfficePaths = [
    'C:/Program Files/LibreOffice/program/soffice.exe',
    'C:/Program Files (x86)/LibreOffice/program/soffice.exe',
    'C:/Program Files/LibreOffice 7/program/soffice.exe',
    '/usr/bin/soffice',
    '/usr/local/bin/soffice',
    '/Applications/LibreOffice.app/Contents/MacOS/soffice',
];

$libreOfficeFound = false;
foreach ($libreOfficePaths as $path) {
    if (file_exists($path)) {
        echo "✅ LibreOffice trouvé: $path\n";

        // Tester la version
        $command = str_contains($path, ' ') ? "\"$path\"" : $path;
        exec("$command --version 2>&1", $output, $returnCode);

        if ($returnCode === 0 && !empty($output)) {
            echo "   📌 Version: " . $output[0] . "\n";
            $success[] = "LibreOffice installé et fonctionnel";
        } else {
            echo "   ⚠️  LibreOffice trouvé mais ne répond pas correctement\n";
            $warnings[] = "LibreOffice ne répond pas à --version";
        }

        $libreOfficeFound = true;
        break;
    }
}

if (!$libreOfficeFound) {
    // Vérifier dans le PATH
    exec("where soffice 2>nul", $whereOutput);
    if (empty($whereOutput)) {
        exec("which soffice 2>/dev/null", $whereOutput);
    }

    if (!empty($whereOutput)) {
        echo "✅ LibreOffice trouvé dans le PATH: " . $whereOutput[0] . "\n";
        $libreOfficeFound = true;
        $success[] = "LibreOffice dans le PATH";
    } else {
        echo "❌ LibreOffice NON TROUVÉ\n";
        echo "   Installation requise: https://www.libreoffice.org/download/\n";
        $errors[] = "LibreOffice manquant (OBLIGATOIRE)";
    }
}

echo "\n";

// ============================================
// 2. Vérifier ImageMagick (Imagick PHP)
// ============================================
echo "🖼️  ImageMagick + PHP Imagick (RECOMMANDÉ pour PDF/PowerPoint):\n";
echo str_repeat("-", 60) . "\n";

if (extension_loaded('imagick')) {
    echo "✅ Extension PHP Imagick CHARGÉE\n";

    try {
        $imagick = new Imagick();
        $version = $imagick->getVersion();
        echo "   📌 Version: " . $version['versionString'] . "\n";

        // Vérifier les formats supportés
        $formats = $imagick->queryFormats();
        $importantFormats = ['PDF', 'PNG', 'JPEG'];
        $supportedFormats = array_intersect($importantFormats, $formats);

        echo "   📌 Formats supportés: " . implode(', ', $supportedFormats) . "\n";

        if (count($supportedFormats) === count($importantFormats)) {
            $success[] = "Imagick complètement fonctionnel";
        } else {
            $warnings[] = "Imagick installé mais formats incomplets";
        }

    } catch (Exception $e) {
        echo "   ⚠️  Imagick chargé mais erreur: " . $e->getMessage() . "\n";
        $warnings[] = "Imagick installé mais non fonctionnel";
    }
} else {
    echo "❌ Extension PHP Imagick NON CHARGÉE\n";
    echo "   Installation recommandée pour meilleure qualité\n";
    echo "   Ubuntu/Debian: sudo apt-get install php-imagick\n";
    echo "   CentOS/RHEL: sudo yum install php-imagick\n";
    $warnings[] = "Imagick manquant (PDF utilisera Ghostscript)";
}

echo "\n";

// ============================================
// 3. Vérifier Ghostscript (Fallback pour PDF)
// ============================================
echo "👻 Ghostscript (Fallback si Imagick absent):\n";
echo str_repeat("-", 60) . "\n";

$ghostscriptCommands = ['gswin64c', 'gswin32c', 'gs'];
$ghostscriptFound = false;

foreach ($ghostscriptCommands as $cmd) {
    exec("$cmd --version 2>&1", $gsOutput, $returnCode);
    if ($returnCode === 0 && !empty($gsOutput)) {
        echo "✅ Ghostscript trouvé: $cmd\n";
        echo "   📌 Version: " . $gsOutput[0] . "\n";
        $ghostscriptFound = true;
        $success[] = "Ghostscript installé";
        break;
    }
}

if (!$ghostscriptFound) {
    echo "❌ Ghostscript NON TROUVÉ\n";
    if (!extension_loaded('imagick')) {
        echo "   ⚠️  CRITIQUE: Ni Imagick ni Ghostscript disponible!\n";
        echo "   Installation requise: https://ghostscript.com/releases/gsdnld.html\n";
        $errors[] = "Ghostscript manquant (requis sans Imagick)";
    } else {
        echo "   ℹ️  Pas critique (Imagick disponible)\n";
    }
}

echo "\n";

// ============================================
// 4. Vérifier GD Library (Fallback basique)
// ============================================
echo "🎨 GD Library (Fallback basique):\n";
echo str_repeat("-", 60) . "\n";

if (extension_loaded('gd')) {
    echo "✅ Extension PHP GD CHARGÉE\n";
    $gdInfo = gd_info();
    echo "   📌 Version: " . $gdInfo['GD Version'] . "\n";
    echo "   📌 PNG Support: " . ($gdInfo['PNG Support'] ? 'Oui' : 'Non') . "\n";
    echo "   📌 JPEG Support: " . ($gdInfo['JPEG Support'] ? 'Oui' : 'Non') . "\n";
    $success[] = "GD Library disponible";
} else {
    echo "❌ Extension PHP GD NON CHARGÉE\n";
    $warnings[] = "GD Library manquante";
}

echo "\n";

// ============================================
// 5. Vérifier Configuration PHP
// ============================================
echo "⚙️  Configuration PHP:\n";
echo str_repeat("-", 60) . "\n";

$phpConfig = [
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'max_input_time' => ini_get('max_input_time'),
];

foreach ($phpConfig as $key => $value) {
    echo "   $key: $value\n";
}

// Vérifier les valeurs minimales recommandées
$uploadMaxBytes = parseSize(ini_get('upload_max_filesize'));
$postMaxBytes = parseSize(ini_get('post_max_size'));
$memoryBytes = parseSize(ini_get('memory_limit'));

if ($uploadMaxBytes < 100 * 1024 * 1024) { // 100 MB
    $warnings[] = "upload_max_filesize trop petit (recommandé: 100M)";
}
if ($postMaxBytes < 100 * 1024 * 1024) {
    $warnings[] = "post_max_size trop petit (recommandé: 100M)";
}
if ($memoryBytes < 256 * 1024 * 1024) { // 256 MB
    $warnings[] = "memory_limit trop petit (recommandé: 512M)";
}
if (ini_get('max_execution_time') < 300) {
    $warnings[] = "max_execution_time trop court (recommandé: 300s)";
}

echo "\n";

// ============================================
// 6. Vérifier Permissions Dossiers
// ============================================
echo "📁 Permissions Dossiers:\n";
echo str_repeat("-", 60) . "\n";

$requiredDirs = [
    __DIR__ . '/storage/app/public',
    __DIR__ . '/storage/logs',
];

foreach ($requiredDirs as $dir) {
    if (file_exists($dir)) {
        $perms = substr(sprintf('%o', fileperms($dir)), -4);
        $writable = is_writable($dir);

        $status = $writable ? '✅' : '❌';
        echo "$status $dir (permissions: $perms, writable: " . ($writable ? 'Oui' : 'Non') . ")\n";

        if (!$writable) {
            $errors[] = "Dossier $dir non accessible en écriture";
        }
    } else {
        echo "❌ $dir n'existe pas\n";
        $errors[] = "Dossier $dir manquant";
    }
}

echo "\n";

// ============================================
// RÉSUMÉ FINAL
// ============================================
echo "╔═══════════════════════════════════════════════════════╗\n";
echo "║                    RÉSUMÉ FINAL                       ║\n";
echo "╚═══════════════════════════════════════════════════════╝\n\n";

if (!empty($success)) {
    echo "✅ SUCCÈS (" . count($success) . "):\n";
    foreach ($success as $msg) {
        echo "   • $msg\n";
    }
    echo "\n";
}

if (!empty($warnings)) {
    echo "⚠️  AVERTISSEMENTS (" . count($warnings) . "):\n";
    foreach ($warnings as $msg) {
        echo "   • $msg\n";
    }
    echo "\n";
}

if (!empty($errors)) {
    echo "❌ ERREURS CRITIQUES (" . count($errors) . "):\n";
    foreach ($errors as $msg) {
        echo "   • $msg\n";
    }
    echo "\n";
}

// ============================================
// VERDICT
// ============================================
echo "═══════════════════════════════════════════════════════\n";

if (empty($errors)) {
    if (empty($warnings)) {
        echo "🎉 CONFIGURATION PARFAITE!\n";
        echo "Toutes les fonctionnalités de conversion sont disponibles.\n";
    } else {
        echo "✅ CONFIGURATION FONCTIONNELLE\n";
        echo "Quelques améliorations recommandées pour performances optimales.\n";
    }
} else {
    echo "⛔ CONFIGURATION INCOMPLÈTE\n";
    echo "Certaines fonctionnalités ne fonctionneront pas correctement.\n";
    echo "Veuillez installer les dépendances manquantes.\n";
}

echo "═══════════════════════════════════════════════════════\n\n";

// ============================================
// HELPER FUNCTIONS
// ============================================
function parseSize($size) {
    $unit = strtoupper(substr($size, -1));
    $value = (int)$size;

    switch ($unit) {
        case 'G': return $value * 1024 * 1024 * 1024;
        case 'M': return $value * 1024 * 1024;
        case 'K': return $value * 1024;
        default: return $value;
    }
}
