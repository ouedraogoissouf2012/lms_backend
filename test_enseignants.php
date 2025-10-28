<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Http;

echo "========================================\n";
echo "TEST ENDPOINT ENSEIGNANTS KLASSCI\n";
echo "========================================\n\n";

// Trouver le coordinateur
$coordinateur = User::where('role', 'coordinateur')->first();

if (!$coordinateur) {
    echo "❌ Aucun coordinateur trouvé\n";
    exit(1);
}

echo "✅ Coordinateur trouvé:\n";
echo "  - ID: {$coordinateur->id}\n";
echo "  - Nom: {$coordinateur->nom} {$coordinateur->prenom}\n";
echo "  - Email: {$coordinateur->email}\n";
echo "  - KLASSCI ID: {$coordinateur->klassci_id}\n";
echo "  - KLASSCI Token: " . (empty($coordinateur->klassci_token) ? "❌ Absent" : "✅ Présent") . "\n\n";

if (empty($coordinateur->klassci_token)) {
    echo "❌ Le coordinateur n'a pas de token KLASSCI\n";
    exit(1);
}

echo "========================================\n";
echo "INTERROGATION ENDPOINT /enseignants\n";
echo "========================================\n\n";

try {
    $url = 'https://presentation.klassci.com/api/lms/enseignants';

    echo "📍 URL: {$url}\n";
    echo "🔑 Token: " . substr($coordinateur->klassci_token, 0, 20) . "...\n\n";

    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . $coordinateur->klassci_token,
        'Accept' => 'application/json',
    ])->get($url);

    $statusCode = $response->status();
    echo "📊 Status Code: {$statusCode}\n";

    if ($response->successful()) {
        $data = $response->json();

        echo "✅ Réponse réussie!\n\n";
        echo "========================================\n";
        echo "STRUCTURE DE LA RÉPONSE\n";
        echo "========================================\n\n";

        echo "Type de données: " . gettype($data) . "\n";

        if (is_array($data)) {
            echo "Nombre d'éléments: " . count($data) . "\n\n";

            if (count($data) > 0) {
                echo "========================================\n";
                echo "PREMIER ENSEIGNANT (EXEMPLE)\n";
                echo "========================================\n\n";

                $premier = $data[0];
                echo json_encode($premier, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

                echo "========================================\n";
                echo "TOUS LES ENSEIGNANTS\n";
                echo "========================================\n\n";

                foreach ($data as $index => $enseignant) {
                    $num = $index + 1;
                    $nom = $enseignant['nom'] ?? 'N/A';
                    $prenom = $enseignant['prenom'] ?? 'N/A';
                    $email = $enseignant['email'] ?? 'N/A';
                    $matieres = isset($enseignant['matieres']) ? count($enseignant['matieres']) : 0;
                    $classes = isset($enseignant['classes']) ? count($enseignant['classes']) : 0;

                    echo "{$num}. {$nom} {$prenom}\n";
                    echo "   Email: {$email}\n";
                    echo "   Matières: {$matieres} | Classes: {$classes}\n\n";
                }
            } else {
                echo "⚠️  Le tableau est VIDE - Aucun enseignant retourné\n\n";
                echo "Réponse complète:\n";
                echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            }
        } else {
            echo "\n⚠️  La réponse n'est pas un tableau\n\n";
            echo "Réponse complète:\n";
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }

    } else {
        echo "❌ Erreur {$statusCode}\n";
        echo "Message: " . $response->body() . "\n";
    }

} catch (\Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "\n========================================\n";
echo "TEST TERMINÉ\n";
echo "========================================\n";
