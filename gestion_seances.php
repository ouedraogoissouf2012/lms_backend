<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  GESTION DES SÉANCES                                             ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

// Liste toutes les séances
$seances = DB::table('seances')
    ->orderBy('id', 'desc')
    ->get();

if ($seances->isEmpty()) {
    echo "❌ Aucune séance trouvée\n";
    exit;
}

echo "📋 LISTE DES SÉANCES:\n\n";

foreach ($seances as $seance) {
    echo "┌─────────────────────────────────────────────────────────────────┐\n";
    echo "│ ID: #{$seance->id}\n";
    echo "│ Matière: " . ($seance->matiere_nom ?? 'N/A') . "\n";
    echo "│ KlassCI Séance ID: " . ($seance->klassci_seance_id ?? 'N/A') . "\n";

    $enseignant = 'N/A';
    if (isset($seance->enseignant_nom)) {
        $prenom = $seance->enseignant_prenom ?? '';
        $enseignant = trim($prenom . ' ' . $seance->enseignant_nom);
    }
    echo "│ Enseignant: " . $enseignant . "\n";

    if ($seance->visio_enabled) {
        echo "│ 📡 VISIO: Activée\n";
        echo "│    Status: " . ($seance->visio_status ?? 'N/A') . "\n";
        echo "│    Active: " . ($seance->visio_active ? 'OUI' : 'NON') . "\n";

        // Compter les participants
        $nbParticipants = DB::table('esbtp_attendance')
            ->where('seance_id', $seance->id)
            ->count();
        echo "│    Participants: $nbParticipants\n";
    } else {
        echo "│ 📡 VISIO: Non activée\n";
    }

    echo "│ Créée le: " . ($seance->created_at ?? 'N/A') . "\n";
    echo "└─────────────────────────────────────────────────────────────────┘\n\n";
}

echo "\n";
echo "════════════════════════════════════════════════════════════════════\n";
echo "SUPPRESSION DE SÉANCES\n";
echo "════════════════════════════════════════════════════════════════════\n\n";

echo "Entrez les IDs des séances à supprimer (séparés par des virgules).\n";
echo "Exemple: 1,2,3\n";
echo "Ou tapez 'annuler' pour quitter.\n\n";

$handle = fopen("php://stdin", "r");
$line = fgets($handle);
$input = trim($line);
fclose($handle);

if (strtolower($input) === 'annuler' || empty($input)) {
    echo "\n❌ Opération annulée\n";
    exit;
}

// Parser les IDs
$ids = array_map('trim', explode(',', $input));
$ids = array_filter($ids, 'is_numeric');

if (empty($ids)) {
    echo "\n❌ Aucun ID valide fourni\n";
    exit;
}

echo "\n⚠️  ATTENTION: Vous allez supprimer " . count($ids) . " séance(s):\n";

foreach ($ids as $id) {
    $seance = DB::table('seances')->where('id', $id)->first();
    if ($seance) {
        echo "   - Séance #$id: " . ($seance->matiere_nom ?? 'N/A') . "\n";
    } else {
        echo "   - Séance #$id: ⚠️  N'existe pas\n";
    }
}

echo "\nConfirmez-vous la suppression? (oui/non): ";

$handle = fopen("php://stdin", "r");
$line = fgets($handle);
$confirmation = trim(strtolower($line));
fclose($handle);

if ($confirmation !== 'oui') {
    echo "\n❌ Opération annulée\n";
    exit;
}

echo "\n🗑️  Suppression en cours...\n\n";

$deleted = 0;
foreach ($ids as $id) {
    try {
        // Supprimer les participants
        $nbParticipants = DB::table('esbtp_attendance')
            ->where('seance_id', $id)
            ->delete();

        // Supprimer la séance
        $result = DB::table('seances')
            ->where('id', $id)
            ->delete();

        if ($result) {
            echo "   ✅ Séance #$id supprimée";
            if ($nbParticipants > 0) {
                echo " (+ $nbParticipants participant(s))";
            }
            echo "\n";
            $deleted++;
        } else {
            echo "   ⚠️  Séance #$id non trouvée\n";
        }
    } catch (Exception $e) {
        echo "   ❌ Erreur lors de la suppression de #$id: " . $e->getMessage() . "\n";
    }
}

echo "\n✅ $deleted séance(s) supprimée(s) avec succès!\n";
