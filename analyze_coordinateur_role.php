<?php

/**
 * Script d'analyse du rôle Coordinateur dans KLASSCI
 *
 * Ce script interroge KLASSCI pour comprendre:
 * 1. Les permissions du coordinateur
 * 2. Les données retournées (admin_data, enseignant_data)
 * 3. Les endpoints accessibles
 * 4. La différence entre coordinateur et enseignant
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

echo "\n========================================\n";
echo "ANALYSE DU RÔLE COORDINATEUR - KLASSCI\n";
echo "========================================\n\n";

// 1. Récupérer l'utilisateur coordinateur de la DB
$coordinateur = User::where('role', 'coordinateur')->first();

if (!$coordinateur) {
    echo "❌ Aucun coordinateur trouvé dans la base de données locale\n";
    echo "Vérification des utilisateurs disponibles:\n";
    $users = User::all();
    foreach ($users as $user) {
        echo "  - {$user->name} ({$user->email}) - Rôle: {$user->role}\n";
    }
    exit(1);
}

echo "✅ Coordinateur trouvé:\n";
echo "  - ID: {$coordinateur->id}\n";
echo "  - Nom: {$coordinateur->name}\n";
echo "  - Email: {$coordinateur->email}\n";
echo "  - Rôle: {$coordinateur->role}\n";
echo "  - KLASSCI ID: {$coordinateur->klassci_id}\n";
echo "  - Token KLASSCI: " . ($coordinateur->klassci_token ? 'Disponible' : 'Non disponible') . "\n";

if (!$coordinateur->klassci_token) {
    echo "\n❌ Pas de token KLASSCI disponible pour cet utilisateur\n";
    echo "Veuillez vous connecter d'abord via l'interface pour générer un token\n";
    exit(1);
}

echo "\n========================================\n";
echo "2. INTERROGATION DES ENDPOINTS KLASSCI\n";
echo "========================================\n\n";

$token = $coordinateur->klassci_token;
$baseUrl = env('KLASSCI_API_URL', 'https://presentation.klassci.com/api/lms');

// Fonction helper pour faire des requêtes KLASSCI
function queryKlassci($endpoint, $token, $baseUrl) {
    try {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->get("{$baseUrl}/{$endpoint}");

        return [
            'success' => $response->successful(),
            'status' => $response->status(),
            'data' => $response->json(),
        ];
    } catch (\Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }
}

// Liste des endpoints à tester
$endpointsToTest = [
    'me' => 'Informations utilisateur',
    'me/teacher-dashboard' => 'Dashboard enseignant',
    'me/admin-dashboard' => 'Dashboard admin',
    'classes' => 'Liste des classes',
    'matieres' => 'Liste des matières',
    'seances' => 'Liste des séances',
    'enseignants' => 'Liste des enseignants',
    'etudiants' => 'Liste des étudiants',
];

$results = [];

foreach ($endpointsToTest as $endpoint => $description) {
    echo "📍 Test de l'endpoint: /{$endpoint}\n";
    echo "   Description: {$description}\n";

    $result = queryKlassci($endpoint, $token, $baseUrl);
    $results[$endpoint] = $result;

    if ($result['success']) {
        echo "   ✅ Status: {$result['status']}\n";

        // Analyser les données retournées
        if (isset($result['data']['data'])) {
            $data = $result['data']['data'];

            if (is_array($data)) {
                echo "   📊 Type: Array (" . count($data) . " éléments)\n";

                if (count($data) > 0 && $endpoint === 'me') {
                    echo "   🔍 Détails utilisateur:\n";
                    if (isset($data['role'])) echo "      - role: {$data['role']}\n";
                    if (isset($data['role_display_name'])) echo "      - role_display_name: {$data['role_display_name']}\n";
                    if (isset($data['is_admin'])) echo "      - is_admin: " . ($data['is_admin'] ? 'true' : 'false') . "\n";
                    if (isset($data['is_coordinateur'])) echo "      - is_coordinateur: " . ($data['is_coordinateur'] ? 'true' : 'false') . "\n";
                    if (isset($data['is_enseignant'])) echo "      - is_enseignant: " . ($data['is_enseignant'] ? 'true' : 'false') . "\n";
                    if (isset($data['is_etudiant'])) echo "      - is_etudiant: " . ($data['is_etudiant'] ? 'true' : 'false') . "\n";

                    if (isset($data['permissions']) && is_array($data['permissions'])) {
                        echo "      - permissions: [" . implode(', ', $data['permissions']) . "]\n";
                    }

                    if (isset($data['admin_data'])) {
                        echo "      - admin_data: " . (is_array($data['admin_data']) ? 'Object avec ' . count($data['admin_data']) . ' clés' : 'Present') . "\n";
                    }

                    if (isset($data['enseignant_data'])) {
                        echo "      - enseignant_data: " . (is_array($data['enseignant_data']) ? 'Object avec ' . count($data['enseignant_data']) . ' clés' : 'Present') . "\n";
                    }
                }
            } else {
                echo "   📊 Type: Object\n";
            }
        }
    } else {
        if (isset($result['status'])) {
            echo "   ❌ Status: {$result['status']}\n";
            if (isset($result['data']['message'])) {
                echo "   Message: {$result['data']['message']}\n";
            }
        } else {
            echo "   ❌ Erreur: {$result['error']}\n";
        }
    }
    echo "\n";
}

echo "\n========================================\n";
echo "3. ANALYSE DÉTAILLÉE DES DONNÉES /me\n";
echo "========================================\n\n";

if (isset($results['me']) && $results['me']['success']) {
    $userData = $results['me']['data']['data'] ?? [];

    echo "📋 DONNÉES COMPLÈTES DU COORDINATEUR:\n\n";
    echo json_encode($userData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
}

echo "\n========================================\n";
echo "4. COMPARAISON COORDINATEUR VS ENSEIGNANT\n";
echo "========================================\n\n";

echo "🔍 Analyse des accès:\n\n";

// Analyser les accès
$accessAnalysis = [
    'teacher-dashboard' => $results['me/teacher-dashboard']['success'] ?? false,
    'admin-dashboard' => $results['me/admin-dashboard']['success'] ?? false,
    'classes' => $results['classes']['success'] ?? false,
    'matieres' => $results['matieres']['success'] ?? false,
    'seances' => $results['seances']['success'] ?? false,
    'enseignants' => $results['enseignants']['success'] ?? false,
    'etudiants' => $results['etudiants']['success'] ?? false,
];

foreach ($accessAnalysis as $endpoint => $hasAccess) {
    $icon = $hasAccess ? '✅' : '❌';
    echo "{$icon} Accès à /{$endpoint}: " . ($hasAccess ? 'OUI' : 'NON') . "\n";
}

echo "\n========================================\n";
echo "5. RECOMMANDATIONS\n";
echo "========================================\n\n";

$recommendations = [];

if ($accessAnalysis['teacher-dashboard']) {
    $recommendations[] = "Le coordinateur a accès au dashboard enseignant - Il peut voir les données enseignant";
}

if ($accessAnalysis['admin-dashboard']) {
    $recommendations[] = "Le coordinateur a accès au dashboard admin - Il a des privilèges administratifs";
}

if (!$accessAnalysis['teacher-dashboard'] && $accessAnalysis['admin-dashboard']) {
    $recommendations[] = "⚠️ Le coordinateur N'A PAS accès au dashboard enseignant - Utiliser les endpoints génériques (classes, matieres)";
}

if ($accessAnalysis['classes'] && $accessAnalysis['matieres']) {
    $recommendations[] = "✅ Le coordinateur peut utiliser les endpoints génériques /classes et /matieres";
}

foreach ($recommendations as $i => $rec) {
    echo ($i + 1) . ". {$rec}\n";
}

echo "\n========================================\n";
echo "ANALYSE TERMINÉE\n";
echo "========================================\n\n";
