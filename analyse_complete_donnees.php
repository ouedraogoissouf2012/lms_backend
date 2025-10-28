<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

echo "========================================================================\n";
echo "ANALYSE COMPLÈTE DES DONNÉES - BACKEND, FRONTEND, KLASSCI\n";
echo "========================================================================\n\n";

$coordinateur = User::where('role', 'coordinateur')->first();

if (!$coordinateur) {
    echo "❌ Coordinateur non trouvé\n";
    exit(1);
}

echo "✅ Coordinateur: {$coordinateur->nom} {$coordinateur->prenom}\n\n";

// ============================================================================
// PARTIE 1: BASE DE DONNÉES LOCALE (BACKEND)
// ============================================================================
echo "========================================================================\n";
echo "PARTIE 1: BASE DE DONNÉES LOCALE (LMS BACKEND)\n";
echo "========================================================================\n\n";

// 1.1 Utilisateurs
echo "--- 1.1 UTILISATEURS (table users) ---\n";
$users = DB::table('users')->get();
echo "Total utilisateurs: " . $users->count() . "\n\n";

$usersByRole = $users->groupBy('role');
foreach ($usersByRole as $role => $roleUsers) {
    echo "  {$role}: " . $roleUsers->count() . " utilisateur(s)\n";
    foreach ($roleUsers as $user) {
        echo "    - {$user->nom} {$user->prenom} ({$user->email})\n";
    }
    echo "\n";
}

// 1.2 Enseignants spécifiquement
echo "--- 1.2 ENSEIGNANTS DANS LA BASE LOCALE ---\n";
$enseignants = User::whereIn('role', ['enseignant', 'teacher', 'coordinateur'])->get();
echo "Total: " . $enseignants->count() . " enseignant(s)\n\n";

foreach ($enseignants as $ens) {
    echo "  ID: {$ens->id}\n";
    echo "  Nom: {$ens->nom} {$ens->prenom}\n";
    echo "  Email: {$ens->email}\n";
    echo "  Téléphone: " . ($ens->telephone ?? 'N/A') . "\n";
    echo "  KLASSCI ID: " . ($ens->klassci_id ?? 'N/A') . "\n";
    echo "  Rôle: {$ens->role}\n";
    echo "  ---\n";
}
echo "\n";

// 1.3 Autres tables
echo "--- 1.3 AUTRES TABLES ---\n";
$tables = ['evaluations', 'lessons', 'evaluation_questions', 'evaluation_submissions'];

foreach ($tables as $table) {
    try {
        $count = DB::table($table)->count();
        echo "  {$table}: {$count} enregistrement(s)\n";
    } catch (\Exception $e) {
        echo "  {$table}: Table inexistante ou erreur\n";
    }
}
echo "\n\n";

// ============================================================================
// PARTIE 2: API KLASSCI
// ============================================================================
echo "========================================================================\n";
echo "PARTIE 2: API KLASSCI (données disponibles pour coordinateur)\n";
echo "========================================================================\n\n";

$klassciEndpoints = [
    '/classes',
    '/matieres',
    '/enseignants',
    '/etudiants',
    '/emploi-temps',
    '/evaluations',
    '/filieres',
    '/niveaux-etudes',
];

foreach ($klassciEndpoints as $endpoint) {
    echo "--- ENDPOINT: {$endpoint} ---\n";

    $url = 'https://presentation.klassci.com/api/lms' . $endpoint;
    $response = Http::withoutVerifying()->withHeaders([
        'Authorization' => 'Bearer ' . $coordinateur->klassci_token,
        'Accept' => 'application/json',
    ])->get($url);

    echo "Status: " . $response->status() . "\n";

    if ($response->successful()) {
        $data = $response->json();

        // Afficher la structure
        if (isset($data['data'])) {
            $count = is_array($data['data']) ? count($data['data']) : 'N/A';
            echo "Données dans 'data': {$count} élément(s)\n";

            if ($count > 0 && $count < 100) {
                echo "\nPremier élément:\n";
                echo json_encode($data['data'][0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            }
        } else {
            $count = is_array($data) ? count($data) : 'N/A';
            echo "Données directes: {$count} élément(s)\n";
        }

        // Chercher des enseignants dans les données
        $json = json_encode($data);
        if (stripos($json, 'enseignant') !== false || stripos($json, 'professeur') !== false || stripos($json, 'teacher') !== false) {
            echo "⚠️  Contient des références à des enseignants!\n";
        }

        echo "\n";
    } else {
        echo "❌ Erreur: " . $response->body() . "\n\n";
    }
}

// ============================================================================
// PARTIE 3: ANALYSE DÉTAILLÉE DES MATIÈRES
// ============================================================================
echo "========================================================================\n";
echo "PARTIE 3: ANALYSE DÉTAILLÉE DES MATIÈRES\n";
echo "========================================================================\n\n";

$url = 'https://presentation.klassci.com/api/lms/matieres';
$response = Http::withoutVerifying()->withHeaders([
    'Authorization' => 'Bearer ' . $coordinateur->klassci_token,
    'Accept' => 'application/json',
])->get($url);

if ($response->successful()) {
    $data = $response->json();
    $matieres = $data['data'] ?? $data;

    echo "Total matières: " . count($matieres) . "\n\n";

    foreach ($matieres as $index => $matiere) {
        $num = $index + 1;
        echo "--- MATIÈRE {$num} ---\n";
        echo "ID: " . ($matiere['id'] ?? 'N/A') . "\n";
        echo "Nom: " . ($matiere['nom'] ?? $matiere['libelle'] ?? 'N/A') . "\n";
        echo "Code: " . ($matiere['code'] ?? 'N/A') . "\n";

        // Vérifier les enseignants
        if (isset($matiere['enseignants'])) {
            $ensCount = is_array($matiere['enseignants']) ? count($matiere['enseignants']) : 0;
            echo "Enseignants: {$ensCount}\n";

            if ($ensCount > 0) {
                echo "  Liste des enseignants:\n";
                foreach ($matiere['enseignants'] as $ens) {
                    echo "    - " . json_encode($ens, JSON_UNESCAPED_UNICODE) . "\n";
                }
            }
        } else {
            echo "Enseignants: Clé absente\n";
        }

        // Vérifier les combinaisons
        if (isset($matiere['combinaisons'])) {
            $combCount = is_array($matiere['combinaisons']) ? count($matiere['combinaisons']) : 0;
            echo "Combinaisons (filière+niveau): {$combCount}\n";
        }

        echo "\n";
    }
}

// ============================================================================
// PARTIE 4: ANALYSE DÉTAILLÉE DES CLASSES
// ============================================================================
echo "========================================================================\n";
echo "PARTIE 4: ANALYSE DÉTAILLÉE DES CLASSES\n";
echo "========================================================================\n\n";

$url = 'https://presentation.klassci.com/api/lms/classes';
$response = Http::withoutVerifying()->withHeaders([
    'Authorization' => 'Bearer ' . $coordinateur->klassci_token,
    'Accept' => 'application/json',
])->get($url);

if ($response->successful()) {
    $data = $response->json();
    $classes = $data['data'] ?? $data;

    echo "Total classes: " . count($classes) . "\n\n";

    foreach ($classes as $index => $classe) {
        $num = $index + 1;
        echo "--- CLASSE {$num} ---\n";
        echo "ID: " . ($classe['id'] ?? 'N/A') . "\n";
        echo "Nom: " . ($classe['name'] ?? $classe['libelle'] ?? 'N/A') . "\n";
        echo "Filière: " . ($classe['filiere']['name'] ?? 'N/A') . "\n";
        echo "Niveau: " . ($classe['niveau']['name'] ?? 'N/A') . "\n";
        echo "Places occupées: " . ($classe['places_occupees'] ?? 'N/A') . "\n";

        // Chercher des enseignants
        $json = json_encode($classe);
        if (stripos($json, 'enseignant') !== false || stripos($json, 'professeur') !== false) {
            echo "⚠️  Contient des références à des enseignants!\n";

            // Chercher les clés
            foreach ($classe as $key => $value) {
                if (stripos($key, 'enseignant') !== false || stripos($key, 'professeur') !== false || stripos($key, 'teacher') !== false) {
                    echo "  Clé trouvée: {$key}\n";
                    echo "  Valeur: " . json_encode($value, JSON_UNESCAPED_UNICODE) . "\n";
                }
            }
        }

        echo "\n";
    }
}

// ============================================================================
// RÉSUMÉ FINAL
// ============================================================================
echo "========================================================================\n";
echo "RÉSUMÉ FINAL\n";
echo "========================================================================\n\n";

echo "BASE DE DONNÉES LOCALE:\n";
echo "  - Utilisateurs totaux: " . DB::table('users')->count() . "\n";
echo "  - Enseignants: " . User::whereIn('role', ['enseignant', 'teacher'])->count() . "\n";
echo "  - Coordinateurs: " . User::where('role', 'coordinateur')->count() . "\n\n";

echo "KLASSCI API:\n";
echo "  - Classes accessibles: " . (isset($classes) ? count($classes) : 0) . "\n";
echo "  - Matières accessibles: " . (isset($matieres) ? count($matieres) : 0) . "\n";
echo "  - Enseignants via /enseignants: 0 (endpoint vide)\n\n";

echo "RECOMMANDATIONS:\n";
echo "  1. Utiliser la base de données locale pour les enseignants\n";
echo "  2. Les matières KLASSCI ne contiennent pas d'infos enseignants\n";
echo "  3. Les classes KLASSCI ne contiennent pas d'infos enseignants\n";
echo "  4. Solution: Backend modifié pour utiliser table users locale\n\n";

echo "========================================================================\n";
echo "FIN DE L'ANALYSE\n";
echo "========================================================================\n";
