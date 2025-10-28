<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Services\KlassciProxyService;

echo "====================================\n";
echo "DEBUG TIMING VISIO\n";
echo "====================================\n\n";

// Récupérer l'enseignant
$user = User::where('role', 'enseignant')->first();
if (!$user) {
    echo "❌ Pas d'enseignant\n";
    exit(1);
}

echo "👤 Enseignant: {$user->name}\n\n";

// Récupérer les détails de la matière
$service = app(KlassciProxyService::class);

try {
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

    echo "📚 Matière ID: {$matiereId}\n\n";

    // Récupérer les détails de la matière
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
    echo "📅 Structure programmation:\n";

    if (isset($seance['programmation'])) {
        echo "  ✅ programmation existe\n";
        echo "  - date: " . ($seance['programmation']['date'] ?? 'NULL') . "\n";
        echo "  - heure_debut: " . ($seance['programmation']['heure_debut'] ?? 'NULL') . "\n";
        echo "  - heure_fin: " . ($seance['programmation']['heure_fin'] ?? 'NULL') . "\n";

        if (isset($seance['programmation']['heure_debut'])) {
            $debut = new DateTime($seance['programmation']['heure_debut']);
            $fin = new DateTime($seance['programmation']['heure_fin']);
            $now = new DateTime();

            // Fenêtre: -15min avant début, +30min après fin
            $windowStart = clone $debut;
            $windowStart->modify('-15 minutes');

            $windowEnd = clone $fin;
            $windowEnd->modify('+30 minutes');

            echo "\n⏰ TIMING:\n";
            echo "  Heure actuelle: " . $now->format('Y-m-d H:i:s') . "\n";
            echo "  Début séance:   " . $debut->format('Y-m-d H:i:s') . "\n";
            echo "  Fin séance:     " . $fin->format('Y-m-d H:i:s') . "\n";
            echo "  \n";
            echo "  Fenêtre début:  " . $windowStart->format('Y-m-d H:i:s') . " (-15min)\n";
            echo "  Fenêtre fin:    " . $windowEnd->format('Y-m-d H:i:s') . " (+30min)\n";
            echo "  \n";

            $isInWindow = ($now >= $windowStart && $now <= $windowEnd);

            if ($isInWindow) {
                echo "  ✅ DANS LA FENÊTRE TEMPORELLE\n";
                echo "  → Le bouton devrait être ACTIF (bleu)\n";
            } else {
                echo "  ❌ HORS FENÊTRE TEMPORELLE\n";
                echo "  → Le bouton devrait être GRISÉ\n";

                if ($now < $windowStart) {
                    $diff = $windowStart->getTimestamp() - $now->getTimestamp();
                    $minutes = floor($diff / 60);
                    $hours = floor($minutes / 60);
                    $mins = $minutes % 60;
                    echo "  → Disponible dans: {$hours}h{$mins}m\n";
                } else {
                    echo "  → Fenêtre expirée\n";
                }
            }
        }
    } else {
        echo "  ❌ programmation MANQUANT\n";
        echo "\n📋 Structure séance reçue:\n";
        echo json_encode($seance, JSON_PRETTY_PRINT) . "\n";
    }

    // Vérifier les données visio
    $visioDb = \App\Models\Seance::where('klassci_seance_id', $seance['id'])->first();

    echo "\n💾 DONNÉES VISIO (BDD):\n";
    if ($visioDb) {
        echo "  - visio_enabled: " . ($visioDb->visio_enabled ? 'OUI' : 'NON') . "\n";
        echo "  - visio_status: " . ($visioDb->visio_status ?? 'NULL') . "\n";
        echo "  - visio_room_id: " . ($visioDb->visio_room_id ?? 'NULL') . "\n";
    } else {
        echo "  ❌ Pas d'entrée dans la BDD\n";
    }

} catch (\Exception $e) {
    echo "❌ ERREUR: {$e->getMessage()}\n";
    exit(1);
}
