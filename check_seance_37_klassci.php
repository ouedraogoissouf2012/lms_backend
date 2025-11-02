<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\KlassciProxyService;

echo "🔍 RECHERCHE SÉANCE 37 DANS KLASSCI\n";
echo "====================================\n\n";

// Utiliser le token de l'enseignant ou coordinateur
$user = User::where('role', 'coordinateur')
    ->orWhere('role', 'enseignant')
    ->whereNotNull('klassci_token')
    ->first();

if (!$user) {
    echo "❌ Aucun enseignant/coordinateur avec token trouvé\n";
    exit(1);
}

echo "✅ Utilisation du token de: {$user->name} ({$user->role})\n\n";

$klassciService = app(KlassciProxyService::class);

try {
    // Récupérer les matières
    if ($user->role === 'enseignant') {
        $dashboard = $klassciService->requestWithUserToken(
            $user->klassci_token,
            'me/teacher-dashboard',
            'GET'
        );
        $matieres = $dashboard['data']['matieres'] ?? [];
    } else {
        $matieresResponse = $klassciService->requestWithUserToken(
            $user->klassci_token,
            'matieres',
            'GET'
        );
        $matieres = $matieresResponse['data'] ?? [];
    }

    echo "📚 Nombre de matières: " . count($matieres) . "\n\n";

    // Chercher la séance 37 dans toutes les matières
    $seanceFound = null;
    $matiereFound = null;

    foreach ($matieres as $matiere) {
        echo "🔍 Recherche dans matière: {$matiere['nom']} (ID: {$matiere['id']})\n";

        $matiereDetails = $klassciService->requestWithUserToken(
            $user->klassci_token,
            "matieres/{$matiere['id']}",
            'GET'
        );

        $seances = $matiereDetails['data']['seances_programmees'] ?? [];

        foreach ($seances as $seance) {
            if ($seance['id'] == 37) {
                $seanceFound = $seance;
                $matiereFound = $matiere;
                break 2;
            }
        }
    }

    if (!$seanceFound) {
        echo "\n❌ Séance 37 non trouvée dans KLASSCI\n";
        exit(1);
    }

    echo "\n✅ SÉANCE 37 TROUVÉE!\n";
    echo "====================\n\n";
    echo "Matière: {$matiereFound['nom']} (ID: {$matiereFound['id']})\n\n";
    echo "Données de la séance:\n";
    echo json_encode($seanceFound, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo "\n\n";

    if (isset($seanceFound['classe']['id'])) {
        echo "✅ classe_id trouvé: {$seanceFound['classe']['id']}\n";
        echo "   Nom classe: " . ($seanceFound['classe']['libelle'] ?? 'N/A') . "\n";
    } else {
        echo "❌ classe_id NON trouvé dans les données\n";
    }

} catch (\Exception $e) {
    echo "❌ ERREUR: {$e->getMessage()}\n";
    exit(1);
}
