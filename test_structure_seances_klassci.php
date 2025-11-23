<?php

/**
 * Script pour afficher la structure complète des séances Klassci
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "═══════════════════════════════════════════════════════════\n";
echo "   STRUCTURE DES SÉANCES KLASSCI\n";
echo "═══════════════════════════════════════════════════════════\n\n";

// Essayer plusieurs utilisateurs pour trouver celui qui a des séances
$users = App\Models\User::whereNotNull('klassci_token')
    ->whereIn('role', ['enseignant', 'etudiant', 'coordinateur'])
    ->get();

echo "🔍 Utilisateurs disponibles: {$users->count()}\n\n";

$klassciService = app(\App\Services\KlassciProxyService::class);
$seanceTrouvee = false;

foreach ($users as $user) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "👤 Utilisateur: {$user->name} ({$user->role})\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    try {
        // Essayer différents endpoints selon le rôle
        $endpoints = [];

        if ($user->role === 'enseignant') {
            $endpoints[] = 'me/teacher-dashboard';
        } elseif ($user->role === 'etudiant') {
            $endpoints[] = 'me/dashboard';
        } elseif ($user->role === 'coordinateur') {
            $endpoints[] = 'matieres';
        }

        foreach ($endpoints as $endpoint) {
            try {
                echo "📡 Appel: {$endpoint}\n";

                $response = $klassciService->requestWithUserToken(
                    $user->klassci_token,
                    $endpoint,
                    'GET'
                );

                // Chercher les matières
                $matieres = [];
                if (isset($response['data']['matieres'])) {
                    $matieres = $response['data']['matieres'];
                } elseif (isset($response['data']) && is_array($response['data'])) {
                    $matieres = $response['data'];
                }

                echo "📚 Matières trouvées: " . count($matieres) . "\n";

                // Pour chaque matière, chercher des séances
                foreach ($matieres as $matiere) {
                    $matiereId = $matiere['id'] ?? null;
                    $matiereNom = $matiere['nom'] ?? 'N/A';

                    // Vérifier si la matière a déjà des séances
                    $seancesDirect = $matiere['seances_programmees'] ?? [];

                    if (!empty($seancesDirect)) {
                        echo "\n✅ SÉANCES TROUVÉES dans matière: {$matiereNom}\n";
                        echo "   Nombre: " . count($seancesDirect) . "\n\n";

                        echo "╔════════════════════════════════════════════════════════╗\n";
                        echo "║  STRUCTURE COMPLÈTE D'UNE SÉANCE KLASSCI              ║\n";
                        echo "╚════════════════════════════════════════════════════════╝\n\n";

                        // Afficher la première séance en détail
                        $premiereSeance = $seancesDirect[0];
                        echo json_encode($premiereSeance, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        echo "\n\n";

                        echo "╔════════════════════════════════════════════════════════╗\n";
                        echo "║  CHAMPS IMPORTANTS EXTRAITS                           ║\n";
                        echo "╚════════════════════════════════════════════════════════╝\n\n";

                        echo "🆔 ID séance: " . ($premiereSeance['id'] ?? 'N/A') . "\n";

                        if (isset($premiereSeance['programmation'])) {
                            $prog = $premiereSeance['programmation'];
                            echo "\n📅 PROGRAMMATION:\n";
                            echo "   • Date: " . ($prog['date'] ?? 'N/A') . "\n";
                            echo "   • Heure début: " . ($prog['heure_debut'] ?? 'N/A') . "\n";
                            echo "   • Heure fin: " . ($prog['heure_fin'] ?? 'N/A') . "\n";
                            echo "   • Salle: " . ($prog['salle'] ?? 'N/A') . "\n";
                        } else {
                            echo "\n⚠️  Pas de champ 'programmation'\n";
                        }

                        if (isset($premiereSeance['enseignant'])) {
                            $ens = $premiereSeance['enseignant'];
                            echo "\n👨‍🏫 ENSEIGNANT:\n";
                            echo "   • ID: " . ($ens['id'] ?? 'N/A') . "\n";
                            echo "   • Nom: " . ($ens['nom'] ?? 'N/A') . "\n";
                            echo "   • Prénom: " . ($ens['prenom'] ?? 'N/A') . "\n";
                        }

                        if (isset($premiereSeance['classe'])) {
                            $classe = $premiereSeance['classe'];
                            echo "\n🏫 CLASSE:\n";
                            echo "   • ID: " . ($classe['id'] ?? 'N/A') . "\n";
                            echo "   • Nom: " . ($classe['nom'] ?? 'N/A') . "\n";
                        }

                        if (isset($premiereSeance['matiere'])) {
                            $mat = $premiereSeance['matiere'];
                            echo "\n📖 MATIÈRE:\n";
                            echo "   • ID: " . ($mat['id'] ?? 'N/A') . "\n";
                            echo "   • Nom: " . ($mat['nom'] ?? 'N/A') . "\n";
                        }

                        echo "\n📊 Statut: " . ($premiereSeance['statut'] ?? 'N/A') . "\n";
                        echo "⏱️  Durée (minutes): " . ($premiereSeance['duree_minutes'] ?? 'N/A') . "\n";

                        $seanceTrouvee = true;
                        break 3; // Sortir de toutes les boucles
                    }

                    // Si pas de séances dans la matière, essayer de récupérer via matieres/{id}
                    if ($matiereId) {
                        try {
                            $matiereDetails = $klassciService->requestWithUserToken(
                                $user->klassci_token,
                                "matieres/{$matiereId}",
                                'GET'
                            );

                            $seancesDetails = $matiereDetails['data']['seances_programmees'] ?? [];

                            if (!empty($seancesDetails)) {
                                echo "\n✅ SÉANCES TROUVÉES via matieres/{$matiereId}: {$matiereNom}\n";
                                echo "   Nombre: " . count($seancesDetails) . "\n\n";

                                echo "╔════════════════════════════════════════════════════════╗\n";
                                echo "║  STRUCTURE COMPLÈTE D'UNE SÉANCE KLASSCI              ║\n";
                                echo "╚════════════════════════════════════════════════════════╝\n\n";

                                echo json_encode($seancesDetails[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                echo "\n\n";

                                $seanceTrouvee = true;
                                break 3;
                            }
                        } catch (\Exception $e) {
                            // Continuer
                        }
                    }
                }

            } catch (\Exception $e) {
                echo "⚠️  Erreur sur {$endpoint}: " . substr($e->getMessage(), 0, 100) . "\n";
            }
        }

        if ($seanceTrouvee) {
            break;
        }

    } catch (\Exception $e) {
        echo "❌ Erreur: {$e->getMessage()}\n\n";
    }
}

if (!$seanceTrouvee) {
    echo "\n❌ Aucune séance trouvée dans Klassci pour aucun utilisateur!\n";
    echo "   Cela peut signifier que:\n";
    echo "   1. Aucune séance n'est programmée dans Klassci\n";
    echo "   2. Les tokens sont expirés\n";
    echo "   3. L'API Klassci a changé\n";
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "   FIN DU DIAGNOSTIC\n";
echo "═══════════════════════════════════════════════════════════\n";
