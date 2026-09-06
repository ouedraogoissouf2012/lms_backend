<?php

declare(strict_types=1);

/**
 * #708 — tout job de app/Jobs/ doit déclarer failed().
 * Exit 0 : tous conformes. Exit 1 : oubli. Exit 2 : rien inspecté.
 */

$jobsDir = $argv[1] ?? dirname(__DIR__).'/app/Jobs';

if (! is_dir($jobsDir)) {
    fwrite(STDERR, "JobsHaveFailed: rien inspecté (répertoire absent : {$jobsDir}).\n");
    exit(2);
}

$files = glob($jobsDir.'/*.php') ?: [];
if ($files === []) {
    fwrite(STDERR, "JobsHaveFailed: rien inspecté (aucun fichier dans {$jobsDir}).\n");
    exit(2);
}

$missing = [];
foreach ($files as $file) {
    $src = (string) file_get_contents($file);
    if (! preg_match('/function\s+failed\s*\(/', $src)) {
        $missing[] = basename($file);
    }
}

$inspected = count($files);
echo "JobsHaveFailed: inspecté {$inspected} job(s) dans {$jobsDir}.\n";

if ($missing !== []) {
    fwrite(STDERR, 'JobsHaveFailed: failed() manquant : '.implode(', ', $missing)."\n");
    exit(1);
}

exit(0);
