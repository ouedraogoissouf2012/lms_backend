<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Seance;

echo "🔍 DEBUG: Analyse des séances pour MARCEL OUEDRAOGO\n";
echo "=" . str_repeat("=", 70) . "\n\n";

// 1. Trouver l'utilisateur Marcel
$marcel = User::where('email', 'LIKE', '%marcel%')->first();

if (!$marcel) {
    echo "❌ Utilisateur Marcel non trouvé\n";
    exit(1);
}

echo "✅ Utilisateur trouvé:\n";
echo "   - ID: {$marcel->id}\n";
echo "   - Nom: {$marcel->name}\n";
echo "   - Email: {$marcel->email}\n";
echo "   - Role: {$marcel->role}\n";
echo "   - KLASSCI ID: {$marcel->klassci_id}\n\n";

// 2. Récupérer toutes les séances
echo "📊 SÉANCES DANS LA BASE DE DONNÉES:\n";
echo str_repeat("-", 70) . "\n";

$seances = Seance::orderBy('klassci_seance_id')->get();

foreach ($seances as $seance) {
    echo "\n🎓 Séance #" . $seance->klassci_seance_id . ":\n";
    echo "   - ID local: {$seance->id}\n";
    echo "   - Matière: " . ($seance->matiere_nom ?? 'N/A') . "\n";
    echo "   - ENSEIGNANT NOM: " . ($seance->enseignant_nom ?? 'NULL/VIDE') . " ⚠️\n";
    echo "   - Enseignant KLASSCI ID: " . ($seance->klassci_enseignant_id ?? 'N/A') . "\n";
    echo "   - Classe KLASSCI ID: " . ($seance->klassci_classe_id ?? 'N/A') . "\n";
    echo "   - Visio enabled: " . ($seance->visio_enabled ? 'Oui' : 'Non') . "\n";
    echo "   - Visio status: " . ($seance->visio_status ?? 'N/A') . "\n";
}

echo "\n\n";
echo "🔍 ANALYSE: RECHERCHE DU PROBLÈME\n";
echo str_repeat("-", 70) . "\n\n";

// 3. Simuler ce que fait myClassesSeances pour la séance 36
$seance36 = Seance::where('klassci_seance_id', 36)->first();

if ($seance36) {
    echo "📋 Séance #36 détails:\n";
    echo "   - enseignant_nom dans BDD: '" . ($seance36->enseignant_nom ?? 'VIDE') . "'\n\n";

    // Simuler le fallback du backend
    if (empty($seance36->enseignant_nom)) {
        echo "⚠️ enseignant_nom est VIDE, recherche fallback...\n";

        $matiereNom = $seance36->matiere_nom;
        echo "   - Matière: {$matiereNom}\n";

        $autreSeance = Seance::where('matiere_nom', $matiereNom)
            ->whereNotNull('enseignant_nom')
            ->where('enseignant_nom', '!=', '')
            ->first();

        if ($autreSeance) {
            echo "   ✅ Fallback trouvé depuis séance #{$autreSeance->klassci_seance_id}\n";
            echo "   - Nom enseignant: {$autreSeance->enseignant_nom}\n";
        } else {
            echo "   ❌ Aucun fallback trouvé!\n";
            echo "   - Résultat: affichera 'Non assigné'\n";
        }
    } else {
        echo "✅ enseignant_nom est présent: '{$seance36->enseignant_nom}'\n";
    }
}

echo "\n\n";
echo "🔍 VÉRIFICATION: D'où vient 'MARCEL OUEDRAOGO'?\n";
echo str_repeat("-", 70) . "\n\n";

// 4. Vérifier si "MARCEL OUEDRAOGO" est stocké quelque part
$seancesAvecMarcel = Seance::where('enseignant_nom', 'LIKE', '%MARCEL%')->get();

if ($seancesAvecMarcel->count() > 0) {
    echo "⚠️ TROUVÉ: Des séances ont 'MARCEL OUEDRAOGO' comme enseignant!\n\n";
    foreach ($seancesAvecMarcel as $s) {
        echo "   - Séance #{$s->klassci_seance_id} (ID local: {$s->id})\n";
        echo "     Matière: {$s->matiere_nom}\n";
        echo "     Enseignant: {$s->enseignant_nom}\n\n";
    }

    echo "🔧 PROBLÈME IDENTIFIÉ:\n";
    echo "   Le champ enseignant_nom contient le nom de l'étudiant au lieu de l'enseignant!\n";
    echo "   Cela vient probablement de la synchronisation KLASSCI.\n\n";
} else {
    echo "✅ Aucune séance avec 'MARCEL' dans enseignant_nom\n";
    echo "   Le problème doit venir d'ailleurs (frontend ou API response)\n\n";
}

// 5. Vérifier les colonnes de la table seances
echo "📋 STRUCTURE TABLE SEANCES:\n";
echo str_repeat("-", 70) . "\n";
$columns = DB::select("PRAGMA table_info(seances)");
foreach ($columns as $col) {
    echo "   - {$col->name} ({$col->type})\n";
}

echo "\n✅ Analyse terminée\n";
