<?php

$file = __DIR__ . '/app/Http/Controllers/API/LMSDataController.php';
$content = file_get_contents($file);

echo "🔧 Correction du filtrage des participations...\n\n";

// Remplacer la logique de vérification pour ne pas skip mais gérer gracieusement
$old = <<<'EOD'
            $enrichedData = $attendances->getCollection()->map(function($attendance) use ($request) {
                // Vérifier que les relations existent
                if (!$attendance->user || !$attendance->seance) {
                    return null; // Skip les participations orphelines
                }

                $data = [
                    'id' => $attendance->id,
                    'user' => [
                        'id' => $attendance->user->id,
                        'name' => $attendance->user->name,
                        'email' => $attendance->user->email,
                    ],
                    'seance' => [
                        'id' => $attendance->seance->id,
                        'klassci_seance_id' => $attendance->seance->klassci_seance_id,
                        'date' => $attendance->seance->date_seance,
                        'matiere' => null,
                        'classe' => null
                    ],
EOD;

$new = <<<'EOD'
            $enrichedData = $attendances->getCollection()->map(function($attendance) use ($request) {
                $data = [
                    'id' => $attendance->id,
                    'user' => [
                        'id' => $attendance->user?->id ?? 0,
                        'name' => $attendance->user?->name ?? 'Utilisateur supprimé',
                        'email' => $attendance->user?->email ?? '',
                    ],
                    'seance' => [
                        'id' => $attendance->seance?->id ?? 0,
                        'klassci_seance_id' => $attendance->seance?->klassci_seance_id ?? 'N/A',
                        'date' => $attendance->seance?->date_seance ?? null,
                        'matiere' => null,
                        'classe' => null
                    ],
EOD;

$content = str_replace($old, $new, $content);

// Retirer filter()->values() puisqu'on ne renvoie plus null
$old2 = <<<'EOD'
                return $data;
            })->filter()->values(); // Retirer les null et ré-indexer
EOD;

$new2 = <<<'EOD'
                return $data;
            });
EOD;

$content = str_replace($old2, $new2, $content);

//  Adapter la vérification klassci_seance_id
$old3 = <<<'EOD'
                // Essayer de récupérer les infos KLASSCI de la séance
                if ($attendance->seance->klassci_seance_id) {
EOD;

$new3 = <<<'EOD'
                // Essayer de récupérer les infos KLASSCI de la séance
                if ($attendance->seance && $attendance->seance->klassci_seance_id) {
EOD;

$content = str_replace($old3, $new3, $content);

file_put_contents($file, $content);

echo "✅ Corrections appliquées :\n";
echo "   - Utilisation de l'opérateur null-safe (?->) pour éviter les erreurs\n";
echo "   - Valeurs par défaut pour user/seance manquants\n";
echo "   - Retrait de filter() qui supprimait toutes les données\n";
echo "\n✅ L'API devrait maintenant renvoyer les données\n";
