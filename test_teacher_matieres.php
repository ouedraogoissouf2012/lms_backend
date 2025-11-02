<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Lesson;
use App\Models\Seance;
use Illuminate\Support\Facades\DB;

echo "🧪 DIAGNOSTIC: Compteurs Matières Enseignant\n";
echo str_repeat('=', 80) . "\n\n";

// Trouver l'enseignant BEDE ABEL TEST
$teacher = User::where('name', 'BEDE ABEL TEST')
    ->orWhere('nom', 'BEDE')
    ->orWhere('email', 'like', '%bede%')
    ->first();

if (!$teacher) {
    echo "❌ Enseignant BEDE ABEL TEST non trouvé\n";
    echo "Recherche d'un autre enseignant...\n\n";
    $teacher = User::where('role', 'enseignant')->first();
}

if (!$teacher) {
    echo "❌ Aucun enseignant trouvé en BDD\n";
    exit(1);
}

echo "👤 Enseignant testé:\n";
echo "   - ID: {$teacher->id}\n";
echo "   - Nom: {$teacher->name}\n";
echo "   - Email: {$teacher->email}\n";
echo "   - KLASSCI ID: {$teacher->klassci_id}\n\n";

echo str_repeat('=', 80) . "\n";
echo "📊 ANALYSE PAR MATIÈRE\n";
echo str_repeat('=', 80) . "\n\n";

// Liste des matières connues (de l'interface)
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

    // 1. Compter les LESSONS publiées
    $lessonsPubliees = Lesson::where('matiere_id', $matiereId)
        ->where('status', 'published')
        ->count();

    echo "   📖 Leçons publiées: {$lessonsPubliees}\n";

    if ($lessonsPubliees > 0) {
        $lessons = Lesson::where('matiere_id', $matiereId)
            ->where('status', 'published')
            ->select('id', 'title', 'created_at')
            ->get();

        foreach ($lessons as $lesson) {
            echo "      ✓ {$lesson->title} (ID: {$lesson->id})\n";
        }
    }

    // 2. Compter les SÉANCES (table locale seances)
    $seancesLocales = Seance::where('matiere_id', $matiereId)
        ->count();

    echo "\n   🎯 Séances (table locale): {$seancesLocales}\n";

    if ($seancesLocales > 0) {
        $seances = Seance::where('matiere_id', $matiereId)
            ->select('id', 'klassci_seance_id', 'matiere_nom', 'enseignant_nom', 'visio_status')
            ->get();

        foreach ($seances as $seance) {
            $status = $seance->visio_status ?? 'non défini';
            echo "      ✓ Séance #{$seance->klassci_seance_id} - Status: {$status}\n";
            echo "        Enseignant: {$seance->enseignant_nom}\n";
        }
    }

    // 3. Compter avec klassci_id (au cas où matiere_id n'est pas rempli)
    $seancesKlassciId = Seance::whereRaw('LOWER(matiere_nom) LIKE ?', ['%' . strtolower($matiereNom) . '%'])
        ->count();

    if ($seancesKlassciId != $seancesLocales) {
        echo "\n   ⚠️ ATTENTION: Différence de comptage!\n";
        echo "      Par matiere_id: {$seancesLocales}\n";
        echo "      Par matiere_nom: {$seancesKlassciId}\n";
    }

    // 4. Évaluations (si table existe)
    try {
        $evaluations = DB::table('evaluations')
            ->where('matiere_id', $matiereId)
            ->count();

        echo "\n   📝 Évaluations: {$evaluations}\n";
    } catch (\Exception $e) {
        echo "\n   📝 Évaluations: Table non trouvée ou erreur\n";
    }

    echo "\n";
}

echo str_repeat('=', 80) . "\n";
echo "🔍 RÉSUMÉ DES DONNÉES GLOBALES\n";
echo str_repeat('=', 80) . "\n\n";

echo "Total leçons publiées: " . Lesson::where('status', 'published')->count() . "\n";
echo "Total séances en BDD: " . Seance::count() . "\n";

// Séances par status
echo "\nSéances par status:\n";
$statusCounts = Seance::select('visio_status', DB::raw('count(*) as count'))
    ->groupBy('visio_status')
    ->get();

foreach ($statusCounts as $row) {
    $status = $row->visio_status ?? 'NULL';
    echo "   - {$status}: {$row->count}\n";
}

// Vérifier les séances sans matiere_id
$seancesSansMatiere = Seance::whereNull('matiere_id')->count();
if ($seancesSansMatiere > 0) {
    echo "\n⚠️ PROBLÈME: {$seancesSansMatiere} séances n'ont PAS de matiere_id!\n";
    echo "   Ces séances ne seront pas comptées dans les matières.\n";
}

echo "\n✅ Diagnostic terminé!\n";
