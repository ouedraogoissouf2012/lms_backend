<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\EvaluationSubmission;
use Illuminate\Support\Facades\DB;

echo "🔍 VÉRIFICATION DES KLASSCI_ETUDIANT_ID\n\n";

// Requête directe SQL pour voir les valeurs exactes
$submissions = DB::table('evaluation_submissions')->get();

echo "📊 Total soumissions: {$submissions->count()}\n\n";

foreach ($submissions as $sub) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Soumission ID: {$sub->id}\n";
    echo "  evaluation_id: " . var_export($sub->evaluation_id, true) . "\n";
    echo "  klassci_etudiant_id: " . var_export($sub->klassci_etudiant_id, true) . "\n";
    echo "  user_id (si existe): " . var_export($sub->user_id ?? 'COLONNE INEXISTANTE', true) . "\n";
    echo "  status: {$sub->status}\n";
    echo "  note_sur_20: " . var_export($sub->note_sur_20, true) . "\n";
    echo "  score: " . var_export($sub->score, true) . "\n";
    echo "\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "✅ DIAGNOSTIC TERMINÉ\n\n";

echo "💡 ANALYSE:\n";
if ($submissions->where('klassci_etudiant_id', null)->count() > 0 ||
    $submissions->where('klassci_etudiant_id', '')->count() > 0) {
    echo "  ⚠️  Certaines soumissions ont klassci_etudiant_id NULL ou vide\n";
    echo "  → Cela explique pourquoi les résultats ne s'affichent pas\n";
    echo "  → La méthode getResultsByClass() cherche par klassci_etudiant_id\n";
}
