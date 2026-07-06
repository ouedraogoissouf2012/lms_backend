<?php

declare(strict_types=1);

$max = null;
$baseline = __DIR__ . '/../phpstan-baseline.neon';

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--max=')) {
        $max = filter_var(substr($arg, 6), FILTER_VALIDATE_INT);
    } elseif ($arg !== '') {
        $baseline = $arg;
    }
}

if (! is_int($max) || $max < 0) {
    fwrite(STDERR, "Usage: php scripts/check-phpstan-baseline.php --max=N [baseline]\n");
    exit(2);
}

if (! is_file($baseline) || ! is_readable($baseline)) {
    fwrite(STDERR, "Baseline introuvable ou illisible: {$baseline}\n");
    exit(2);
}

$contents = file_get_contents($baseline);
if ($contents === false) {
    fwrite(STDERR, "Lecture impossible: {$baseline}\n");
    exit(2);
}

preg_match_all('/^\s*message:/m', $contents, $matches);
$count = count($matches[0]);

if ($count > $max) {
    fwrite(STDERR, "PHPStan baseline increased: {$count}/{$max} entries.\n");
    exit(1);
}

echo "PHPStan baseline OK: {$count}/{$max} entries.\n";
