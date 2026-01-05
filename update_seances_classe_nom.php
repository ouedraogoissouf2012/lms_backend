<?php

/**
 * Script pour remplir automatiquement classe_nom dans les séances
 * Remplit les noms de classe manquants en interrogeant l'API KLASSCI
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Seance;
use App\Services\KlassciProxyService;
use Illuminate\Support\Facades\Log;

echo "═══════════════════════════════════════════════════════════════\n";
echo "   MISE À JOUR CLASSE_NOM DANS LES SÉANCES\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

try {
    $klassciService = app(KlassciProxyService::class);

    // Récupérer toutes les classes une seule fois pour éviter les appels multiples
    echo "📡 Récupération des classes depuis KLASSCI...\n";
    $classes = $klassciService->getClasses();

    if (!isset($classes['data'])) {
        echo "❌ Impossible de récupérer les classes depuis KLASSCI\n";
        exit(1);
    }

    $classesById = [];
    foreach ($classes['data'] as $classe) {
        $classesById[$classe['id']] = $classe;
    }

    echo "✅ " . count($classesById) . " classes récupérées\n\n";

    // Récupérer toutes les séances sans classe_nom
    $seances = Seance::whereNull('classe_nom')
        ->whereNotNull('klassci_classe_id')
        ->get();

    echo "📊 " . $seances->count() . " séances à mettre à jour\n\n";

    if ($seances->isEmpty()) {
        echo "✅ Aucune séance à mettre à jour\n";
        exit(0);
    }

    $updated = 0;
    $failed = 0;

    foreach ($seances as $seance) {
        $klasseId = $seance->klassci_classe_id;

        if (!isset($classesById[$klasseId])) {
            echo "⚠️  Séance #{$seance->klassci_seance_id}: Classe KLASSCI #{$klasseId} introuvable\n";
            $failed++;
            continue;
        }

        $classe = $classesById[$klasseId];
        $classeNom = $classe['libelle'] ?? $classe['nom'] ?? null;

        // Si pas de nom, utiliser le code du niveau
        if (!$classeNom && isset($classe['niveau']['code'])) {
            $classeNom = $classe['niveau']['code'];
        } elseif (!$classeNom && isset($classe['niveau']['libelle'])) {
            $classeNom = $classe['niveau']['libelle'];
        }

        if ($classeNom) {
            $seance->classe_nom = $classeNom;
            $seance->save();

            echo "✅ Séance #{$seance->klassci_seance_id}: classe_nom = '{$classeNom}'\n";
            $updated++;
        } else {
            echo "❌ Séance #{$seance->klassci_seance_id}: Impossible de trouver un nom de classe\n";
            $failed++;
        }
    }

    echo "\n═══════════════════════════════════════════════════════════════\n";
    echo "   RÉSULTAT\n";
    echo "═══════════════════════════════════════════════════════════════\n\n";
    echo "✅ Mises à jour: $updated\n";
    echo "❌ Échecs: $failed\n";
    echo "\n";

} catch (\Exception $e) {
    echo "\n❌ ERREUR: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
