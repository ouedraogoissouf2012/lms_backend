<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';

echo "App type: " . get_class($app) . "\n";
echo "Is Application? " . ($app instanceof \Illuminate\Foundation\Application ? 'YES' : 'NO') . "\n";
echo "App basePath: " . $app->basePath() . "\n";
