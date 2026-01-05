<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "\n=== SÉANCES ACTIVES ===\n\n";

$activeSeances = App\Models\Seance::where('visio_active', true)
    ->orWhereNotNull('visio_started_at')
    ->whereNull('visio_ended_at')
    ->orderBy('visio_started_at', 'desc')
    ->get();

echo "Total: " . $activeSeances->count() . " séance(s) active(s)\n\n";

foreach ($activeSeances as $seance) {
    echo "ID local: {$seance->id}\n";
    echo "KLASSCI ID: {$seance->klassci_seance_id}\n";
    echo "Titre: {$seance->titre}\n";
    echo "Matière: {$seance->matiere_nom} (ID: {$seance->klassci_matiere_id})\n";
    echo "Classe: {$seance->classe_nom} (ID: {$seance->klassci_classe_id})\n";
    echo "Date séance: {$seance->date_seance}\n";
    echo "Visio active: " . ($seance->visio_active ? 'OUI' : 'NON') . "\n";
    echo "Visio démarrée: {$seance->visio_started_at}\n";
    echo "Visio terminée: " . ($seance->visio_ended_at ?? 'En cours...') . "\n";

    if ($seance->visio_started_at) {
        $now = now();
        $dureeEnCours = $seance->visio_started_at->diffInMinutes($now);
        echo "Durée écoulée: {$dureeEnCours} minutes\n";
    }

    echo "\n";
}

// Vérifier dans KLASSCI
if ($activeSeances->count() > 0) {
    $seance = $activeSeances->first();

    echo "\n=== DÉTAILS KLASSCI POUR LA SÉANCE ACTIVE ===\n\n";

    $enseignant = App\Models\User::where('role', 'enseignant')
        ->whereNotNull('klassci_token')
        ->first();

    if ($enseignant) {
        try {
            $klassciService = app(App\Services\KlassciProxyService::class);

            // Récupérer les détails de la matière
            $matiereDetails = $klassciService->get("matieres/{$seance->klassci_matiere_id}");

            $seancesProgrammees = $matiereDetails['data']['seances_programmees'] ?? [];

            echo "Total séances programmées pour cette matière: " . count($seancesProgrammees) . "\n\n";

            // Trouver la séance correspondante
            foreach ($seancesProgrammees as $sp) {
                if ($sp['id'] == $seance->klassci_seance_id) {
                    echo "Séance trouvée dans KLASSCI!\n";
                    echo "Date: " . ($sp['programmation']['date'] ?? 'N/A') . "\n";
                    echo "Heure début: " . ($sp['programmation']['heure_debut'] ?? 'N/A') . "\n";
                    echo "Heure fin: " . ($sp['programmation']['heure_fin'] ?? 'N/A') . "\n";

                    if (isset($sp['programmation']['heure_debut']) && isset($sp['programmation']['heure_fin'])) {
                        $debut = new DateTime($sp['programmation']['heure_debut']);
                        $fin = new DateTime($sp['programmation']['heure_fin']);
                        $dureeProgrammee = ($fin->getTimestamp() - $debut->getTimestamp()) / 60;
                        echo "Durée programmée: {$dureeProgrammee} minutes\n";
                    }

                    break;
                }
            }

        } catch (Exception $e) {
            echo "Erreur: " . $e->getMessage() . "\n";
        }
    }
}
