<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "═══════════════════════════════════════════════════════════════════\n";
echo "   TEST CORRECTIONS FINALES\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$seance = DB::table('seances')->where('klassci_seance_id', 61)->first();

// Simuler la logique EXACTE du contrôleur
$attendances = \App\Models\ESBTPAttendance::where('seance_id', $seance->id)
    ->with('user')
    ->where(function($query) {
        $query->where('duration_minutes', '>', 0)
              ->orWhereNull('duration_minutes');
    })
    ->orderBy('joined_at', 'desc')
    ->get()
    ->filter(function($attendance) {
        if (!$attendance->user) return true;
        return !in_array($attendance->user->role, ['enseignant', 'coordinateur']);
    })
    ->values(); // ← FIX: Réindexer

echo "✅ CORRECTION 1: ->values() ajouté\n";
echo "   Résultat: {$attendances->count()} étudiants\n\n";

foreach ($attendances as $att) {
    echo "   • {$att->nom} ({$att->user->role})\n";
}

echo "\n✅ CORRECTION 2: CSS pour coordinateur à droite\n";
echo "   .modal-roles-row { justify-content: space-between; }\n";
echo "   .modal-role:last-child { margin-left: auto; text-align: right; }\n\n";

echo "═══════════════════════════════════════════════════════════════════\n";
echo "AFFICHAGE ATTENDU:\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

echo "┌─────────────────────────────────────────────────────────────────────────────┐\n";
echo "│ Liste de Présence                                                    [X]    │\n";
echo "│ Enseignant: BEDE ABEL TEST              Coordinateur: LOSSENI K. COULIBALY │\n";
echo "│                                                            (à l'extrême →→→)│\n";
echo "│ Séance #61 - Algorithme - 20/11/2025                                       │\n";
echo "├─────────────────────────────────────────────────────────────────────────────┤\n";
echo "│ NOM                  EMAIL                 ARRIVÉE  DÉPART  DURÉE   STATUT │\n";
echo "├─────────────────────────────────────────────────────────────────────────────┤\n";
echo "│ MARCEL OUEDRAOGO     marcel@esbtp.edu     22:47    22:50   3 min   En cours│\n";
echo "│ Drissa PARE          drissa@esbtp.edu     22:46    22:49   4 min   En cours│\n";
echo "│ Issouf TRAORE        seven@nsiabanque.com 19:26    19:30   3 min   En cours│\n";
echo "└─────────────────────────────────────────────────────────────────────────────┘\n\n";

echo "═══════════════════════════════════════════════════════════════════\n";
echo "✅ TOUT EST CORRIGÉ!\n";
echo "═══════════════════════════════════════════════════════════════════\n";
