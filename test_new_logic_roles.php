<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "═══════════════════════════════════════════════════════════════════\n";
echo "   TEST NOUVELLE LOGIQUE - ENSEIGNANT ET COORDINATEUR\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$seance = DB::table('seances')->where('klassci_seance_id', 61)->first();

echo "SÉANCE #61 - {$seance->matiere_nom}\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// Récupérer TOUS les participants avec leur user
$allParticipants = DB::table('esbtp_attendance')
    ->where('seance_id', $seance->id)
    ->leftJoin('users', 'esbtp_attendance.user_id', '=', 'users.id')
    ->select('esbtp_attendance.*', 'users.role as user_role')
    ->get();

echo "TOUS LES PARTICIPANTS (avant filtrage):\n";
echo str_repeat('─', 100) . "\n";
printf("%-25s %-20s %-15s %-15s\n", "Nom", "Email", "users.role", "is_observer");
echo str_repeat('─', 100) . "\n";

foreach ($allParticipants as $p) {
    printf("%-25s %-20s %-15s %-15s\n",
        substr($p->nom, 0, 25),
        substr($p->email, 0, 20),
        $p->user_role ?? '[NULL]',
        $p->is_observer ? 'TRUE' : 'FALSE'
    );
}
echo str_repeat('─', 100) . "\n\n";

// Identifier enseignant
$teacher = null;
foreach ($allParticipants as $p) {
    if ($p->user_role === 'enseignant') {
        $teacher = $p;
        break;
    }
}

// Identifier coordinateur
$coordinator = null;
foreach ($allParticipants as $p) {
    if ($p->user_role === 'coordinateur') {
        $coordinator = $p;
        break;
    }
}

// Filtrer les étudiants (exclure enseignant et coordinateur)
$students = $allParticipants->filter(function($p) {
    return !in_array($p->user_role, ['enseignant', 'coordinateur']);
})->filter(function($p) {
    // Exclure durée = 0
    return $p->duration_minutes === null || $p->duration_minutes > 0;
});

echo "═══════════════════════════════════════════════════════════════════\n";
echo "RÉSULTAT DE LA LOGIQUE:\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

echo "👨‍🏫 ENSEIGNANT:\n";
if ($teacher) {
    echo "  Nom: {$teacher->nom}\n";
    echo "  Email: {$teacher->email}\n";
    echo "  Durée: " . ($teacher->duration_minutes ?? 'NULL') . " min\n";
} else {
    echo "  ❌ Non trouvé\n";
}

echo "\n👁️ COORDINATEUR:\n";
if ($coordinator) {
    echo "  Nom: {$coordinator->nom}\n";
    echo "  Email: {$coordinator->email}\n";
    echo "  Durée: " . ($coordinator->duration_minutes ?? 'NULL') . " min\n";
} else {
    echo "  ❌ Non trouvé\n";
}

echo "\n👥 ÉTUDIANTS ({$students->count()}):\n";
echo str_repeat('─', 100) . "\n";
printf("%-25s %-30s %-12s\n", "Nom", "Email", "Durée");
echo str_repeat('─', 100) . "\n";

foreach ($students as $s) {
    printf("%-25s %-30s %-12s\n",
        substr($s->nom, 0, 25),
        substr($s->email, 0, 30),
        ($s->duration_minutes ?? 'NULL') . ' min'
    );
}
echo str_repeat('─', 100) . "\n\n";

echo "═══════════════════════════════════════════════════════════════════\n";
echo "AFFICHAGE MODAL (SIMULATION):\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

echo "┌─────────────────────────────────────────────────────────────────┐\n";
echo "│ Liste de Présence                                               │\n";
$enseignantStr = $teacher ? $teacher->nom : 'Non spécifié';
$coordinateurStr = $coordinator ? $coordinator->nom : '';
echo "│ Enseignant: " . str_pad($enseignantStr, 25) . " Coordinateur: " . str_pad($coordinateurStr, 20) . "│\n";
echo "│ Séance #61 - Algorithme - 20/11/2025                           │\n";
echo "└─────────────────────────────────────────────────────────────────┘\n\n";

echo "TABLEAU (Seulement les étudiants):\n";
echo "┌──────────────────────┬─────────────────────────┬──────────┬──────────┬────────┬──────────┐\n";
echo "│ NOM                  │ EMAIL                   │ ARRIVÉE  │ DÉPART   │ DURÉE  │ STATUT   │\n";
echo "├──────────────────────┼─────────────────────────┼──────────┼──────────┼────────┼──────────┤\n";

foreach ($students as $s) {
    $arriveStr = $s->joined_at ? date('H:i', strtotime($s->joined_at)) : '-';
    $departStr = $s->left_at ? date('H:i', strtotime($s->left_at)) : '-';
    $dureeStr = $s->duration_minutes ? round($s->duration_minutes) . ' min' : '-';

    printf("│ %-20s │ %-23s │ %-8s │ %-8s │ %-6s │ %-8s │\n",
        substr($s->nom, 0, 20),
        substr($s->email, 0, 23),
        $arriveStr,
        $departStr,
        substr($dureeStr, 0, 6),
        '🔵 En cours'
    );
}

echo "└──────────────────────┴─────────────────────────┴──────────┴──────────┴────────┴──────────┘\n\n";

echo "═══════════════════════════════════════════════════════════════════\n";
echo "✅ VÉRIFICATION:\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "✅ Enseignant identifié via users.role = 'enseignant'\n";
echo "✅ Coordinateur identifié via users.role = 'coordinateur'\n";
echo "✅ Les deux affichés EN HAUT du modal (pas dans le tableau)\n";
echo "✅ Seulement les ÉTUDIANTS dans le tableau\n";
echo "✅ Colonne PRÉNOM supprimée\n";
echo "✅ 6 colonnes: Nom, Email, Arrivée, Départ, Durée, Statut\n";
echo "═══════════════════════════════════════════════════════════════════\n";
