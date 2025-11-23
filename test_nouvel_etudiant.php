<?php

/**
 * Test de connexion pour un nouvel étudiant créé dans Klassci
 */

require __DIR__ . '/vendor/autoload.php';

use App\Services\KlassciProxyService;
use Illuminate\Support\Facades\DB;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== DIAGNOSTIC: NOUVEL ÉTUDIANT KLASSCI ===\n\n";

echo "Pour diagnostiquer, j'ai besoin de quelques informations:\n\n";

echo "1️⃣  INFORMATIONS DE L'ÉTUDIANT\n";
echo "-----------------------------------------------\n";
echo "Quel est le USERNAME que tu utilises pour te connecter?\n";
echo "(Format: prenom.nom)\n\n";

echo "Exemple: Si l'email est jean.dupont@esbtp.edu\n";
echo "         Le username est: jean.dupont\n\n";

echo "2️⃣  VÉRIFICATION DANS KLASSCI\n";
echo "-----------------------------------------------\n";

// Utiliser un token existant pour interroger Klassci
$teacher = DB::table('users')
    ->where('role', 'enseignant')
    ->whereNotNull('klassci_token')
    ->first();

if (!$teacher) {
    echo "❌ Aucun enseignant avec token trouvé\n";
    exit(1);
}

$klassciService = app(KlassciProxyService::class);

echo "Recherche de tous les étudiants dans Klassci...\n\n";

try {
    $classes = $klassciService->requestWithUserToken($teacher->klassci_token, 'classes', 'GET');

    $allStudents = [];

    foreach ($classes['data'] ?? [] as $classe) {
        $classeDetails = $klassciService->requestWithUserToken(
            $teacher->klassci_token,
            "classes/{$classe['id']}",
            'GET'
        );

        foreach ($classeDetails['etudiants'] ?? [] as $etudiant) {
            // Extraire le username de l'email
            $email = $etudiant['email'] ?? null;
            $username = $email ? explode('@', $email)[0] : 'N/A';

            $allStudents[] = [
                'id' => $etudiant['id'],
                'nom' => $etudiant['nom'] ?? 'N/A',
                'email' => $email,
                'username' => $username,
                'classe' => $classe['nom'] ?? 'N/A',
            ];
        }
    }

    echo "✅ Trouvé " . count($allStudents) . " étudiant(s) dans Klassci:\n\n";

    foreach ($allStudents as $student) {
        echo "  • {$student['nom']}\n";
        echo "    Klassci ID: {$student['id']}\n";
        echo "    Email: {$student['email']}\n";
        echo "    Username: {$student['username']}\n";
        echo "    Classe: {$student['classe']}\n";

        // Vérifier s'il existe en local
        $localUser = DB::table('users')
            ->where('klassci_id', $student['id'])
            ->orWhere('email', $student['email'])
            ->first();

        if ($localUser) {
            echo "    ✅ Existe en local (ID: {$localUser->id})\n";
        } else {
            echo "    ❌ N'existe PAS en local - sera créé à la première connexion\n";
        }
        echo "\n";
    }

} catch (Exception $e) {
    echo "❌ Erreur: {$e->getMessage()}\n";
    exit(1);
}

echo "\n3️⃣  PROBLÈMES POSSIBLES\n";
echo "-----------------------------------------------\n\n";

echo "A. PAS D'EMAIL dans Klassci\n";
echo "   → CRITIQUE: Sans email, impossible de se connecter\n";
echo "   → Solution: Ajouter l'email dans Klassci\n\n";

echo "B. MAUVAIS USERNAME\n";
echo "   → Tu dois utiliser: prenom.nom (pas l'email complet)\n";
echo "   → Exemple: jean.dupont (PAS jean.dupont@esbtp.edu)\n\n";

echo "C. MAUVAIS MOT DE PASSE\n";
echo "   → Le mot de passe doit être celui défini dans Klassci\n";
echo "   → Vérifie dans Klassci ou réinitialise-le\n\n";

echo "D. COMPTE NON CRÉÉ DANS KLASSCI\n";
echo "   → Vérifie que l'étudiant apparaît dans la liste ci-dessus\n";
echo "   → Si non, il faut le créer dans Klassci d'abord\n\n";

echo "\n4️⃣  TESTER LA CONNEXION\n";
echo "-----------------------------------------------\n\n";

echo "Pour tester manuellement avec cURL:\n\n";
echo "curl -X POST http://localhost:8000/api/auth/login \\\n";
echo "  -H 'Content-Type: application/json' \\\n";
echo "  -d '{\n";
echo "    \"username\": \"prenom.nom\",\n";
echo "    \"password\": \"MOT_DE_PASSE_KLASSCI\"\n";
echo "  }'\n\n";

echo "\n5️⃣  VÉRIFIER LES LOGS\n";
echo "-----------------------------------------------\n\n";

$logFile = storage_path('logs/laravel.log');

if (file_exists($logFile)) {
    $handle = fopen($logFile, 'r');
    fseek($handle, -20000, SEEK_END);
    $logs = fread($handle, 20000);
    fclose($handle);

    $lines = explode("\n", $logs);
    $loginErrors = array_filter($lines, function($line) {
        return stripos($line, 'login') !== false
            && (stripos($line, 'error') !== false || stripos($line, 'failed') !== false);
    });

    if (!empty($loginErrors)) {
        echo "⚠️  Erreurs de connexion récentes:\n\n";
        foreach (array_slice($loginErrors, -5) as $log) {
            echo "  " . trim($log) . "\n\n";
        }
    } else {
        echo "✅ Aucune erreur de connexion récente\n\n";
    }
} else {
    echo "⚠️  Fichier de log non trouvé\n\n";
}

echo "\n6️⃣  CE QU'IL FAUT ME DIRE\n";
echo "=======================================================\n\n";

echo "Pour que je puisse t'aider, dis-moi:\n\n";

echo "1. Le USERNAME que tu utilises (format prenom.nom)\n";
echo "2. L'étudiant apparaît-il dans la liste ci-dessus?\n";
echo "3. Quel message d'erreur tu vois exactement?\n";
echo "4. Sur quelle page tu essaies de te connecter? (frontend ou backend direct?)\n\n";

echo "=== FIN DU DIAGNOSTIC ===\n";
