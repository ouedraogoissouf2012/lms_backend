<?php

/**
 * Test immédiat du nettoyage des séances obsolètes
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "═══════════════════════════════════════════════════════════\n";
echo "   TEST NETTOYAGE DES SÉANCES OBSOLÈTES\n";
echo "═══════════════════════════════════════════════════════════\n\n";

// État AVANT nettoyage
echo "1️⃣  ÉTAT AVANT NETTOYAGE\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$seancesAvant = App\Models\Seance::where('is_active', true)
    ->whereNotNull('klassci_seance_id')
    ->get();

echo "Séances actives: {$seancesAvant->count()}\n\n";

foreach ($seancesAvant as $seance) {
    echo "   • ID {$seance->id}: Klassci #{$seance->klassci_seance_id} - {$seance->matiere_nom}\n";
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "2️⃣  EXÉCUTION DU NETTOYAGE\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

try {
    // Exécuter le job de nettoyage
    $job = new App\Jobs\CleanObsoleteSeances();
    $klassciService = app(\App\Services\KlassciProxyService::class);

    $job->handle($klassciService);

    echo "✅ Job exécuté avec succès\n\n";

} catch (\Exception $e) {
    echo "❌ Erreur: {$e->getMessage()}\n\n";
    exit(1);
}

// État APRÈS nettoyage
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "3️⃣  ÉTAT APRÈS NETTOYAGE\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$seancesApres = App\Models\Seance::where('is_active', true)
    ->whereNotNull('klassci_seance_id')
    ->get();

$seancesArchivees = App\Models\Seance::where('is_active', false)
    ->whereNotNull('klassci_seance_id')
    ->get();

echo "Séances actives: {$seancesApres->count()}\n";
echo "Séances archivées: {$seancesArchivees->count()}\n\n";

if ($seancesApres->count() > 0) {
    echo "Séances encore actives:\n";
    foreach ($seancesApres as $seance) {
        echo "   • ID {$seance->id}: Klassci #{$seance->klassci_seance_id} - {$seance->matiere_nom}\n";
    }
    echo "\n";
}

if ($seancesArchivees->count() > 0) {
    echo "Séances archivées:\n";
    foreach ($seancesArchivees as $seance) {
        echo "   • ID {$seance->id}: Klassci #{$seance->klassci_seance_id} - {$seance->matiere_nom}\n";
    }
    echo "\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "4️⃣  RÉSUMÉ\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$archived = $seancesAvant->count() - $seancesApres->count();

echo "✅ Séances vérifiées: {$seancesAvant->count()}\n";
echo "🗑️  Séances archivées: {$archived}\n";
echo "📊 Séances actives restantes: {$seancesApres->count()}\n\n";

if ($archived > 0) {
    echo "✅ Le nettoyage a bien fonctionné!\n";
    echo "   {$archived} séances qui n'existent plus dans Klassci ont été archivées.\n\n";
} else {
    echo "⚠️  Aucune séance archivée.\n";
    echo "   Soit toutes les séances existent encore dans Klassci,\n";
    echo "   soit il y a eu un problème de connexion.\n\n";
}

echo "═══════════════════════════════════════════════════════════\n";
echo "   FIN DU TEST\n";
echo "═══════════════════════════════════════════════════════════\n";
