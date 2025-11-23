<?php

$file = __DIR__ . '/app/Http/Controllers/API/LMSDataController.php';
$content = file_get_contents($file);

// Pattern à chercher
$oldCode = <<<'EOD'
            // Enrichir les données avec les infos KLASSCI si disponibles
            $enrichedData = $attendances->getCollection()->map(function($attendance) use ($request) {
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
                    ],
EOD;

$newCode = <<<'EOD'
            // Enrichir les données avec les infos KLASSCI si disponibles
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

$content = str_replace($oldCode, $newCode, $content);

// Pattern 2: Ajouter filter() à la fin du map
$oldEnd = <<<'EOD'
                return $data;
            });

            return response()->json([
EOD;

$newEnd = <<<'EOD'
                return $data;
            })->filter()->values(); // Retirer les null et ré-indexer

            return response()->json([
EOD;

$content = str_replace($oldEnd, $newEnd, $content);

// Pattern 3: Ajouter vérification pour klassci_seance_id
$oldTry = <<<'EOD'
                // Essayer de récupérer les infos KLASSCI de la séance
                try {
                    $seanceDetails = $this->seanceDetails($attendance->seance->klassci_seance_id, $request);
EOD;

$newTry = <<<'EOD'
                // Essayer de récupérer les infos KLASSCI de la séance
                if ($attendance->seance->klassci_seance_id) {
                    try {
                        $seanceDetails = $this->seanceDetails($attendance->seance->klassci_seance_id, $request);
EOD;

$content = str_replace($oldTry, $newTry, $content);

// Pattern 4: Fermer le if ajouté
$oldCatch = <<<'EOD'
                } catch (\Exception $e) {
                    // Ignorer les erreurs de récupération KLASSCI (séance peut être archivée)
                    Log::debug('Impossible de récupérer détails KLASSCI pour historique', [
                        'seance_id' => $attendance->seance->klassci_seance_id,
                        'error' => $e->getMessage()
                    ]);
                }

                return $data;
EOD;

$newCatch = <<<'EOD'
                    } catch (\Exception $e) {
                        // Ignorer les erreurs de récupération KLASSCI (séance peut être archivée)
                        Log::debug('Impossible de récupérer détails KLASSCI pour historique', [
                            'seance_id' => $attendance->seance->klassci_seance_id,
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                return $data;
EOD;

$content = str_replace($oldCatch, $newCatch, $content);

file_put_contents($file, $content);

echo "✅ Fichier LMSDataController.php corrigé\n";
echo "   - Ajout de vérification des relations user/seance\n";
echo "   - Ajout de filter() pour retirer les participations orphelines\n";
echo "   - Ajout de vérification klassci_seance_id avant appel API\n";
