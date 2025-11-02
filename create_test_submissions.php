<?php

/**
 * SCRIPT SIMPLE - CRÉER DES SOUMISSIONS DE TEST
 * ==============================================
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use Illuminate\Support\Facades\DB;

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║   CRÉATION DE SOUMISSIONS DE TEST                              ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

try {
    // Prendre la première évaluation disponible
    $evaluation = Evaluation::first();

    if (!$evaluation) {
        echo "❌ Aucune évaluation trouvée en BDD\n";
        exit(1);
    }

    echo "📝 Évaluation trouvée: {$evaluation->titre} (ID: {$evaluation->id})\n";
    echo "📊 Barème: {$evaluation->bareme}/20\n\n";

    // Simuler 8 étudiants avec des soumissions
    $etudiants = [
        ['id' => 101, 'nom' => 'Dupont', 'prenom' => 'Marie', 'note' => 18.5],
        ['id' => 102, 'nom' => 'Martin', 'prenom' => 'Jean', 'note' => 16.0],
        ['id' => 103, 'nom' => 'Bernard', 'prenom' => 'Sophie', 'note' => 14.5],
        ['id' => 104, 'nom' => 'Dubois', 'prenom' => 'Pierre', 'note' => 17.0],
        ['id' => 105, 'nom' => 'Thomas', 'prenom' => 'Julie', 'note' => 12.5],
        ['id' => 106, 'nom' => 'Robert', 'prenom' => 'Lucas', 'note' => 15.5],
        ['id' => 107, 'nom' => 'Petit', 'prenom' => 'Emma', 'note' => 13.0],
        ['id' => 108, 'nom' => 'Richard', 'prenom' => 'Tom', 'note' => 10.5],
    ];

    echo "👥 Création de " . count($etudiants) . " soumissions...\n\n";

    foreach ($etudiants as $etudiant) {
        // Vérifier si la soumission existe déjà
        $existing = EvaluationSubmission::where('evaluation_id', $evaluation->id)
            ->where('klassci_etudiant_id', $etudiant['id'])
            ->first();

        if ($existing) {
            echo "   ⚠️  Soumission existe déjà: {$etudiant['prenom']} {$etudiant['nom']}\n";
            continue;
        }

        $bareme = $evaluation->bareme ?? 20;
        $score = ($etudiant['note'] / 20) * $bareme;

        $submission = EvaluationSubmission::create([
            'evaluation_id' => $evaluation->id,
            'klassci_etudiant_id' => $etudiant['id'],
            'score' => $score,
            'note_sur_20' => $etudiant['note'],
            'duree_secondes' => rand(1800, 3600),
            'status' => 'soumis',
            'attempt' => 1,
            'submitted_at' => now()->subDays(rand(1, 5)),
        ]);

        echo "   ✅ {$etudiant['prenom']} {$etudiant['nom']}: {$submission->note_sur_20}/20\n";
    }

    echo "\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    echo "✅ SOUMISSIONS CRÉÉES AVEC SUCCÈS!\n";
    echo "═══════════════════════════════════════════════════════════════\n";

    // Afficher les statistiques
    $total = EvaluationSubmission::where('evaluation_id', $evaluation->id)->count();
    $notes = EvaluationSubmission::where('evaluation_id', $evaluation->id)->pluck('note_sur_20');
    $moyenne = round($notes->avg(), 2);
    $min = $notes->min();
    $max = $notes->max();

    echo "\n📊 STATISTIQUES:\n";
    echo "   - Total soumissions: $total\n";
    echo "   - Moyenne: {$moyenne}/20\n";
    echo "   - Note min: {$min}/20\n";
    echo "   - Note max: {$max}/20\n";
    echo "═══════════════════════════════════════════════════════════════\n";

} catch (Exception $e) {
    echo "\n❌ ERREUR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
