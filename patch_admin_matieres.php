<?php

$file = 'app/Http/Controllers/API/LMSDataController.php';
$content = file_get_contents($file);

// Pattern 1: After nb_seances_programmees in the first array
$search1 = "'nb_seances_programmees' => \$matiere['nb_seances_programmees'] ?? 0,\n                        'combinaisons' => \$combinaisons";
$replace1 = "'nb_seances_programmees' => \$matiere['nb_seances_programmees'] ?? 0,\n                        'nb_lecons' => \$matiere['nb_lecons'] ?? 0,\n                        'nb_evaluations' => \$matiere['nb_evaluations'] ?? 0,\n                        'combinaisons' => \$combinaisons";

$content = str_replace($search1, $replace1, $content);

// Pattern 2: After nb_seances_programmees in the error case array
$search2 = "'nb_seances_programmees' => \$matiere['nb_seances_programmees'] ?? 0,\n                        'combinaisons' => []";
$replace2 = "'nb_seances_programmees' => \$matiere['nb_seances_programmees'] ?? 0,\n                        'nb_lecons' => \$matiere['nb_lecons'] ?? 0,\n                        'nb_evaluations' => \$matiere['nb_evaluations'] ?? 0,\n                        'combinaisons' => []";

$content = str_replace($search2, $replace2, $content);

file_put_contents($file, $content);

echo "✅ Fichier patché avec succès!\n";
echo "   Ajout de 'nb_lecons' et 'nb_evaluations' à adminMatieresList()\n";
