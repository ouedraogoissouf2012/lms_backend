<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== RECHERCHE ENSEIGNANTS ===\n\n";

// 1. Dans la BDD locale LMS
echo "1. ENSEIGNANTS DANS LMS LOCAL:\n";
$localUsers = \App\Models\User::whereIn('role', ['enseignant', 'teacher'])->get();
echo "   Nombre: " . $localUsers->count() . "\n";
foreach ($localUsers as $user) {
    echo "   - ID: {$user->id} | Nom: {$user->name} | Email: {$user->email} | KLASSCI_ID: {$user->klassci_id}\n";
}
echo "\n";

// 2. Via l'API KLASSCI
echo "2. ENSEIGNANTS VIA API KLASSCI:\n";
$service = app(\App\Services\KlassciProxyService::class);
try {
    $result = $service->getEnseignants();
    $enseignants = $result['data'] ?? [];
    echo "   Nombre: " . count($enseignants) . "\n";
    if (count($enseignants) > 0) {
        foreach ($enseignants as $ens) {
            echo "   - ID: " . ($ens['id'] ?? 'N/A') . " | Nom: " . ($ens['nom'] ?? 'N/A') . " | Email: " . ($ens['email'] ?? 'N/A') . "\n";
        }
    } else {
        echo "   Aucun enseignant retourné par KLASSCI\n";
    }
} catch (\Exception $e) {
    echo "   ERREUR: " . $e->getMessage() . "\n";
}
echo "\n";

echo "=== FIN RECHERCHE ===\n";
