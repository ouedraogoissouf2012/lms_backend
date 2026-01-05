<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\KlassciProxyService;
use App\Models\User;
use App\Models\Evaluation;

echo "🔍 DEBUG: Comparaison IDs étudiants\n\n";

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

// Récupérer l'évaluation 8 (classe_id = 1)
$evaluation = Evaluation::find(8);
echo "📝 Évaluation: {$evaluation->titre}\n";
echo "📚 Classe ID: {$evaluation->klassci_classe_id}\n\n";

// Récupérer les étudiants de cette classe depuis KLASSCI
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "👥 ÉTUDIANTS DEPUIS API KLASSCI (classe {$evaluation->klassci_classe_id}):\n\n";

try {
    $classeEtudiants = $klassciService->getClasseEtudiants($evaluation->klassci_classe_id);
    $etudiants = $classeEtudiants['data'] ?? [];

    foreach ($etudiants as $etudiant) {
        echo "  ID: {$etudiant['id']}\n";
        echo "  Nom: {$etudiant['nom']} {$etudiant['prenom']}\n";
        echo "  Email: {$etudiant['email']}\n";

        // Chercher l'utilisateur local
        $userLocal = User::where('email', $etudiant['email'])->first();
        if ($userLocal) {
            echo "  ✅ User local trouvé:\n";
            echo "     - user.id: {$userLocal->id}\n";
            echo "     - user.klassci_id: {$userLocal->klassci_id}\n";
        } else {
            echo "  ❌ Pas de user local avec cet email\n";
        }

        // Chercher les soumissions avec cet ID
        $submissions = $evaluation->submissions()
            ->where('klassci_etudiant_id', $etudiant['id'])
            ->get();

        if ($submissions->count() > 0) {
            echo "  ✅ Soumissions trouvées: {$submissions->count()}\n";
            foreach ($submissions as $sub) {
                echo "     - Note: {$sub->note_sur_20}\n";
            }
        } else {
            echo "  ⚠️  Aucune soumission avec klassci_etudiant_id = {$etudiant['id']}\n";
        }

        echo "\n";
    }
} catch (\Exception $e) {
    echo "❌ Erreur: {$e->getMessage()}\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "📋 SOUMISSIONS DANS LA BASE:\n\n";

$submissions = $evaluation->submissions;
foreach ($submissions as $sub) {
    echo "  Soumission ID: {$sub->id}\n";
    echo "  klassci_etudiant_id: {$sub->klassci_etudiant_id}\n";
    echo "  Note: {$sub->note_sur_20}\n";

    // Chercher l'étudiant KLASSCI avec cet ID
    foreach ($etudiants as $etudiant) {
        if ($etudiant['id'] == $sub->klassci_etudiant_id) {
            echo "  ✅ Match KLASSCI: {$etudiant['nom']} {$etudiant['prenom']}\n";
        }
    }

    echo "\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "✅ DEBUG TERMINÉ\n";
