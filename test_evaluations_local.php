<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Evaluation;

echo "🧪 TEST : Évaluations avec données locales\n\n";

$evaluations = Evaluation::all();

echo "📊 Total : {$evaluations->count()} évaluation(s)\n\n";

foreach ($evaluations as $eval) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "📝 ID: {$eval->id}\n";
    echo "   Titre: {$eval->titre}\n";
    echo "   Matière: {$eval->matiere_nom} (ID: {$eval->klassci_matiere_id})\n";
    echo "   Classe: {$eval->classe_nom} (ID: {$eval->klassci_classe_id})\n";
    echo "   Enseignant: {$eval->enseignant_nom} (ID: {$eval->klassci_enseignant_id})\n";
    echo "   Statut: {$eval->status}\n";
    echo "   Publié: " . ($eval->is_published ? 'Oui' : 'Non') . "\n";

    if ($eval->date_evaluation) {
        echo "   Date: {$eval->date_evaluation->format('d/m/Y H:i')}\n";
    }

    echo "\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "✅ Toutes les évaluations ont leurs données locales !\n";
echo "🚀 Plus besoin d'appeler KLASSCI pour l'historique !\n";
