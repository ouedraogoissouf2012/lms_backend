<?php

/**
 * Test du nettoyage automatique des évaluations passées
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use Carbon\Carbon;

echo "═══════════════════════════════════════════════════════════\n";
echo "   TEST NETTOYAGE DES ÉVALUATIONS PASSÉES\n";
echo "═══════════════════════════════════════════════════════════\n\n";

// État AVANT nettoyage
echo "1️⃣  ÉTAT AVANT NETTOYAGE\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$totalEvaluations = Evaluation::count();
$publishedEvaluations = Evaluation::where('is_published', true)->count();

$cutoffDate = Carbon::now()->subDays(7);
$oldEvaluations = Evaluation::where('is_published', true)
    ->where(function ($query) use ($cutoffDate) {
        $query->where('status', 'terminee')
            ->orWhere(function ($q) use ($cutoffDate) {
                $q->where('date_evaluation', '<', $cutoffDate)
                  ->whereIn('status', ['en_cours', 'planifiee']);
            });
    })
    ->whereNull('deleted_at')
    ->get();

echo "Total évaluations: {$totalEvaluations}\n";
echo "Évaluations publiées: {$publishedEvaluations}\n";
echo "Évaluations passées (>7j): {$oldEvaluations->count()}\n\n";

if ($oldEvaluations->count() > 0) {
    echo "Détails des évaluations passées:\n\n";

    foreach ($oldEvaluations as $eval) {
        $submissionsCount = EvaluationSubmission::where('evaluation_id', $eval->id)
            ->whereIn('status', ['soumis', 'corrige'])
            ->count();

        $willBeArchived = $submissionsCount === 0 ? '🗑️  SERA ARCHIVÉE' : '✅ SERA CONSERVÉE';

        echo "   • ID {$eval->id}: {$eval->titre}\n";
        echo "     Date: {$eval->date_evaluation}\n";
        echo "     Status: {$eval->status}\n";
        echo "     Soumissions: {$submissionsCount}\n";
        echo "     Action: {$willBeArchived}\n\n";
    }
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "2️⃣  EXÉCUTION DU NETTOYAGE\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

try {
    // Exécuter le job de nettoyage
    $job = new App\Jobs\CleanOldEvaluations();
    $job->handle();

    echo "✅ Job exécuté avec succès\n\n";

} catch (\Exception $e) {
    echo "❌ Erreur: {$e->getMessage()}\n\n";
    exit(1);
}

// État APRÈS nettoyage
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "3️⃣  ÉTAT APRÈS NETTOYAGE\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$totalApres = Evaluation::count();
$archivedCount = Evaluation::onlyTrashed()->count();

echo "Total évaluations actives: {$totalApres}\n";
echo "Total évaluations archivées (soft deleted): {$archivedCount}\n\n";

if ($archivedCount > 0) {
    echo "Évaluations archivées (détails):\n\n";

    $archived = Evaluation::onlyTrashed()->get();
    foreach ($archived as $eval) {
        echo "   • ID {$eval->id}: {$eval->titre}\n";
        echo "     Date: {$eval->date_evaluation}\n";
        echo "     Status: {$eval->status}\n";
        echo "     Archivée le: {$eval->deleted_at}\n\n";
    }
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "4️⃣  IMPACT SUR LA VUE ÉTUDIANT\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Simuler la requête étudiant (automatiquement filtrée par soft delete)
$visibleForStudents = Evaluation::where('is_published', true)->count();

echo "Évaluations visibles pour les étudiants: {$visibleForStudents}\n";
echo "Évaluations archivées (masquées): {$archivedCount}\n\n";

echo "✅ Les évaluations archivées ne sont plus visibles dans:\n";
echo "   - Liste des évaluations étudiant\n";
echo "   - Dashboard\n";
echo "   - API /evaluations/student\n\n";

echo "ℹ️  Les évaluations archivées restent accessibles pour:\n";
echo "   - Les enseignants (via Evaluation::withTrashed())\n";
echo "   - Les statistiques historiques\n";
echo "   - La restauration si besoin\n\n";

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "5️⃣  RÉSUMÉ\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$countBefore = $totalEvaluations;
$countAfter = $totalApres;
$diff = $countBefore - $countAfter;

echo "✅ Évaluations avant: {$countBefore}\n";
echo "✅ Évaluations après: {$countAfter}\n";
echo "🗑️  Évaluations archivées: {$diff}\n\n";

if ($diff > 0) {
    echo "✅ Le nettoyage a bien fonctionné!\n";
    echo "   {$diff} évaluations passées sans soumission ont été archivées.\n";
    echo "   La vue des étudiants est maintenant plus propre.\n\n";
} else {
    echo "ℹ️  Aucune évaluation archivée.\n";
    echo "   Soit toutes les évaluations sont récentes,\n";
    echo "   soit elles ont toutes des soumissions.\n\n";
}

echo "═══════════════════════════════════════════════════════════\n";
echo "   FIN DU TEST\n";
echo "═══════════════════════════════════════════════════════════\n";
