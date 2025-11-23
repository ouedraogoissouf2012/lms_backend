<?php

/**
 * Test pour diagnostiquer les dates des séances Klassci
 */

require __DIR__ . '/vendor/autoload.php';

use App\Models\User;
use Illuminate\Support\Facades\Log;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "========================================\n";
echo "DIAGNOSTIC: DATES DES SÉANCES KLASSCI\n";
echo "========================================\n\n";

// Trouver l'étudiant MARCEL OUEDRAOGO
$etudiant = User::where('name', 'LIKE', '%MARCEL%')->first();

if (!$etudiant) {
    echo "❌ Étudiant MARCEL non trouvé\n";
    exit(1);
}

echo "✅ Étudiant trouvé: {$etudiant->name} (ID: {$etudiant->id})\n";
echo "   Role: {$etudiant->role}\n";
echo "   Token Klassci: " . (strlen($etudiant->klassci_token) > 0 ? "✅ Présent" : "❌ Absent") . "\n\n";

// Récupérer le dashboard étudiant
$klassciService = app(\App\Services\KlassciProxyService::class);

try {
    echo "1️⃣  RÉCUPÉRATION DU DASHBOARD ÉTUDIANT\n";
    echo "-----------------------------------------------\n";

    $dashboard = $klassciService->requestWithUserToken(
        $etudiant->klassci_token,
        'me/student-dashboard',
        'GET'
    );

    $matieres = $dashboard['data']['matieres'] ?? [];
    echo "✅ Dashboard récupéré: " . count($matieres) . " matières\n\n";

    // Chercher Marketing digital
    $marketing = null;
    foreach ($matieres as $mat) {
        if (stripos($mat['nom'] ?? '', 'Marketing') !== false) {
            $marketing = $mat;
            break;
        }
    }

    if (!$marketing) {
        echo "❌ Marketing digital non trouvé dans le dashboard\n";
        exit(1);
    }

    echo "✅ Matière trouvée: {$marketing['nom']} (ID: {$marketing['id']})\n\n";

    // Récupérer les détails de la matière
    echo "2️⃣  RÉCUPÉRATION DES DÉTAILS DE LA MATIÈRE\n";
    echo "-----------------------------------------------\n";

    $matiereDetails = $klassciService->requestWithUserToken(
        $etudiant->klassci_token,
        "matieres/{$marketing['id']}",
        'GET'
    );

    $seances = $matiereDetails['data']['seances_programmees'] ?? [];
    echo "✅ Séances trouvées: " . count($seances) . "\n\n";

    if (empty($seances)) {
        echo "⚠️  Aucune séance retournée par l'API Klassci\n";
        exit(0);
    }

    // Afficher les détails de chaque séance
    echo "3️⃣  DÉTAILS DES SÉANCES\n";
    echo "-----------------------------------------------\n";

    foreach ($seances as $index => $seance) {
        $num = $index + 1;
        echo "\n📅 SÉANCE #{$num}\n";
        echo "   ID: " . ($seance['id'] ?? 'N/A') . "\n";

        // Programmation
        if (isset($seance['programmation'])) {
            $prog = $seance['programmation'];
            echo "   📆 Date: " . ($prog['date'] ?? 'N/A') . "\n";
            echo "   ⏰ Heure début: " . ($prog['heure_debut'] ?? 'N/A') . "\n";
            echo "   ⏰ Heure fin: " . ($prog['heure_fin'] ?? 'N/A') . "\n";
            echo "   🏫 Salle: " . ($prog['salle'] ?? 'N/A') . "\n";
        } else {
            echo "   ⚠️  Pas d'objet 'programmation'\n";
        }

        // Afficher toute la structure pour debug
        echo "   \n   🔍 STRUCTURE COMPLÈTE:\n";
        echo "   " . str_replace("\n", "\n   ", json_encode($seance, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "\n";

        if ($num >= 3) {
            echo "\n   [...] (séances suivantes masquées pour lisibilité)\n";
            break;
        }
    }

    echo "\n========================================\n";
    echo "RÉSUMÉ\n";
    echo "========================================\n\n";

    echo "Total séances Klassci: " . count($seances) . "\n";

    // Vérifier combien ont des dates
    $withDates = 0;
    foreach ($seances as $s) {
        if (isset($s['programmation']['date'])) {
            $withDates++;
        }
    }

    echo "Avec dates: {$withDates}\n";
    echo "Sans dates: " . (count($seances) - $withDates) . "\n\n";

    if ($withDates === 0) {
        echo "⚠️  PROBLÈME: Aucune séance n'a de date!\n";
        echo "   → L'API Klassci ne retourne pas les dates de programmation\n";
    } elseif ($withDates < count($seances)) {
        echo "⚠️  PROBLÈME PARTIEL: Certaines séances n'ont pas de date\n";
    } else {
        echo "✅ Toutes les séances ont des dates\n";
    }

} catch (\Exception $e) {
    echo "❌ ERREUR: {$e->getMessage()}\n";
    echo "   Trace: {$e->getTraceAsString()}\n";
    exit(1);
}

echo "\n=== FIN DU DIAGNOSTIC ===\n";
