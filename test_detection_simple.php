<?php

/**
 * Test simple de la détection auto
 */

require __DIR__ . '/vendor/autoload.php';

use App\Services\KlassciProxyService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Test Simple Détection Auto Visio ===\n\n";

// Tester avec user ID 2
$teacher = User::find(2);

if (!$teacher) {
    echo "User 2 non trouvé\n";
    exit(1);
}

echo "Enseignant: {$teacher->name}\n";
echo "Role local: {$teacher->role}\n";
echo "Klassci ID: {$teacher->klassci_id}\n\n";

$service = app(KlassciProxyService::class);

// Tester le rôle dans Klassci
echo "Test du role Klassci...\n";
try {
    $me = $service->requestWithUserToken($teacher->klassci_token, 'me', 'GET');
    $role = $me['data']['role'] ?? 'unknown';
    echo "✓ Role Klassci: {$role}\n\n";

    if ($role !== 'enseignant' && $role !== 'teacher') {
        echo "⚠ Ce user n'est pas enseignant dans Klassci\n";
        echo "Impossible de tester avec ce compte\n";
        exit(0);
    }

} catch (Exception $e) {
    echo "✗ Erreur: {$e->getMessage()}\n";
    exit(1);
}

// Tester l'accès aux matières
echo "Test d'accès aux matières...\n";
try {
    $matieres = $service->requestWithUserToken($teacher->klassci_token, 'matieres', 'GET');
    $matieresList = $matieres['data'] ?? [];
    echo "✓ Nombre de matières: " . count($matieresList) . "\n\n";

    if (empty($matieresList)) {
        echo "Aucune matière assignée à cet enseignant\n";
        exit(0);
    }

    // Analyser la première matière
    $matiere = $matieresList[0];
    echo "Matière 1: {$matiere['nom']}\n";
    echo "ID: {$matiere['id']}\n\n";

    // Récupérer les détails
    echo "Récupération des détails de la matière...\n";
    $details = $service->requestWithUserToken(
        $teacher->klassci_token,
        "matieres/{$matiere['id']}",
        'GET'
    );

    $seances = $details['data']['seances_programmees'] ?? [];
    echo "✓ Séances programmées: " . count($seances) . "\n\n";

    if (empty($seances)) {
        echo "Aucune séance programmée\n";
        echo "Créez une séance avec visio dans Klassci pour tester\n";
        exit(0);
    }

    // Analyser les séances
    echo "Analyse des séances:\n";
    foreach ($seances as $seance) {
        $visioEnabled = $seance['visio_enabled'] ?? false;
        $localExists = DB::table('seances')
            ->where('klassci_seance_id', $seance['id'])
            ->exists();

        echo "  Séance {$seance['id']}:\n";
        echo "    - Visio Klassci: " . ($visioEnabled ? 'OUI' : 'non') . "\n";
        echo "    - Existe localement: " . ($localExists ? 'oui' : 'NON') . "\n";
        echo "    - Devrait déclencher détection: " . (!$localExists && $visioEnabled ? 'OUI ✓' : 'non') . "\n";

        if (isset($seance['classe']['id'])) {
            $classeLocal = DB::table('classes')
                ->where('klassci_id', $seance['classe']['id'])
                ->first();
            echo "    - Classe Klassci ID: {$seance['classe']['id']}\n";
            echo "    - Classe locale: " . ($classeLocal ? "oui ({$classeLocal->nom})" : 'NON') . "\n";
        }
        echo "\n";
    }

} catch (Exception $e) {
    echo "✗ Erreur: {$e->getMessage()}\n";
    exit(1);
}

echo "\n=== Instructions pour tester la détection auto ===\n";
echo "1. Créez une séance dans Klassci avec visio activée\n";
echo "2. Connectez-vous au LMS frontend avec: {$teacher->email}\n";
echo "3. Allez à la page des séances\n";
echo "4. La détection auto devrait se déclencher\n";
echo "5. Vérifiez les logs: tail -f storage/logs/laravel.log | grep 'Séance Klassci'\n";
