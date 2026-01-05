<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\EvaluationSubmission;
use App\Models\User;

echo "🔍 DÉTAILS DES SOUMISSIONS\n\n";

$submissions = EvaluationSubmission::all();

foreach ($submissions as $sub) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "📋 Soumission ID: {$sub->id}\n";
    echo "   Évaluation ID: {$sub->evaluation_id}\n";
    echo "   User ID: {$sub->user_id}\n";

    // Chercher l'utilisateur
    $user = User::find($sub->user_id);
    if ($user) {
        echo "   👤 Utilisateur trouvé: {$user->name} ({$user->email})\n";
        echo "      Role: {$user->role}\n";
        echo "      Klassci ID: {$user->klassci_id}\n";
    } else {
        echo "   ❌ UTILISATEUR NON TROUVÉ (user_id={$sub->user_id})\n";
        echo "      → Cet utilisateur n'existe plus dans la table users\n";
    }

    echo "\n   📊 Données de soumission:\n";
    echo "      Status: {$sub->status}\n";
    echo "      Note: " . ($sub->note ?? 'NULL') . "\n";
    echo "      Note max: " . ($sub->note_max ?? 'NULL') . "\n";
    echo "      Score: " . ($sub->score ?? 'NULL') . "%\n";
    echo "      Started at: " . ($sub->started_at ? $sub->started_at->format('d/m/Y H:i') : 'NULL') . "\n";
    echo "      Submitted at: " . ($sub->submitted_at ? $sub->submitted_at->format('d/m/Y H:i') : 'NULL') . "\n";
    echo "      Attempt: {$sub->attempt_number}\n";

    // Vérifier les réponses
    $answers = is_string($sub->answers) ? json_decode($sub->answers, true) : $sub->answers;
    if ($answers && is_array($answers)) {
        echo "\n   📝 Réponses:\n";
        echo "      Nombre de réponses: " . count($answers) . "\n";
        if (count($answers) > 0) {
            echo "      Exemple première réponse:\n";
            $firstAnswer = reset($answers);
            echo "         Question ID: " . ($firstAnswer['question_id'] ?? 'N/A') . "\n";
            echo "         Réponse: " . ($firstAnswer['answer'] ?? 'N/A') . "\n";
        }
    } else {
        echo "\n   ⚠️  Aucune réponse enregistrée\n";
    }

    echo "\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "✅ ANALYSE TERMINÉE\n";
