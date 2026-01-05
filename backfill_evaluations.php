<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Evaluation;
use App\Models\User;
use App\Services\KlassciProxyService;

echo "🔄 Remplissage des données manquantes pour les évaluations...\n\n";

$klassciService = app(KlassciProxyService::class);

// Récupérer toutes les évaluations qui n'ont pas les noms locaux
$evaluations = Evaluation::whereNull('matiere_nom')
    ->orWhereNull('classe_nom')
    ->orWhereNull('enseignant_nom')
    ->get();

echo "📊 Trouvé {$evaluations->count()} évaluation(s) avec données manquantes\n\n";

if ($evaluations->count() === 0) {
    echo "✅ Aucune évaluation à mettre à jour !\n";
    exit(0);
}

$updated = 0;
$failed = 0;

// Récupérer le token d'un utilisateur admin ou coordinateur
$admin = User::where('role', 'coordinateur')
    ->orWhere('role', 'superAdmin')
    ->whereNotNull('klassci_token')
    ->first();

if (!$admin || !$admin->klassci_token) {
    echo "❌ Aucun utilisateur admin avec token trouvé\n";
    exit(1);
}

echo "👤 Utilisation du token de {$admin->name}\n\n";
$userToken = $admin->klassci_token;

foreach ($evaluations as $evaluation) {
    echo "Traitement évaluation ID: {$evaluation->id}";
    if ($evaluation->titre) {
        echo " - {$evaluation->titre}";
    }
    echo "\n";

    try {
        $updates = [];

        // Récupérer matière
        if (!$evaluation->matiere_nom && $evaluation->klassci_matiere_id) {
            try {
                $matieres = $klassciService->requestWithUserToken($userToken, 'matieres', 'GET');
                if (isset($matieres['data'])) {
                    foreach ($matieres['data'] as $matiere) {
                        if ($matiere['id'] == $evaluation->klassci_matiere_id) {
                            $updates['matiere_nom'] = $matiere['nom'] ?? $matiere['libelle'] ?? null;
                            echo "  ✅ Matière: {$updates['matiere_nom']}\n";
                            break;
                        }
                    }
                }
            } catch (\Exception $e) {
                echo "  ⚠️  Erreur récupération matière: {$e->getMessage()}\n";
            }
        }

        // Récupérer classe
        if (!$evaluation->classe_nom && $evaluation->klassci_classe_id) {
            try {
                $classes = $klassciService->requestWithUserToken($userToken, 'classes', 'GET');
                if (isset($classes['data'])) {
                    foreach ($classes['data'] as $classe) {
                        if ($classe['id'] == $evaluation->klassci_classe_id) {
                            $updates['classe_nom'] = $classe['libelle'] ?? $classe['nom'] ?? $classe['name'] ?? null;
                            if (!$updates['classe_nom'] && isset($classe['niveau']['code'])) {
                                $updates['classe_nom'] = $classe['niveau']['code'];
                            } elseif (!$updates['classe_nom'] && isset($classe['niveau']['libelle'])) {
                                $updates['classe_nom'] = $classe['niveau']['libelle'];
                            }
                            echo "  ✅ Classe: {$updates['classe_nom']}\n";
                            break;
                        }
                    }
                }
            } catch (\Exception $e) {
                echo "  ⚠️  Erreur récupération classe: {$e->getMessage()}\n";
            }
        }

        // Récupérer enseignant
        if (!$evaluation->enseignant_nom && $evaluation->klassci_enseignant_id) {
            try {
                $enseignants = $klassciService->requestWithUserToken($userToken, 'enseignants', 'GET');
                if (isset($enseignants['data'])) {
                    foreach ($enseignants['data'] as $enseignant) {
                        if ($enseignant['id'] == $evaluation->klassci_enseignant_id) {
                            $updates['enseignant_nom'] = $enseignant['nom'] ?? null;
                            if ($updates['enseignant_nom'] && isset($enseignant['prenom'])) {
                                $updates['enseignant_nom'] = $enseignant['prenom'] . ' ' . $updates['enseignant_nom'];
                            }
                            echo "  ✅ Enseignant: {$updates['enseignant_nom']}\n";
                            break;
                        }
                    }
                }

                // Fallback: chercher dans la table users locale
                if (!isset($updates['enseignant_nom'])) {
                    $enseignant = User::where('klassci_id', $evaluation->klassci_enseignant_id)->first();
                    if ($enseignant) {
                        $updates['enseignant_nom'] = $enseignant->name;
                        echo "  ✅ Enseignant (depuis users): {$updates['enseignant_nom']}\n";
                    }
                }
            } catch (\Exception $e) {
                echo "  ⚠️  Erreur récupération enseignant: {$e->getMessage()}\n";
            }
        }

        // Appliquer les mises à jour
        if (!empty($updates)) {
            $evaluation->update($updates);
            $updated++;
            echo "  ✅ Évaluation mise à jour\n";
        } else {
            echo "  ℹ️  Aucune mise à jour nécessaire\n";
        }

    } catch (\Exception $e) {
        echo "  ❌ Erreur: {$e->getMessage()}\n";
        $failed++;
    }

    echo "\n";
}

echo "✅ Terminé !\n";
echo "📊 Résumé: {$updated} mise(s) à jour, {$failed} échec(s)\n";
