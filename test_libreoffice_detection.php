<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Test de détection LibreOffice:\n";
echo "==============================\n\n";

// Simuler la méthode de détection
function findLibreOfficeCommand(): ?string
{
    $possiblePaths = [
        'C:/Program Files/LibreOffice/program/soffice.exe',
        'C:/Program Files (x86)/LibreOffice/program/soffice.exe',
        'C:/Program Files/LibreOffice 7/program/soffice.exe',
        'C:/Program Files/LibreOffice 6/program/soffice.exe',
        'soffice',
        'libreoffice',
        '/usr/bin/soffice',
        '/usr/bin/libreoffice',
        '/usr/local/bin/soffice',
        '/Applications/LibreOffice.app/Contents/MacOS/soffice',
    ];

    echo "Recherche de LibreOffice...\n\n";

    foreach ($possiblePaths as $path) {
        echo "Test: $path ... ";

        if (str_contains($path, '/') || str_contains($path, '\\')) {
            if (file_exists($path)) {
                echo "✓ TROUVÉ!\n";
                return $path;
            } else {
                echo "✗ Non trouvé\n";
            }
        } else {
            $test = shell_exec("where $path 2>nul");
            if ($test) {
                echo "✓ TROUVÉ dans PATH!\n";
                echo "  Chemin: " . trim($test) . "\n";
                return $path;
            } else {
                echo "✗ Non trouvé\n";
            }
        }
    }

    return null;
}

$libreOffice = findLibreOfficeCommand();

echo "\n=== RÉSULTAT ===\n";
if ($libreOffice) {
    echo "✓ LibreOffice détecté: $libreOffice\n\n";

    // Tester la version
    $versionCommand = (str_contains($libreOffice, ' ')) ? '"' . $libreOffice . '"' : $libreOffice;
    echo "Test de la version:\n";
    $version = shell_exec("$versionCommand --version 2>&1");
    echo $version . "\n";
} else {
    echo "✗ LibreOffice NON détecté\n";
    echo "⚠️ Veuillez vérifier l'installation de LibreOffice\n";
}
