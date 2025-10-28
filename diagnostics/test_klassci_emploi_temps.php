<?php

/**
 * Script de test pour vérifier l'endpoint KLASSCI emploi-temps
 *
 * Usage: php diagnostics/test_klassci_emploi_temps.php [user_email]
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Services\KlassciProxyService;
use Carbon\Carbon;

echo "🔍 Test Endpoint KLASSCI: emploi-temps\n";
echo "=======================================\n\n";

// Récupérer utilisateur test
$email = $argv[1] ?? 'losseni.coulibaly@esbtp.sn'; // coordinateur par défaut
echo "👤 Utilisateur: $email\n";

$user = User::where('email', $email)->first();

if (!$user) {
    echo "❌ Utilisateur non trouvé: $email\n";
    echo "💡 Usage: php diagnostics/test_klassci_emploi_temps.php [user_email]\n";
    exit(1);
}

if (!$user->klassci_token) {
    echo "❌ Utilisateur n'a pas de token KLASSCI\n";
    echo "💡 Se reconnecter pour obtenir un token\n";
    exit(1);
}

echo "✅ Token KLASSCI trouvé\n";
echo "👔 Rôle: {$user->role}\n\n";

// Dates de recherche
$dateDebut = Carbon::now()->format('Y-m-d');
$dateFin = Carbon::now()->addDays(30)->format('Y-m-d');

echo "📅 Période recherchée: $dateDebut → $dateFin\n\n";

// Test avec KlassciProxyService
$klassciService = app(KlassciProxyService::class);

// Test 1: Sans filtres
echo "🧪 Test 1: GET /emploi-temps (sans filtres)\n";
echo "-------------------------------------------\n";

try {
    $url = "emploi-temps?date_debut={$dateDebut}&date_fin={$dateFin}";
    echo "🌐 URL: $url\n";

    $response = $klassciService->requestWithUserToken(
        $user->klassci_token,
        $url,
        'GET'
    );

    if (isset($response['data'])) {
        $count = count($response['data']);
        echo "✅ Requête réussie\n";
        echo "📊 Nombre de séances: $count\n";

        if ($count > 0) {
            echo "\n📝 Exemple de séance:\n";
            $seance = $response['data'][0];
            echo "   - ID: " . ($seance['id'] ?? 'N/A') . "\n";
            echo "   - Date: " . ($seance['date_seance'] ?? 'N/A') . "\n";
            echo "   - Heure: " . ($seance['heure_debut'] ?? 'N/A') . " - " . ($seance['heure_fin'] ?? 'N/A') . "\n";
            echo "   - Matière: " . ($seance['matiere']['libelle'] ?? 'N/A') . "\n";
            echo "   - Classe: " . ($seance['classe']['libelle'] ?? 'N/A') . "\n";
            echo "   - Enseignant: " . ($seance['enseignant']['nom'] ?? 'N/A') . "\n";
        } else {
            echo "\n⚠️  Aucune séance trouvée pour cette période\n";
            echo "💡 Vérifier dans KLASSCI si des séances sont programmées\n";
        }
    } else {
        echo "⚠️  Réponse sans 'data': " . json_encode($response) . "\n";
    }

} catch (\Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";

    // Détecter bug SQL spécifique
    if (strpos($e->getMessage(), 'date_cours') !== false) {
        echo "\n🐛 BUG KLASSCI DÉTECTÉ!\n";
        echo "   Le endpoint emploi-temps utilise 'date_cours' au lieu de 'date_seance'\n";
        echo "   → Ce bug doit être corrigé dans KLASSCI\n";
    }
}

echo "\n";

// Test 2: Avec filtre classe
echo "🧪 Test 2: GET /emploi-temps avec filtre classe\n";
echo "------------------------------------------------\n";

try {
    // Récupérer une classe
    $classesResponse = $klassciService->requestWithUserToken(
        $user->klassci_token,
        'classes',
        'GET'
    );

    if (isset($classesResponse['data']) && count($classesResponse['data']) > 0) {
        $classeId = $classesResponse['data'][0]['id'];
        $classeLibelle = $classesResponse['data'][0]['libelle'] ?? 'N/A';

        echo "🎯 Filtre classe: $classeLibelle (ID: $classeId)\n";

        $url = "emploi-temps?date_debut={$dateDebut}&date_fin={$dateFin}&classe_id={$classeId}";
        echo "🌐 URL: $url\n";

        $response = $klassciService->requestWithUserToken(
            $user->klassci_token,
            $url,
            'GET'
        );

        $count = count($response['data'] ?? []);
        echo "✅ Requête réussie\n";
        echo "📊 Nombre de séances pour cette classe: $count\n";
    } else {
        echo "⚠️  Aucune classe disponible pour test\n";
    }

} catch (\Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 3: Endpoint alternatif /seances (si disponible)
echo "🧪 Test 3: GET /seances (endpoint alternatif)\n";
echo "----------------------------------------------\n";

try {
    $response = $klassciService->requestWithUserToken(
        $user->klassci_token,
        'seances',
        'GET'
    );

    if (isset($response['data'])) {
        $count = count($response['data']);
        echo "✅ Requête réussie\n";
        echo "📊 Nombre total de séances: $count\n";
    } else {
        echo "⚠️  Réponse sans 'data'\n";
    }

} catch (\Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    echo "💡 Endpoint /seances peut ne pas exister dans KLASSCI\n";
}

echo "\n";
echo "📊 RÉSUMÉ\n";
echo "==========\n";
echo "Si 0 séances trouvées:\n";
echo "  1. Vérifier dans KLASSCI qu'il y a des séances programmées\n";
echo "  2. Vérifier la période (aujourd'hui + 30 jours)\n";
echo "  3. Vérifier les droits de l'utilisateur\n";
echo "\n";
echo "Si erreur SQL 'date_cours':\n";
echo "  → Bug KLASSCI à corriger (colonne inexistante)\n";
echo "  → Backend LMS gère déjà ce cas gracieusement\n";
echo "\n";
