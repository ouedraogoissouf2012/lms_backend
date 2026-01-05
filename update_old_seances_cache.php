<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\KlassciService;

echo "=== MISE À JOUR DU CACHE DES ANCIENNES SÉANCES ===\n\n";

// Récupérer les séances sans cache complet
$seancesToUpdate = DB::table('seances')
    ->whereNotNull('visio_started_at')
    ->where(function($query) {
        $query->whereNull('classe_nom')
              ->orWhereNull('titre')
              ->orWhereNull('date_seance');
    })
    ->get();

echo "Séances à mettre à jour: " . $seancesToUpdate->count() . "\n\n";

if ($seancesToUpdate->isEmpty()) {
    echo "✅ Aucune séance à mettre à jour\n";
    exit(0);
}

// Récupérer un token admin pour les appels KLASSCI
$adminUser = DB::table('users')
    ->where('role', 'admin')
    ->whereNotNull('klassci_token')
    ->first();

if (!$adminUser) {
    echo "❌ Aucun admin avec token KLASSCI trouvé\n";
    echo "Les données manquantes seront affichées comme 'Matière inconnue', etc.\n";
    exit(1);
}

$klassciService = app(KlassciService::class);
$updated = 0;
$errors = 0;

foreach ($seancesToUpdate as $seance) {
    echo "Traitement séance #{$seance->klassci_seance_id}...\n";

    try {
        // Appel KLASSCI pour récupérer les données
        $response = $klassciService->requestWithUserToken(
            $adminUser->klassci_token,
            "seances/{$seance->klassci_seance_id}",
            'GET'
        );

        $seanceData = $response['data'] ?? null;

        if ($seanceData) {
            // Mettre à jour la séance avec les données récupérées
            DB::table('seances')
                ->where('id', $seance->id)
                ->update([
                    'classe_nom' => $seanceData['classe']['libelle'] ?? null,
                    'titre' => $seanceData['titre'] ?? 'Séance #' . $seance->klassci_seance_id,
                    'date_seance' => isset($seanceData['date_debut'])
                        ? date('Y-m-d H:i:s', strtotime($seanceData['date_debut']))
                        : null,
                    'updated_at' => now(),
                ]);

            echo "  ✅ Mis à jour: {$seanceData['titre']}\n";
            $updated++;
        } else {
            echo "  ⚠️  Aucune donnée retournée par KLASSCI\n";
            $errors++;
        }

    } catch (\Exception $e) {
        echo "  ❌ Erreur: " . $e->getMessage() . "\n";
        $errors++;
    }

    // Pause pour éviter de surcharger l'API
    usleep(200000); // 200ms
}

echo "\n=== RÉSUMÉ ===\n";
echo "✅ Séances mises à jour: $updated\n";
echo "❌ Erreurs: $errors\n";
echo "📊 Total traité: " . $seancesToUpdate->count() . "\n";
