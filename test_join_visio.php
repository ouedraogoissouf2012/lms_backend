<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Seance;
use App\Services\KlassciProxyService;
use Illuminate\Support\Facades\Log;

echo "🧪 TEST JOINTURE VISIO - MARCEL OUEDRAOGO\n";
echo "==========================================\n\n";

// 1. Récupérer l'étudiant MARCEL
$user = User::where('email', 'marcel.ouedraogo@esbtp.edu')->first();

if (!$user) {
    echo "❌ Étudiant MARCEL non trouvé\n";
    exit(1);
}

echo "✅ Étudiant trouvé:\n";
echo "  - ID: {$user->id}\n";
echo "  - Nom: {$user->name}\n";
echo "  - Email: {$user->email}\n";
echo "  - KLASSCI ID: {$user->klassci_id}\n";
echo "  - Role: {$user->role}\n";
echo "  - Has token: " . ($user->klassci_token ? 'OUI' : 'NON') . "\n\n";

// 2. Récupérer la séance 37
$seanceId = 37;
$visio = Seance::where('klassci_seance_id', $seanceId)->first();

if (!$visio) {
    echo "❌ Séance {$seanceId} non trouvée\n";
    exit(1);
}

echo "✅ Séance trouvée:\n";
echo "  - ID local: {$visio->id}\n";
echo "  - KLASSCI Seance ID: {$visio->klassci_seance_id}\n";
echo "  - KLASSCI Classe ID: " . ($visio->klassci_classe_id ?? 'VIDE/NULL') . "\n";
echo "  - Visio Status: {$visio->visio_status}\n";
echo "  - Visio Enabled: {$visio->visio_enabled}\n\n";

// 3. Vérifier le status de la visio
if ($visio->visio_status !== 'active') {
    echo "❌ Visio pas active (status: {$visio->visio_status})\n";
    exit(1);
}

echo "✅ Visio est active\n\n";

// 4. Récupérer le classe_id
$classeId = $visio->klassci_classe_id;

if (empty($classeId)) {
    echo "⚠️  KLASSCI Classe ID manquant, récupération depuis API...\n";

    try {
        $klassciService = app(KlassciProxyService::class);
        $seanceResponse = $klassciService->get("seances/{$seanceId}");

        echo "  - Réponse API: " . json_encode($seanceResponse, JSON_PRETTY_PRINT) . "\n\n";

        $classeId = $seanceResponse['data']['classe_id'] ?? null;

        if (empty($classeId)) {
            echo "❌ Impossible de récupérer classe_id depuis KLASSCI\n";
            exit(1);
        }

        echo "✅ Classe ID récupéré: {$classeId}\n\n";

    } catch (\Exception $e) {
        echo "❌ ERREUR lors de la récupération de la séance depuis KLASSCI:\n";
        echo "  - Message: {$e->getMessage()}\n";
        echo "  - File: {$e->getFile()}:{$e->getLine()}\n";
        exit(1);
    }
} else {
    echo "✅ Classe ID présent: {$classeId}\n\n";
}

// 5. Récupérer les étudiants de la classe
echo "📋 Récupération des étudiants de la classe {$classeId}...\n";

try {
    $klassciService = app(KlassciProxyService::class);
    $etudiantsResponse = $klassciService->get("classes/{$classeId}/etudiants");
    $etudiants = $etudiantsResponse['data'] ?? [];

    echo "✅ " . count($etudiants) . " étudiants récupérés\n\n";

    // 6. Vérifier si MARCEL est inscrit (par email)
    $etudiantInscrit = collect($etudiants)->first(function ($etudiant) use ($user) {
        // Chercher par email d'abord
        if (!empty($etudiant['email']) && !empty($user->email)) {
            return strtolower($etudiant['email']) === strtolower($user->email);
        }
        // Fallback: chercher par KLASSCI ID
        return $etudiant['id'] == $user->klassci_id;
    });

    if (!$etudiantInscrit) {
        echo "❌ MARCEL n'est PAS inscrit dans cette classe\n";
        echo "\nListe des étudiants de la classe:\n";
        foreach ($etudiants as $index => $etudiant) {
            echo "  " . ($index + 1) . ". {$etudiant['nom']} {$etudiant['prenom']} - {$etudiant['email']} (ID: {$etudiant['id']})\n";
        }
        exit(1);
    }

    echo "✅ MARCEL EST inscrit dans la classe!\n";
    echo "  - Données KLASSCI:\n";
    echo "    - ID: {$etudiantInscrit['id']}\n";
    echo "    - Nom: {$etudiantInscrit['nom']}\n";
    echo "    - Prénom: " . ($etudiantInscrit['prenom'] ?? 'N/A') . "\n";
    echo "    - Email: {$etudiantInscrit['email']}\n\n";

    // 7. Vérifier si le KLASSCI ID a changé
    if ($etudiantInscrit['id'] != $user->klassci_id) {
        echo "⚠️  KLASSCI ID différent:\n";
        echo "  - BDD locale: {$user->klassci_id}\n";
        echo "  - KLASSCI API: {$etudiantInscrit['id']}\n";
        echo "  - Action: Devrait être mis à jour automatiquement\n\n";
    }

    echo "✅ TOUS LES TESTS SONT PASSÉS!\n";
    echo "L'étudiant devrait pouvoir rejoindre la visio.\n";

} catch (\Exception $e) {
    echo "❌ ERREUR lors de la vérification:\n";
    echo "  - Message: {$e->getMessage()}\n";
    echo "  - File: {$e->getFile()}:{$e->getLine()}\n";
    echo "  - Trace:\n{$e->getTraceAsString()}\n";
    exit(1);
}
