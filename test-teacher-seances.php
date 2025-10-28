<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Récupérer l'enseignant
$teacher = App\Models\User::where('role', 'enseignant')->first();

if (!$teacher) {
    echo "❌ Aucun enseignant trouvé" . PHP_EOL;
    exit(1);
}

echo "Enseignant: {$teacher->name} (KLASSCI ID: {$teacher->klassci_id})" . PHP_EOL;
echo "Token KLASSCI: " . (empty($teacher->klassci_token) ? 'VIDE ❌' : 'OK ✓') . PHP_EOL;
echo PHP_EOL;

// Simuler la requête
$service = app(App\Services\KlassciProxyService::class);

try {
    // Récupérer dashboard enseignant
    echo "📚 Récupération dashboard enseignant..." . PHP_EOL;
    $dashboard = $service->requestWithUserToken($teacher->klassci_token, 'me/teacher-dashboard', 'GET');

    $matieres = $dashboard['data']['matieres'] ?? [];
    echo "Matières trouvées: " . count($matieres) . PHP_EOL;

    $totalSeances = 0;

    foreach ($matieres as $matiere) {
        echo PHP_EOL . "Matière: {$matiere['nom']}" . PHP_EOL;

        $details = $service->requestWithUserToken(
            $teacher->klassci_token,
            "matieres/{$matiere['id']}",
            'GET'
        );

        $seances = $details['data']['seances_programmees'] ?? [];
        echo "  Séances: " . count($seances) . PHP_EOL;

        foreach ($seances as $seance) {
            echo "    - Séance ID {$seance['id']}: {$seance['programmation']['date']} {$seance['programmation']['heure_debut']}" . PHP_EOL;
            echo "      Classe: {$seance['classe']['nom']}" . PHP_EOL;
            $totalSeances++;
        }
    }

    echo PHP_EOL . "TOTAL: {$totalSeances} séances trouvées" . PHP_EOL;

} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . PHP_EOL;
}
