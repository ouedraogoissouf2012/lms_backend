<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';

try {
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    echo "Kernel type: " . get_class($kernel) . "\n";
    echo "Kernel created successfully\n";
} catch (\Exception $e) {
    echo "Error creating kernel: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
