<?php

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Structure de la table seances:\n";
echo "========================================\n\n";

$columns = Schema::getColumns('seances');

foreach ($columns as $column) {
    $nullable = $column['nullable'] ? 'NULL' : 'NOT NULL';
    $default = isset($column['default']) ? " DEFAULT {$column['default']}" : '';
    echo "{$column['name']}: {$column['type_name']} {$nullable}{$default}\n";
}
