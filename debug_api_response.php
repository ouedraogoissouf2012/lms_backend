<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\KlassciService;
use Illuminate\Support\Facades\Log;

echo "🔍 DEBUG: Simulation de l'API myClassesSeances pour Marcel\n";
echo "=" . str_repeat("=", 70) . "\n\n";

// 1. Trouver Marcel
$marcel = User::where('email', 'LIKE', '%marcel%')->first();

if (!$marcel || !$marcel->klassci_token) {
    echo "❌ Marcel non trouvé ou pas de token\n";
    exit(1);
}

echo "✅ Marcel trouvé: {$marcel->name} ({$marcel->email})\n";
echo "   Token: " . substr($marcel->klassci_token, 0, 20) . "...\n\n";

// 2. Simuler l'appel API
$klassciService = app(KlassciService::class);

try {
    echo "📡 Appel KLASSCI API: me/dashboard\n";
    $dashboard = $klassciService->requestWithUserToken(
        $marcel->klassci_token,
        'me/dashboard',
        'GET'
    );

    $classeId = $dashboard['data']['classe']['id'] ?? null;
    echo "   ✅ Classe ID: {$classeId}\n\n";

    $coursFromDashboard = $dashboard['data']['cours'] ?? [];
    echo "📚 Matières trouvées: " . count($coursFromDashboard) . "\n\n";

    // 3. Pour chaque matière, récupérer les séances (comme le fait le backend)
    foreach ($coursFromDashboard as $index => $matiere) {
        if ($index >= 1) break; // Limiter à 1 matière pour le debug

        $matiereId = $matiere['id'] ?? $matiere['matiere_id'] ?? $matiere['matiere']['id'] ?? null;
        $matiereNom = $matiere['nom'] ?? $matiere['name'] ?? $matiere['libelle'] ?? 'N/A';

        echo "📖 Matière: {$matiereNom} (ID: {$matiereId})\n";
        echo str_repeat("-", 70) . "\n";

        $matiereDetails = $klassciService->requestWithUserToken(
            $marcel->klassci_token,
            "matieres/{$matiereId}",
            'GET'
        );

        $seancesProgrammees = collect($matiereDetails['data']['seances_programmees'] ?? []);
        echo "   Séances programmées: " . $seancesProgrammees->count() . "\n\n";

        // Filtrer par classe
        $seancesClasse = $seancesProgrammees->filter(function ($seance) use ($classeId) {
            return ($seance['classe']['id'] ?? null) == $classeId;
        });

        echo "   Séances pour classe {$classeId}: " . $seancesClasse->count() . "\n\n";

        // Afficher les 3 premières séances
        foreach ($seancesClasse->take(3) as $seance) {
            $seanceId = $seance['id'];

            // Chercher dans BDD locale
            $visioData = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();

            // Simuler la logique du backend (lignes 2152-2171)
            $enseignantNom = 'Non assigné';
            if ($visioData && $visioData->enseignant_nom) {
                $enseignantNom = $visioData->enseignant_nom;
            } else {
                $matiereNomSearch = $matiere['nom'] ?? $matiere['name'] ?? $matiere['libelle'] ?? null;
                if ($matiereNomSearch) {
                    $autreSeance = \App\Models\Seance::where('matiere_nom', $matiereNomSearch)
                        ->whereNotNull('enseignant_nom')
                        ->first();
                    if ($autreSeance) {
                        $enseignantNom = $autreSeance->enseignant_nom;
                    }
                }
            }

            echo "   🎓 Séance #{$seanceId}:\n";
            echo "      - Matière: " . ($matiere['nom'] ?? 'N/A') . "\n";
            echo "      - Date: " . ($seance['programmation']['date'] ?? 'N/A') . "\n";
            echo "      - Classe: " . ($seance['classe']['nom'] ?? 'N/A') . "\n";
            echo "      - ENSEIGNANT (calculé): '{$enseignantNom}' ⭐\n";

            // Ce qui sera retourné dans l'API
            $apiResponse = [
                'id' => $seance['id'],
                'programmation' => [
                    'date' => $seance['programmation']['date'] ?? null,
                    'heure_debut' => $seance['programmation']['heure_debut'] ?? null,
                    'heure_fin' => $seance['programmation']['heure_fin'] ?? null,
                ],
                'matiere' => [
                    'id' => $matiereId,
                    'nom' => $matiereNom
                ],
                'classe' => [
                    'id' => $seance['classe']['id'] ?? null,
                    'nom' => $seance['classe']['nom'] ?? 'N/A'
                ],
                'enseignant' => [
                    'nom' => $enseignantNom  // ⭐ C'est ce qui est retourné
                ],
                'visio' => $visioData ? [
                    'enabled' => $visioData->visio_enabled,
                    'status' => $visioData->visio_status,
                ] : null
            ];

            echo "      - API Response enseignant.nom: '" . $apiResponse['enseignant']['nom'] . "'\n\n";
        }
    }

    echo "\n✅ Simulation terminée\n\n";
    echo "🔍 CONCLUSION:\n";
    echo "   Si l'enseignant est correct ici mais pas dans le frontend,\n";
    echo "   le problème vient du FRONTEND (mauvais champ affiché)\n";

} catch (\Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    echo "   " . $e->getFile() . ":" . $e->getLine() . "\n";
}
