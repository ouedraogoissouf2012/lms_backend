<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "\n=== TEST MANUEL DU JOB DE SYNCHRONISATION ===\n\n";

echo "Exécution manuelle du job SyncKlassciSeances...\n\n";

$job = new App\Jobs\SyncKlassciSeances();

$klassciService = app(App\Services\KlassciProxyService::class);
$classeSyncService = app(App\Services\ClasseSyncService::class);
$notificationService = app(App\Services\NotificationService::class);

$job->handle($klassciService, $classeSyncService, $notificationService);

echo "\n✅ Job terminé\n";
echo "\nVérifiez les logs dans storage/logs/laravel.log\n";
echo "pour voir les détails de la synchronisation.\n\n";
