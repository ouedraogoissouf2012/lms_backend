<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Seance;
use App\Models\Lesson;

echo "🧪 TEST: Compteurs de matières (nouvelle méthode)\n";
echo str_repeat('=', 80) . "\n\n";

$matieres = [
    ['id' => 1, 'nom' => 'Marketing digital'],
    ['id' => 2, 'nom' => 'Algorithme'],
    ['id' => 3, 'nom' => 'Anglais']
];

foreach ($matieres as $matiere) {
    $matiereId = $matiere['id'];
    $matiereNom = $matiere['nom'];

    echo "📚 {$matiereNom} (ID: {$matiereId})\n";
    echo str_repeat('-', 80) . "\n";

    // Leçons publiées
    $lessonsPubliees = Lesson::where('matiere_id', $matiereId)
        ->where('status', 'published')
        ->count();

    // Séances avec klassci_matiere_id
    $seances = Seance::where('klassci_matiere_id', $matiereId)->count();

    echo "   📖 Leçons publiées: {$lessonsPubliees}\n";
    echo "   🎯 Séances (klassci_matiere_id): {$seances}\n";

    if ($seances > 0) {
        $seancesList = Seance::where('klassci_matiere_id', $matiereId)
            ->select('klassci_seance_id', 'matiere_nom', 'visio_status')
            ->get();

        foreach ($seancesList as $s) {
            $status = $s->visio_status ?? 'N/A';
            echo "      ✓ Séance #{$s->klassci_seance_id} - {$s->matiere_nom} - Status: {$status}\n";
        }
    }

    echo "\n";
}

echo "✅ Test terminé!\n";
