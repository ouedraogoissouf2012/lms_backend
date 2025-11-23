<?php

/**
 * Test de connexion pour Issouf Ouedraogo
 * Diagnostique pourquoi l'utilisateur est éjecté
 */

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Services\KlassciProxyService;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== DIAGNOSTIC CONNEXION ISSOUF OUEDRAOGO ===\n\n";

// 1. Vérifier l'utilisateur dans la base
echo "1️⃣  VÉRIFICATION BASE DE DONNÉES LOCALE\n";
echo str_repeat('-', 60) . "\n";

$user = DB::table('users')
    ->where('email', 'issouf.ouedraogo@esbtp.edu')
    ->first();

if (!$user) {
    echo "❌ PROBLÈME: Utilisateur introuvable en local\n";
    echo "   Email recherché: issouf.ouedraogo@esbtp.edu\n\n";

    // Chercher par nom
    $userByName = DB::table('users')
        ->where('name', 'LIKE', '%Issouf%')
        ->orWhere('name', 'LIKE', '%OUEDRAOGO%')
        ->get();

    if ($userByName->count() > 0) {
        echo "   Utilisateurs similaires trouvés:\n";
        foreach ($userByName as $u) {
            echo "   - {$u->name} ({$u->email})\n";
        }
    }
    exit(1);
}

echo "✅ Utilisateur trouvé en local:\n";
echo "   ID: {$user->id}\n";
echo "   Nom: {$user->name}\n";
echo "   Email: {$user->email}\n";
echo "   Role: {$user->role}\n";
echo "   Klassci ID: " . ($user->klassci_id ?? 'N/A') . "\n";
echo "   Token Klassci: " . ($user->klassci_token ? 'OUI' : 'NON') . "\n";
echo "   Mot de passe local: " . ($user->password ? 'OUI' : 'NON') . "\n\n";

// 2. Tester la connexion locale (si mot de passe existe)
echo "2️⃣  TEST AUTHENTIFICATION LOCALE\n";
echo str_repeat('-', 60) . "\n";

if ($user->password) {
    echo "Un mot de passe local existe.\n";
    echo "Tentative de vérification...\n\n";

    // On ne peut pas tester sans le mot de passe
    echo "⚠️  Je ne peux pas tester sans le mot de passe réel\n";
    echo "   Pour tester, utilisez le mot de passe que vous avez entré\n\n";
} else {
    echo "⚠️  AUCUN mot de passe local\n";
    echo "   L'authentification DOIT passer par Klassci\n\n";
}

// 3. Tester la connexion Klassci
echo "3️⃣  TEST AUTHENTIFICATION KLASSCI\n";
echo str_repeat('-', 60) . "\n";

$klassciService = app(KlassciProxyService::class);

echo "⚠️  Pour tester Klassci, j'ai besoin de votre mot de passe\n";
echo "   Je vais essayer avec le token existant de l'utilisateur...\n\n";

if ($user->klassci_token) {
    echo "Token Klassci existe: " . substr($user->klassci_token, 0, 20) . "...\n";
    echo "Tentative d'appel à l'API Klassci avec ce token...\n\n";

    try {
        $klassciMe = $klassciService->requestWithUserToken($user->klassci_token, 'auth/me', 'GET');

        echo "✅ Token valide! Réponse Klassci:\n";
        echo "   Nom: " . ($klassciMe['data']['user']['nom'] ?? 'N/A') . "\n";
        echo "   Email: " . ($klassciMe['data']['user']['email'] ?? 'N/A') . "\n";
        echo "   Role: " . ($klassciMe['data']['user']['role'] ?? 'N/A') . "\n\n";

        echo "✅ Le token Klassci fonctionne!\n\n";

    } catch (Exception $e) {
        echo "❌ PROBLÈME: Token Klassci invalide ou expiré\n";
        echo "   Erreur: {$e->getMessage()}\n\n";
        echo "   → C'EST PROBABLEMENT LA CAUSE DE L'ÉJECTION!\n\n";
    }
} else {
    echo "❌ PROBLÈME: Aucun token Klassci enregistré\n";
    echo "   → L'utilisateur doit se reconnecter via Klassci\n\n";
}

// 4. Vérifier les logs d'erreur
echo "4️⃣  VÉRIFICATION DES LOGS D'ERREUR\n";
echo str_repeat('-', 60) . "\n";

$logFile = storage_path('logs/laravel.log');

if (file_exists($logFile)) {
    $handle = fopen($logFile, 'r');
    fseek($handle, -50000, SEEK_END); // Lire les 50KB derniers
    $logs = fread($handle, 50000);
    fclose($handle);

    $lines = explode("\n", $logs);
    $errorLines = array_filter($lines, function($line) {
        return (stripos($line, 'error') !== false ||
                stripos($line, 'exception') !== false ||
                stripos($line, 'failed') !== false ||
                stripos($line, 'issouf') !== false) &&
                stripos($line, 'local.ERROR') !== false;
    });

    if (!empty($errorLines)) {
        echo "⚠️  Erreurs trouvées dans les logs:\n\n";
        foreach (array_slice($errorLines, -5) as $log) {
            echo "   " . trim($log) . "\n\n";
        }
    } else {
        echo "✅ Aucune erreur récente trouvée pour Issouf\n\n";
    }
} else {
    echo "⚠️  Fichier de log introuvable\n\n";
}

// 5. Diagnostic complet
echo "\n5️⃣  DIAGNOSTIC ET SOLUTIONS\n";
echo str_repeat('=', 60) . "\n\n";

$problems = [];

if (!$user->klassci_token) {
    $problems[] = [
        'titre' => 'AUCUN TOKEN KLASSCI',
        'description' => 'L\'utilisateur n\'a pas de token Klassci enregistré',
        'solution' => 'Réessayez de vous connecter avec vos identifiants Klassci',
    ];
}

if ($user->klassci_token) {
    // Vérifier si le token est expiré
    try {
        $klassciService->requestWithUserToken($user->klassci_token, 'auth/me', 'GET');
    } catch (Exception $e) {
        $problems[] = [
            'titre' => 'TOKEN KLASSCI EXPIRÉ',
            'description' => 'Le token Klassci n\'est plus valide',
            'solution' => 'Reconnectez-vous avec vos identifiants Klassci',
        ];
    }
}

if (empty($problems)) {
    echo "✅ AUCUN PROBLÈME DÉTECTÉ\n\n";

    echo "Le compte semble correct. Si vous êtes toujours éjecté:\n\n";

    echo "1. Vérifiez le MOT DE PASSE:\n";
    echo "   - Utilisez l'email: issouf.ouedraogo@esbtp.edu\n";
    echo "   - Utilisez le mot de passe Klassci\n\n";

    echo "2. Vérifiez les LOGS du FRONTEND:\n";
    echo "   - Ouvrez la console du navigateur (F12)\n";
    echo "   - Regardez les erreurs dans l'onglet Console\n";
    echo "   - Regardez les requêtes dans l'onglet Network\n\n";

    echo "3. TESTEZ avec CURL:\n";
    echo "   curl -X POST http://localhost:8000/api/auth/login \\\n";
    echo "     -H 'Content-Type: application/json' \\\n";
    echo "     -d '{\"username\":\"issouf.ouedraogo@esbtp.edu\",\"password\":\"VOTRE_MOT_DE_PASSE\"}'\n\n";

} else {
    echo "❌ PROBLÈMES DÉTECTÉS:\n\n";

    foreach ($problems as $i => $pb) {
        echo ($i + 1) . ". {$pb['titre']}\n";
        echo "   Description: {$pb['description']}\n";
        echo "   💡 Solution: {$pb['solution']}\n\n";
    }
}

echo "\n6️⃣  INFORMATIONS POUR LA CONNEXION\n";
echo str_repeat('=', 60) . "\n\n";

echo "Email à utiliser: {$user->email}\n";
echo "Mot de passe: Celui de Klassci\n\n";

echo "URL de connexion backend: http://localhost:8000/api/auth/login\n";
echo "Payload JSON:\n";
echo "{\n";
echo "  \"username\": \"{$user->email}\",\n";
echo "  \"password\": \"votre_mot_de_passe_klassci\"\n";
echo "}\n\n";

echo "=== FIN DU DIAGNOSTIC ===\n";
