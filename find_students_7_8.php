<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\KlassciProxyService;
use App\Models\User;

echo "🔍 RECHERCHE DES ÉTUDIANTS ID 7 et 8 dans KLASSCI\n\n";

$klassciService = app(KlassciProxyService::class);

// Récupérer un utilisateur avec token
$user = User::where('role', 'coordinateur')
    ->whereNotNull('klassci_token')
    ->first();

if (!$user || !$user->klassci_token) {
    echo "❌ Pas d'utilisateur avec token\n";
    exit(1);
}

echo "👤 Utilisation du token de: {$user->name}\n\n";

try {
    // Méthode 1: Chercher directement l'étudiant par ID
    echo "🔎 Tentative 1: GET /etudiants/7\n";
    try {
        $etudiant7 = $klassciService->requestWithUserToken($user->klassci_token, 'etudiants/7', 'GET');
        echo "✅ Étudiant ID 7 trouvé:\n";
        echo json_encode($etudiant7, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    } catch (\Exception $e) {
        echo "❌ Étudiant ID 7 non trouvé: {$e->getMessage()}\n\n";
    }

    echo "🔎 Tentative 2: GET /etudiants/8\n";
    try {
        $etudiant8 = $klassciService->requestWithUserToken($user->klassci_token, 'etudiants/8', 'GET');
        echo "✅ Étudiant ID 8 trouvé:\n";
        echo json_encode($etudiant8, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    } catch (\Exception $e) {
        echo "❌ Étudiant ID 8 non trouvé: {$e->getMessage()}\n\n";
    }

    // Méthode 2: Lister TOUS les étudiants et chercher 7 et 8
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "🔎 Tentative 3: Lister TOUS les étudiants\n\n";

    $allEtudiants = $klassciService->requestWithUserToken($user->klassci_token, 'etudiants', 'GET');
    $etudiants = $allEtudiants['data'] ?? [];

    echo "📊 Total étudiants: " . count($etudiants) . "\n\n";

    $found7 = false;
    $found8 = false;

    foreach ($etudiants as $etudiant) {
        if ($etudiant['id'] == 7) {
            $found7 = true;
            echo "✅ Étudiant ID 7 trouvé dans la liste:\n";
            echo "   Nom: {$etudiant['nom']} {$etudiant['prenom']}\n";
            echo "   Email: {$etudiant['email']}\n";
            echo "   Classe: " . ($etudiant['classe']['libelle'] ?? 'N/A') . "\n\n";
        }
        if ($etudiant['id'] == 8) {
            $found8 = true;
            echo "✅ Étudiant ID 8 trouvé dans la liste:\n";
            echo "   Nom: {$etudiant['nom']} {$etudiant['prenom']}\n";
            echo "   Email: {$etudiant['email']}\n";
            echo "   Classe: " . ($etudiant['classe']['libelle'] ?? 'N/A') . "\n\n";
        }
    }

    if (!$found7) {
        echo "❌ Étudiant ID 7 PAS dans la liste\n";
    }
    if (!$found8) {
        echo "❌ Étudiant ID 8 PAS dans la liste\n";
    }

} catch (\Exception $e) {
    echo "❌ Erreur: {$e->getMessage()}\n";
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "✅ RECHERCHE TERMINÉE\n";
