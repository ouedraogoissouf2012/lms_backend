<?php

/**
 * Test spécifique pour drissa.pare
 */

require __DIR__ . '/vendor/autoload.php';

use App\Services\KlassciProxyService;
use Illuminate\Support\Facades\DB;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== DIAGNOSTIC POUR drissa.pare ===\n\n";

$klassciService = app(KlassciProxyService::class);

echo "1️⃣  TEST DE CONNEXION DIREKTET À KLASSCI\n";
echo "-----------------------------------------------\n\n";

echo "Tentative de connexion avec username: drissa.pare\n";
echo "⚠️  Je ne vais PAS utiliser le vrai mot de passe\n";
echo "   (Tu peux tester manuellement avec le vrai mot de passe)\n\n";

// Vérifier s'il existe en local
$localUser = DB::table('users')
    ->where('email', 'LIKE', '%drissa%')
    ->orWhere('name', 'LIKE', '%drissa%')
    ->get();

if ($localUser->count() > 0) {
    echo "✅ Utilisateur(s) trouvé(s) en local:\n\n";
    foreach ($localUser as $user) {
        echo "  - ID: {$user->id}\n";
        echo "    Nom: {$user->name}\n";
        echo "    Email: {$user->email}\n";
        echo "    Role: {$user->role}\n";
        echo "    Klassci ID: " . ($user->klassci_id ?? 'N/A') . "\n\n";
    }
} else {
    echo "❌ Aucun utilisateur 'drissa' trouvé en local\n";
    echo "   → Sera créé automatiquement à la première connexion réussie\n\n";
}

echo "\n2️⃣  VÉRIFICATION DANS KLASSCI\n";
echo "-----------------------------------------------\n\n";

// Utiliser un token enseignant pour interroger Klassci
$teacher = DB::table('users')
    ->where('role', 'enseignant')
    ->whereNotNull('klassci_token')
    ->first();

if (!$teacher) {
    echo "❌ Aucun enseignant avec token pour interroger Klassci\n";
    exit(1);
}

echo "Recherche de 'drissa' dans Klassci...\n\n";

try {
    $classes = $klassciService->requestWithUserToken($teacher->klassci_token, 'classes', 'GET');

    $found = false;

    foreach ($classes['data'] ?? [] as $classe) {
        $classeDetails = $klassciService->requestWithUserToken(
            $teacher->klassci_token,
            "classes/{$classe['id']}",
            'GET'
        );

        foreach ($classeDetails['etudiants'] ?? [] as $etudiant) {
            if (stripos($etudiant['nom'] ?? '', 'drissa') !== false ||
                stripos($etudiant['email'] ?? '', 'drissa') !== false) {

                $found = true;

                echo "✅ TROUVÉ dans Klassci:\n\n";
                echo "  Klassci ID: {$etudiant['id']}\n";
                echo "  Nom: {$etudiant['nom']}\n";
                echo "  Email: {$etudiant['email']}\n";
                echo "  Classe: {$classe['nom']}\n";
                echo "  Matricule: " . ($etudiant['matricule'] ?? 'N/A') . "\n\n";

                // Extraire le username
                $email = $etudiant['email'] ?? null;
                if ($email) {
                    $username = explode('@', $email)[0];
                    echo "  👉 USERNAME À UTILISER: {$username}\n\n";
                } else {
                    echo "  ⚠️  PAS D'EMAIL - Ne peut pas se connecter!\n\n";
                }
            }
        }
    }

    if (!$found) {
        echo "❌ DRISSA PAS TROUVÉ dans Klassci\n\n";

        echo "Vérifications à faire dans Klassci:\n";
        echo "1. L'étudiant existe-t-il?\n";
        echo "2. Est-il inscrit dans une classe?\n";
        echo "3. L'inscription est-elle ACTIVE?\n";
        echo "4. A-t-il un EMAIL?\n\n";
    }

} catch (Exception $e) {
    echo "❌ Erreur: {$e->getMessage()}\n\n";
}

echo "\n3️⃣  ANALYSE DE L'ERREUR 401\n";
echo "-----------------------------------------------\n\n";

echo "L'erreur 401 Unauthorized peut venir de:\n\n";

echo "A. IDENTIFIANTS INCORRECTS\n";
echo "   → Username: drissa.pare (correct)\n";
echo "   → Mot de passe: ??? (à vérifier)\n\n";

echo "B. PAS D'EMAIL DANS KLASSCI\n";
echo "   → Si pas d'email, Klassci rejette la connexion\n\n";

echo "C. COMPTE NON ACTIF\n";
echo "   → L'étudiant doit être inscrit dans une classe active\n\n";

echo "D. ERREUR NO_ACTIVE_ENROLLMENT\n";
echo "   → L'étudiant n'est pas inscrit pour l'année en cours\n\n";

echo "\n4️⃣  TEST MANUEL\n";
echo "-----------------------------------------------\n\n";

echo "Pour tester directement avec cURL:\n\n";
echo "curl -X POST http://localhost:8000/api/auth/login \\\n";
echo "  -H 'Content-Type: application/json' \\\n";
echo "  -d '{\"username\":\"drissa.pare\",\"password\":\"TON_MOT_DE_PASSE\"}'\n\n";

echo "Ou teste dans Klassci directement:\n";
echo "→ Va sur l'interface Klassci\n";
echo "→ Essaie de te connecter avec drissa.pare\n";
echo "→ Si ça ne marche pas dans Klassci, ça ne marchera pas dans le LMS\n\n";

echo "\n5️⃣  SOLUTION\n";
echo "=======================================================\n\n";

echo "DANS KLASSCI, vérifie:\n\n";

echo "1. ✅ L'étudiant 'DRISSA PARE' existe\n";
echo "2. ✅ Il a un EMAIL (ex: drissa.pare@esbtp.edu)\n";
echo "3. ⚠️  Il est INSCRIT dans une CLASSE\n";
echo "4. ⚠️  L'inscription est ACTIVE pour l'année en cours\n\n";

echo "Sans ces 4 éléments, la connexion échouera!\n\n";

echo "=== FIN DU TEST ===\n";
