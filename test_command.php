<?php

use Illuminate\Foundation\Application;
use Symfony\Component\Console\Input\ArgvInput;

define('LARAVEL_START', microtime(true));

require __DIR__.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/bootstrap/app.php';

echo "App created: " . get_class($app) . "\n";
echo "App is bound: " . ($app->bound('app') ? 'YES' : 'NO') . "\n";

// Simulate what artisan does
$input = new ArgvInput(['artisan', 'serve']);

try {
    $status = $app->handleCommand($input);
    echo "Command executed with status: $status\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
}
