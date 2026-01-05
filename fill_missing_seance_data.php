<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Seance;
use App\Services\KlassciProxyService;
use Illuminate\Support\Facades\Log;

echo "🔄 Remplissage des données manquantes pour les séances...\n\n";

$klassciService = app(KlassciProxyService::class);

// Récupérer toutes les séances qui n'ont pas de classe_nom ou titre
$seances = Seance::whereNull('classe_nom')
    ->orWhereNull('titre')
    ->get();

echo "📊 Trouvé {$seances->count()} séance(s) avec données manquantes\n\n";

$updated = 0;
$failed = 0;

foreach ($seances as $seance) {
    echo "Traitement séance ID: {$seance->id} (KLASSCI #{$seance->klassci_seance_id})...\n";

    try {
        // Récupérer le token d'un utilisateur admin ou coordinateur
        $admin = \App\Models\User::where('role', 'coordinateur')
            ->orWhere('role', 'superAdmin')
            ->whereNotNull('klassci_token')
            ->first();

        if (!$admin || !$admin->klassci_token) {
            echo "  ⚠️  Aucun utilisateur admin avec token trouvé, skip\n";
            $failed++;
            continue;
        }

        // Récupérer les détails de la séance depuis KLASSCI
        $seanceData = $klassciService->requestWithUserToken(
            $admin->klassci_token,
            "seances/{$seance->klassci_seance_id}",
            'GET'
        );

        if (isset($seanceData['data'])) {
            $data = $seanceData['data'];

            // Mettre à jour les champs manquants
            $updates = [];

            if (!$seance->classe_nom && isset($data['classe']['libelle'])) {
                $updates['classe_nom'] = $data['classe']['libelle'];
                echo "  ✅ Classe: {$data['classe']['libelle']}\n";
            }

            if (!$seance->titre && isset($data['titre'])) {
                $updates['titre'] = $data['titre'];
                echo "  ✅ Titre: {$data['titre']}\n";
            } elseif (!$seance->titre) {
                $updates['titre'] = "Séance {$seance->klassci_seance_id}";
                echo "  ✅ Titre par défaut: Séance {$seance->klassci_seance_id}\n";
            }

            if (!$seance->date_seance && isset($data['date_debut'])) {
                $updates['date_seance'] = \Carbon\Carbon::parse($data['date_debut']);
                echo "  ✅ Date: {$data['date_debut']}\n";
            }

            if (!empty($updates)) {
                $seance->update($updates);
                $updated++;
                echo "  ✅ Séance mise à jour\n";
            } else {
                echo "  ℹ️  Aucune mise à jour nécessaire\n";
            }
        } else {
            echo "  ⚠️  Données KLASSCI non disponibles\n";

            // Mettre un titre par défaut
            if (!$seance->titre) {
                $seance->update(['titre' => "Séance {$seance->klassci_seance_id}"]);
                echo "  ✅ Titre par défaut ajouté\n";
                $updated++;
            } else {
                $failed++;
            }
        }

    } catch (\Exception $e) {
        echo "  ❌ Erreur: {$e->getMessage()}\n";

        // Mettre un titre par défaut en cas d'erreur
        if (!$seance->titre) {
            $seance->update(['titre' => "Séance {$seance->klassci_seance_id}"]);
            echo "  ✅ Titre par défaut ajouté malgré l'erreur\n";
        }

        $failed++;
    }

    echo "\n";
}

echo "✅ Terminé !\n";
echo "📊 Résumé: {$updated} mise(s) à jour, {$failed} échec(s)\n";
