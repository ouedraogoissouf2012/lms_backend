<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Services\KlassciProxyService;

echo "====================================\n";
echo "CRÉER SÉANCE TEST AVEC HORAIRES ACTUELS\n";
echo "====================================\n\n";

$user = User::where('role', 'enseignant')->first();
if (!$user) {
    echo "❌ Pas d'enseignant\n";
    exit(1);
}

echo "👤 Enseignant: {$user->name}\n\n";

$service = app(KlassciProxyService::class);

try {
    // Récupérer les séances
    $dashboard = $service->requestWithUserToken(
        $user->klassci_token,
        'me/teacher-dashboard',
        'GET'
    );

    $matiereId = $dashboard['data']['matieres'][0]['id'] ?? null;

    if (!$matiereId) {
        echo "❌ Pas de matière\n";
        exit(1);
    }

    $matiereDetails = $service->requestWithUserToken(
        $user->klassci_token,
        "matieres/{$matiereId}",
        'GET'
    );

    $seance = $matiereDetails['data']['seances_programmees'][0] ?? null;

    if (!$seance) {
        echo "❌ Pas de séance\n";
        exit(1);
    }

    echo "🎯 Séance ID: {$seance['id']}\n";
    echo "📅 Horaires actuels: {$seance['programmation']['heure_debut']} - {$seance['programmation']['heure_fin']}\n\n";

    echo "💡 SOLUTION:\n";
    echo "Pour tester, KLASSCI doit avoir une séance programmée pour AUJOURD'HUI.\n";
    echo "\n";
    echo "OPTIONS:\n";
    echo "\n";
    echo "1️⃣  OPTION 1: Modifier la fenêtre temporelle (pour les tests)\n";
    echo "   → Retirer la contrainte de timing dans VisioManager.vue\n";
    echo "   → L'enseignant peut démarrer la visio à tout moment\n";
    echo "\n";
    echo "2️⃣  OPTION 2: Créer une séance test dans KLASSCI\n";
    echo "   → Aller sur KLASSCI et programmer une séance pour aujourd'hui\n";
    echo "   → Horaire: maintenant + 5 minutes\n";
    echo "\n";
    echo "3️⃣  OPTION 3: Désactiver temporairement la vérification\n";
    echo "   → Mettre isInTimeWindow à true pour les tests\n";
    echo "\n";

    echo "Quelle option préfères-tu ?\n";
    echo "Pour un système de production professionnel, je recommande l'OPTION 1\n";
    echo "car un enseignant devrait pouvoir démarrer son cours même si\n";
    echo "la séance est passée (rattrapage, cours de remplacement, etc.)\n";

} catch (\Exception $e) {
    echo "❌ ERREUR: {$e->getMessage()}\n";
    exit(1);
}
