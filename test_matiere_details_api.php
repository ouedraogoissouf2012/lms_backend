<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use App\Http\Controllers\API\LMSDataController;
use App\Models\User;

echo "🧪 TEST: API matiereDetails pour Marcel\n";
echo "=" . str_repeat("=", 70) . "\n\n";

// 1. Trouver Marcel
$marcel = User::where('email', 'LIKE', '%marcel%')->first();

if (!$marcel) {
    echo "❌ Marcel non trouvé\n";
    exit(1);
}

echo "✅ Utilisateur: {$marcel->name}\n";
echo "   Email: {$marcel->email}\n";
echo "   Role: {$marcel->role}\n\n";

// 2. Créer une fausse requête
$request = Request::create('/api/lms/matieres/2', 'GET');
$request->setUserResolver(function () use ($marcel) {
    return $marcel;
});

// 3. Appeler le contrôleur
$controller = new LMSDataController();

try {
    echo "📡 Appel: LMSDataController->matiereDetails(2)\n\n";

    $response = $controller->matiereDetails(2, $request);
    $responseData = json_decode($response->getContent(), true);

    if (!$responseData['success']) {
        echo "❌ Échec: " . $responseData['message'] . "\n";
        exit(1);
    }

    echo "✅ Réponse reçue\n\n";

    // 4. Vérifier les séances
    $seances = $responseData['data']['seances_programmees'] ?? [];

    echo "📚 SÉANCES RETOURNÉES PAR L'API:\n";
    echo str_repeat("-", 70) . "\n\n";

    foreach ($seances as $seance) {
        echo "🎓 Séance #{$seance['id']}:\n";
        echo "   - Matière: " . ($seance['matiere']['nom'] ?? 'N/A') . "\n";
        echo "   - Date: " . ($seance['programmation']['date'] ?? 'N/A') . "\n";
        echo "   - Classe: " . ($seance['classe']['nom'] ?? 'N/A') . "\n";
        echo "   - ⭐ ENSEIGNANT: " . ($seance['enseignant']['nom'] ?? 'N/A') . "\n";

        // Vérifier si c'est le bug
        if (isset($seance['enseignant']['nom'])) {
            if ($seance['enseignant']['nom'] === 'MARCEL OUEDRAOGO') {
                echo "      ❌ BUG: Affiche le nom de l'étudiant au lieu de l'enseignant!\n";
            } elseif ($seance['enseignant']['nom'] === 'BEDE ABEL TEST') {
                echo "      ✅ CORRECT: Affiche le vrai enseignant!\n";
            } else {
                echo "      ℹ️ Autre: " . $seance['enseignant']['nom'] . "\n";
            }
        }

        echo "\n";
    }

    echo "\n✅ Test terminé\n";

} catch (\Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    echo "   " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\n" . $e->getTraceAsString() . "\n";
}
