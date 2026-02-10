<?php
/**
 * Script de conversion des guides HTML en fichiers autonomes (images base64 intégrées)
 * Usage: php convert_to_standalone.php
 */

$guidesDir = __DIR__;
$files = [
    'guide_enseignant.html',
    'guide_coordinateur.html',
    'guide_etudiant.html',
];

foreach ($files as $file) {
    $inputPath = $guidesDir . DIRECTORY_SEPARATOR . $file;
    $outputPath = $guidesDir . DIRECTORY_SEPARATOR . str_replace('.html', '_standalone.html', $file);

    if (!file_exists($inputPath)) {
        echo "SKIP: $file not found\n";
        continue;
    }

    echo "Processing $file...\n";
    $html = file_get_contents($inputPath);

    // Find all img src references
    $count = 0;
    $html = preg_replace_callback('/(<img\s[^>]*src=")([^"]+)(")/i', function($matches) use ($guidesDir, &$count) {
        $imgPath = $guidesDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $matches[2]);

        if (!file_exists($imgPath)) {
            echo "  WARNING: Image not found: {$matches[2]}\n";
            return $matches[0];
        }

        $imageData = file_get_contents($imgPath);
        $base64 = base64_encode($imageData);
        $ext = strtolower(pathinfo($imgPath, PATHINFO_EXTENSION));
        $mime = $ext === 'png' ? 'image/png' : ($ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/' . $ext);

        $count++;
        echo "  Converted: {$matches[2]} (" . round(strlen($imageData) / 1024) . " KB)\n";

        return $matches[1] . "data:$mime;base64,$base64" . $matches[3];
    }, $html);

    file_put_contents($outputPath, $html);
    $sizeMB = round(filesize($outputPath) / 1024 / 1024, 1);
    echo "  => $count images embedded\n";
    echo "  => Saved: " . basename($outputPath) . " ($sizeMB MB)\n\n";
}

echo "Done! Standalone files created.\n";
echo "To generate PDFs: Open each _standalone.html in Chrome, press Ctrl+P, select 'Save as PDF'\n";
