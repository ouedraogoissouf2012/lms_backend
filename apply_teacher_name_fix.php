<?php

/**
 * FIX: Ajouter nom enseignant dans getVisioParticipants
 */

$file = 'app/Http/Controllers/API/LMSDataController.php';

if (!file_exists($file)) {
    die("❌ Fichier non trouvé: $file\n");
}

echo "📝 Modification de $file...\n\n";

$content = file_get_contents($file);

// Rechercher le pattern exact
$search = "            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'students' => \$unifiedList,
                    'statistics' => \$stats
                ]
            ]);";

$replace = "            ];

            // Récupérer l'info enseignant depuis la table seances
            \$teacherInfo = [
                'nom' => \$visio->enseignant_nom ?? null,
                'prenom' => \$visio->enseignant_prenom ?? null,
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'students' => \$unifiedList,
                    'statistics' => \$stats,
                    'teacher' => \$teacherInfo,
                    'seance' => [
                        'id' => \$seanceId,
                        'matiere' => \$visio->matiere_nom,
                        'classe_id' => \$visio->klassci_classe_id,
                    ]
                ]
            ]);";

if (strpos($content, "'teacher' => \$teacherInfo") !== false) {
    echo "✅ Fix déjà appliqué!\n";
    exit(0);
}

$newContent = str_replace($search, $replace, $content);

if ($newContent === $content) {
    echo "❌ Pattern non trouvé. Vérifier manuellement.\n";
    echo "Rechercher: 'students' => \$unifiedList,\n";
    echo "            'statistics' => \$stats\n";
    exit(1);
}

file_put_contents($file, $newContent);

echo "✅ Modification appliquée avec succès!\n\n";
echo "Changements:\n";
echo "- Ajout de 'teacher' avec nom et prénom\n";
echo "- Ajout de 'seance' avec id, matière et classe_id\n\n";
echo "Le nom de l'enseignant sera maintenant disponible dans la liste de présence!\n";
