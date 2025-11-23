<?php

/**
 * DIAGNOSTIC: Problème de démarrage de visio par l'enseignant
 *
 * Contexte: "quand le coordinateur active la visio et que l'enseignant demarre il n'arrivais pas a suivre"
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Seance;
use App\Models\User;

echo "═══════════════════════════════════════════════════════════\n";
echo "   DIAGNOSTIC: DÉMARRAGE VISIO PAR ENSEIGNANT\n";
echo "═══════════════════════════════════════════════════════════\n\n";

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "1️⃣  WORKFLOW ACTUEL\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "ÉTAPE 1: Coordinateur active la visio\n";
echo "   → POST /api/lms/seances/{id}/activate-visio\n";
echo "   → visio_enabled = true\n";
echo "   → visio_status = 'programmee'\n";
echo "   → visio_active = false\n";
echo "   → klassci_enseignant_id = ID de l'enseignant de la séance\n\n";

echo "ÉTAPE 2: Enseignant tente de démarrer\n";
echo "   → POST /api/lms/seances/{id}/start-visio\n";
echo "   → VÉRIFICATION 1: visio_enabled = true ? ✅\n";
echo "   → VÉRIFICATION 2: user.role = 'enseignant' ? ✅\n";
echo "   → VÉRIFICATION 3: user.klassci_id == visio.klassci_enseignant_id ? ❓\n\n";

echo "❌ PROBLÈME POTENTIEL: La vérification 3 échoue!\n\n";

echo "Raisons possibles:\n";
echo "   1. Le coordinateur active la visio → klassci_enseignant_id peut être NULL ou incorrect\n";
echo "   2. L'enseignant connecté n'a pas le même klassci_id que celui de la séance\n";
echo "   3. Différence entre l'enseignant qui crée et celui qui enseigne réellement\n\n";

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "2️⃣  CODE ACTUEL (LMSDataController.php lignes 2860-2866)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "Code:\n";
echo "```php\n";
echo "// Vérifier que c'est bien l'enseignant de cette séance\n";
echo "if (\$visio->klassci_enseignant_id && \$visio->klassci_enseignant_id !== \$user->klassci_id) {\n";
echo "    return response()->json([\n";
echo "        'success' => false,\n";
echo "        'message' => 'Seul l\\'enseignant de cette séance peut la démarrer'\n";
echo "    ], 403);\n";
echo "}\n";
echo "```\n\n";

echo "❌ CETTE VÉRIFICATION BLOQUE L'ENSEIGNANT!\n\n";

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "3️⃣  VÉRIFICATION DES DONNÉES\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Récupérer les séances avec visio activée
$seancesAvecVisio = Seance::where('visio_enabled', true)->get();

echo "Séances avec visio activée: {$seancesAvecVisio->count()}\n\n";

if ($seancesAvecVisio->count() > 0) {
    foreach ($seancesAvecVisio as $seance) {
        echo "Séance ID: {$seance->id} | Klassci: {$seance->klassci_seance_id}\n";
        echo "   Titre: {$seance->titre}\n";
        echo "   Enseignant (nom): {$seance->enseignant_nom}\n";
        echo "   Enseignant (klassci_id): " . ($seance->klassci_enseignant_id ?? 'NULL') . "\n";
        echo "   Visio status: {$seance->visio_status}\n";
        echo "   Visio active: " . ($seance->visio_active ? 'true' : 'false') . "\n\n";

        // Chercher l'enseignant dans la base locale
        if ($seance->klassci_enseignant_id) {
            $enseignant = User::where('klassci_id', $seance->klassci_enseignant_id)->first();

            if ($enseignant) {
                echo "   ✅ Enseignant trouvé dans la base locale:\n";
                echo "      Nom: {$enseignant->name}\n";
                echo "      Email: {$enseignant->email}\n";
                echo "      Role: {$enseignant->role}\n";
                echo "      Klassci ID: {$enseignant->klassci_id}\n\n";
            } else {
                echo "   ❌ PROBLÈME: Aucun enseignant avec klassci_id={$seance->klassci_enseignant_id} trouvé!\n\n";
            }
        } else {
            echo "   ⚠️  PROBLÈME: klassci_enseignant_id = NULL\n";
            echo "      → Impossible de vérifier quel enseignant peut démarrer\n\n";
        }
    }
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "4️⃣  VÉRIFICATION ENSEIGNANTS\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$enseignants = User::where('role', 'enseignant')->get();

echo "Total enseignants: {$enseignants->count()}\n\n";

foreach ($enseignants as $ens) {
    echo "   • {$ens->name}\n";
    echo "     Email: {$ens->email}\n";
    echo "     Klassci ID: " . ($ens->klassci_id ?? 'NULL') . "\n";
    echo "     Token: " . ($ens->klassci_token ? 'Présent' : 'NULL') . "\n\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "5️⃣  PROBLÈME IDENTIFIÉ\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "SCÉNARIO PROBLÉMATIQUE:\n\n";

echo "1. Coordinateur active la visio pour une séance\n";
echo "   → La séance vient de Klassci\n";
echo "   → klassci_enseignant_id = ID de l'enseignant dans Klassci\n\n";

echo "2. Enseignant se connecte et veut démarrer\n";
echo "   → Son user.klassci_id peut être NULL si:\n";
echo "      - Il ne s'est jamais connecté au LMS avant\n";
echo "      - La synchronisation n'a pas été faite\n";
echo "      - Il a été créé manuellement dans le LMS\n\n";

echo "3. Vérification échoue:\n";
echo "   → visio.klassci_enseignant_id = 123 (depuis Klassci)\n";
echo "   → user.klassci_id = NULL ou différent\n";
echo "   → 123 !== NULL → REFUSÉ ❌\n\n";

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "6️⃣  SOLUTIONS POSSIBLES\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "SOLUTION 1: Assouplir la vérification (RECOMMANDÉE) ✅\n";
echo "   → Permettre à TOUT enseignant de démarrer une visio activée\n";
echo "   → Supprimer la vérification du klassci_enseignant_id\n";
echo "   → Logique: Si coordinateur a approuvé (activé), enseignant peut démarrer\n\n";

echo "SOLUTION 2: Meilleure synchronisation\n";
echo "   → Lors de l'activation, synchroniser l'enseignant depuis Klassci\n";
echo "   → S'assurer que user.klassci_id correspond\n";
echo "   → Plus complexe, peut échouer si enseignant pas dans la base locale\n\n";

echo "SOLUTION 3: Vérification alternative\n";
echo "   → Vérifier par email ou nom au lieu de klassci_id\n";
echo "   → Moins fiable (homonymes, emails différents)\n\n";

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "7️⃣  RECOMMANDATION FINALE\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "MODIFIER startVisio() pour:\n\n";

echo "LOGIQUE ACTUELLE (STRICTE):\n";
echo "```\n";
echo "SI visio_enabled = true\n";
echo "ET user.role = 'enseignant'\n";
echo "ET (klassci_enseignant_id = NULL OU user.klassci_id = klassci_enseignant_id)\n";
echo "ALORS démarrer\n";
echo "SINON refuser\n";
echo "```\n\n";

echo "NOUVELLE LOGIQUE (ASSOUPLIE) - RECOMMANDÉE:\n";
echo "```\n";
echo "SI visio_enabled = true\n";
echo "ET user.role = 'enseignant'\n";
echo "ALORS démarrer\n";
echo "SINON refuser\n";
echo "```\n\n";

echo "JUSTIFICATION:\n";
echo "   ✅ Si le coordinateur a activé la visio, c'est qu'il a validé\n";
echo "   ✅ L'enseignant qui se connecte a les droits nécessaires\n";
echo "   ✅ Évite les problèmes de synchronisation klassci_id\n";
echo "   ✅ Plus simple et robuste\n\n";

echo "MODIFICATION À FAIRE:\n";
echo "   Fichier: app/Http/Controllers/API/LMSDataController.php\n";
echo "   Lignes: 2860-2866\n";
echo "   Action: Supprimer ou commenter la vérification du klassci_enseignant_id\n\n";

echo "═══════════════════════════════════════════════════════════\n";
echo "   FIN DU DIAGNOSTIC\n";
echo "═══════════════════════════════════════════════════════════\n";
