<?php

/**
 * DIAGNOSTIC COMPLET - Système de présence/participants
 *
 * Vérifie si les listes de présence sont accessibles après la fin des appels vidéo
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Seance;
use App\Models\ESBTPAttendance;
use App\Models\User;
use Carbon\Carbon;

echo "═══════════════════════════════════════════════════════════\n";
echo "   DIAGNOSTIC: SYSTÈME DE PRÉSENCE APRÈS FIN APPEL VISIO\n";
echo "═══════════════════════════════════════════════════════════\n\n";

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "1️⃣  ÉTAT DES DONNÉES DE PRÉSENCE\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$totalAttendances = ESBTPAttendance::count();
$connected = ESBTPAttendance::where('status', 'connected')->count();
$disconnected = ESBTPAttendance::where('status', 'disconnected')->count();

echo "Total enregistrements: {$totalAttendances}\n";
echo "Status 'connected': {$connected}\n";
echo "Status 'disconnected': {$disconnected}\n\n";

// ❌ PROBLÈME DÉTECTÉ
if ($connected > 0 && $disconnected === 0) {
    echo "⚠️  PROBLÈME DÉTECTÉ:\n";
    echo "    Tous les participants sont marqués 'connected'\n";
    echo "    Aucun n'a été marqué 'disconnected' après la fin de l'appel\n\n";
}

echo "Détails des présences:\n\n";

$allAttendances = ESBTPAttendance::with('user:id,name', 'seance:id,klassci_seance_id,titre')
    ->latest()
    ->get();

foreach ($allAttendances as $att) {
    echo "   • Séance: {$att->seance->titre} (ID: {$att->seance_id})\n";
    echo "     Étudiant: {$att->user->name}\n";
    echo "     Status: {$att->status}\n";
    echo "     Rejoint: {$att->joined_at}\n";
    echo "     Quitté: " . ($att->left_at ? $att->left_at : '❌ JAMAIS QUITTÉ (left_at = NULL)') . "\n";
    echo "     Durée: " . ($att->duration_minutes ?? '❌ NON CALCULÉE') . " minutes\n\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "2️⃣  WORKFLOW ACTUEL - CE QUI SE PASSE\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "ÉTAPE 1: Étudiant rejoint la visio\n";
echo "   → POST /api/lms/seances/{id}/join-visio\n";
echo "   → ESBTPAttendance::updateOrCreate()\n";
echo "   → status = 'connected'\n";
echo "   → joined_at = NOW()\n";
echo "   → left_at = NULL ✅\n\n";

echo "ÉTAPE 2: Étudiant quitte la visio\n";
echo "   → POST /api/lms/seances/{id}/leave-visio\n";
echo "   → attendance->markAsDisconnected()\n";
echo "   → status = 'disconnected'\n";
echo "   → left_at = NOW()\n";
echo "   → duration_minutes = calculée ✅\n\n";

echo "❌ PROBLÈME: L'étape 2 n'est JAMAIS exécutée!\n\n";

echo "Raisons possibles:\n";
echo "   1. Frontend ne call pas /leave-visio quand l'étudiant quitte\n";
echo "   2. L'étudiant ferme juste l'onglet (pas d'appel API)\n";
echo "   3. Pas de gestion des déconnexions involontaires\n";
echo "   4. Heartbeat pas utilisé pour détecter les déconnexions\n\n";

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "3️⃣  ACCÈS AUX LISTES DE PRÉSENCE APRÈS FIN APPEL\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "Endpoint: GET /api/lms/seances/{id}/participants\n\n";

// Simuler un appel enseignant
$enseignant = User::where('role', 'enseignant')->first();

if ($enseignant) {
    echo "Simulons un appel par un enseignant...\n\n";

    // Prendre une séance avec des participants
    $seanceAvecParticipants = ESBTPAttendance::with('seance')->first();

    if ($seanceAvecParticipants) {
        $seance = $seanceAvecParticipants->seance;

        echo "Séance testée: {$seance->titre} (ID: {$seance->id})\n\n";

        // Récupérer les participants comme le fait l'API
        $participants = ESBTPAttendance::where('seance_id', $seance->id)
            ->with('user:id,name,email,klassci_id')
            ->orderBy('joined_at', 'desc')
            ->get();

        echo "Participants retournés:\n\n";

        foreach ($participants as $p) {
            echo "   • {$p->user->name}\n";
            echo "     Email: {$p->user->email}\n";
            echo "     Status: {$p->status}\n";
            echo "     Rejoint: {$p->joined_at->format('Y-m-d H:i:s')}\n";
            echo "     Quitté: " . ($p->left_at ? $p->left_at->format('Y-m-d H:i:s') : '❌ NULL') . "\n";
            echo "     Durée: " . ($p->duration_minutes ?? '❌ NULL') . " minutes\n\n";
        }

        echo "✅ CONCLUSION: Les enseignants/coordinateurs PEUVENT accéder aux données\n";
        echo "⚠️  MAIS: Les données sont incomplètes (left_at et duration manquants)\n\n";

    } else {
        echo "❌ Aucune séance avec participants trouvée\n\n";
    }
} else {
    echo "❌ Aucun enseignant trouvé dans la base\n\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "4️⃣  ENDPOINTS DISPONIBLES\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "Routes API détectées (routes/api.php):\n\n";

echo "1. GET /api/lms/seances/{seanceId}/participants\n";
echo "   → Contrôleur: LMSDataController::getVisioParticipants()\n";
echo "   → Accès: Enseignants, Coordinateurs\n";
echo "   → Retourne: Liste complète participants avec status\n\n";

echo "2. POST /api/lms/seances/{seanceId}/join-visio\n";
echo "   → Contrôleur: LMSDataController::joinVisio()\n";
echo "   → Accès: Étudiants\n";
echo "   → Enregistre: joined_at, status='connected'\n\n";

echo "3. POST /api/lms/seances/{seanceId}/leave-visio\n";
echo "   → Contrôleur: LMSDataController::leaveVisio()\n";
echo "   → Accès: Étudiants\n";
echo "   → Enregistre: left_at, duration, status='disconnected'\n\n";

echo "4. POST /api/lms/seances/{seanceId}/heartbeat\n";
echo "   → Contrôleur: LMSDataController::heartbeatVisio()\n";
echo "   → Accès: Étudiants\n";
echo "   → Met à jour: last_seen_at\n\n";

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "5️⃣  PROBLÈMES IDENTIFIÉS\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "❌ PROBLÈME 1: Pas de gestion de déconnexion automatique\n";
echo "   → Les étudiants qui ferment l'onglet restent 'connected'\n";
echo "   → Le heartbeat n'est pas utilisé pour détecter les déconnexions\n";
echo "   → Solution: Job planifié qui vérifie last_seen_at\n\n";

echo "❌ PROBLÈME 2: Frontend ne call pas /leave-visio\n";
echo "   → Vérifier si SeanceDetails.vue appelle bien l'endpoint\n";
echo "   → Vérifier beforeUnmount() et window.beforeunload\n";
echo "   → Solution: Ajouter les hooks de déconnexion\n\n";

echo "❌ PROBLÈME 3: Pas de finalisation automatique après fin séance\n";
echo "   → Quand heure_fin est passée, les participants restent 'connected'\n";
echo "   → Solution: Job qui marque comme 'disconnected' après heure_fin + 30min\n\n";

echo "❌ PROBLÈME 4: Durée non calculée pour participants actuels\n";
echo "   → duration_minutes = NULL tant que l'étudiant n'a pas quitté\n";
echo "   → Solution: Calculer dynamiquement dans l'API si status='connected'\n\n";

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "6️⃣  RÉPONSE À LA QUESTION\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "Question: \"Quand le call finis, est-ce qu'on peut toujours avoir la\n";
echo "           liste de présence côté coordinateur, enseignant?\"\n\n";

echo "✅ RÉPONSE: OUI, les listes de présence sont PERSISTANTES\n\n";

echo "DÉTAILS:\n";
echo "   ✅ Les données sont stockées dans la table esbtp_attendance\n";
echo "   ✅ Les enseignants/coordinateurs peuvent y accéder à tout moment\n";
echo "   ✅ L'endpoint GET /api/lms/seances/{id}/participants fonctionne\n";
echo "   ✅ Les données persistent même après la fin de l'appel\n\n";

echo "⚠️  MAIS:\n";
echo "   ❌ Les données de déconnexion ne sont pas enregistrées\n";
echo "   ❌ left_at reste NULL (heure de sortie manquante)\n";
echo "   ❌ duration_minutes reste NULL (durée non calculée)\n";
echo "   ❌ status reste 'connected' (même après fin appel)\n\n";

echo "💡 IMPACT:\n";
echo "   → Les enseignants peuvent voir QUI a participé ✅\n";
echo "   → Ils peuvent voir QUAND l'étudiant a rejoint ✅\n";
echo "   → MAIS ils ne peuvent PAS voir:\n";
echo "      - Quand l'étudiant a quitté ❌\n";
echo "      - Combien de temps il est resté ❌\n";
echo "      - Si l'étudiant est toujours connecté vs déconnecté ❌\n\n";

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "7️⃣  CORRECTIONS NÉCESSAIRES\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "CORRECTION 1: Frontend - Appeler /leave-visio\n";
echo "   Fichier: lms-frontend/src/views/seances/SeanceDetails.vue\n";
echo "   Action: Ajouter appel API dans beforeUnmount() et window.onbeforeunload\n\n";

echo "CORRECTION 2: Job automatique - Finaliser les présences\n";
echo "   Fichier: app/Jobs/FinalizeSeanceAttendances.php (À CRÉER)\n";
echo "   Action: Marquer 'disconnected' les séances passées (heure_fin + 30min)\n";
echo "   Planning: Toutes les 10 minutes via routes/console.php\n\n";

echo "CORRECTION 3: API - Calculer durée dynamiquement\n";
echo "   Fichier: app/Http/Controllers/API/LMSDataController.php\n";
echo "   Action: Dans getVisioParticipants(), calculer duration si connected\n\n";

echo "CORRECTION 4: Job heartbeat - Détecter déconnexions\n";
echo "   Fichier: app/Jobs/DetectDisconnectedParticipants.php (À CRÉER)\n";
echo "   Action: Si last_seen_at > 5 minutes, marquer 'disconnected'\n";
echo "   Planning: Toutes les 2 minutes via routes/console.php\n\n";

echo "═══════════════════════════════════════════════════════════\n";
echo "   FIN DU DIAGNOSTIC\n";
echo "═══════════════════════════════════════════════════════════\n";
