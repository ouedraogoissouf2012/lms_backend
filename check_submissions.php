<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;

echo "🔍 VÉRIFICATION DES SOUMISSIONS D'ÉVALUATION\n\n";

// 1. Vérifier les évaluations
$evaluations = Evaluation::all();
echo "📊 Total évaluations: {$evaluations->count()}\n\n";

foreach ($evaluations as $eval) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "📝 Évaluation ID {$eval->id}: {$eval->titre}\n";
    echo "   Classe: {$eval->classe_nom} (ID: {$eval->klassci_classe_id})\n";
    echo "   Statut: {$eval->status}\n";
    echo "   Publié: " . ($eval->is_published ? 'Oui' : 'Non') . "\n";

    // Compter les soumissions
    $submissions = $eval->submissions;
    echo "   Soumissions: {$submissions->count()}\n";

    if ($submissions->count() > 0) {
        echo "\n   📋 Détails des soumissions:\n";
        foreach ($submissions as $sub) {
            $user = User::find($sub->user_id);
            $userName = $user ? $user->name : "Utilisateur #{$sub->user_id}";
            echo "     - {$userName}: Note {$sub->note}/{$sub->note_max} ";
            echo "({$sub->status}) - Soumis le " . $sub->submitted_at->format('d/m/Y H:i') . "\n";
        }
    } else {
        echo "   ⚠️  Aucune soumission pour cette évaluation\n";
    }

    echo "\n";
}

// 2. Vérifier le total de soumissions
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$totalSubmissions = EvaluationSubmission::count();
echo "📊 TOTAL SOUMISSIONS DANS LA BASE: {$totalSubmissions}\n\n";

if ($totalSubmissions > 0) {
    echo "Liste de toutes les soumissions:\n";
    $allSubs = EvaluationSubmission::all();
    foreach ($allSubs as $sub) {
        $user = User::find($sub->user_id);
        $eval = Evaluation::find($sub->evaluation_id);
        $userName = $user ? $user->name : "User #{$sub->user_id}";
        $evalTitre = $eval ? $eval->titre : "Éval #{$sub->evaluation_id}";

        echo "  - {$userName} → {$evalTitre}: {$sub->note}/{$sub->note_max} ({$sub->status})\n";
    }
}

echo "\n✅ VÉRIFICATION TERMINÉE\n";
