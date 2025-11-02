<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Seance;
use App\Models\ESBTPAttendance;
use App\Models\User;
use Illuminate\Support\Facades\DB;

echo "🔍 DEBUG: Participants Séance #37\n";
echo str_repeat('=', 80) . "\n\n";

// Trouver la séance
$seance = Seance::where('klassci_seance_id', 37)->first();

if (!$seance) {
    echo "❌ Séance #37 non trouvée\n";
    exit(1);
}

echo "📌 Séance #37:\n";
echo "   - ID local: {$seance->id}\n";
echo "   - Matière: " . ($seance->matiere_nom ?? 'N/A') . "\n";
echo "   - Enseignant: " . ($seance->enseignant_nom ?? 'N/A') . "\n";
echo "   - Status: " . ($seance->visio_status ?? 'N/A') . "\n";
echo "   - klassci_matiere_id: " . ($seance->klassci_matiere_id ?? 'NULL') . "\n";
echo "   - klassci_classe_id: " . ($seance->klassci_classe_id ?? 'NULL') . "\n\n";

echo str_repeat('=', 80) . "\n";
echo "👥 PARTICIPANTS RÉELS (table esbtp_attendance):\n";
echo str_repeat('-', 80) . "\n";

$actualParticipants = ESBTPAttendance::where('seance_id', $seance->id)
    ->with('user')
    ->get();

echo "Nombre de participants ayant rejoint: {$actualParticipants->count()}\n\n";

foreach ($actualParticipants as $p) {
    echo "   ✅ {$p->nom} {$p->prenom} (User ID: {$p->user_id})\n";
    echo "      - Email: {$p->email}\n";
    echo "      - Rejoint: " . ($p->joined_at ? $p->joined_at->format('H:i:s') : 'N/A') . "\n";
    echo "      - Quitté: " . ($p->left_at ? $p->left_at->format('H:i:s') : 'Encore connecté') . "\n";
    echo "      - Durée: " . ($p->duration_minutes ?? 0) . " min\n";
    echo "\n";
}

echo str_repeat('=', 80) . "\n";
echo "📚 ÉTUDIANTS DE LA CLASSE (via klassci_classe_id):\n";
echo str_repeat('-', 80) . "\n";

if ($seance->klassci_classe_id) {
    echo "Classe ID: {$seance->klassci_classe_id}\n\n";

    // Chercher les étudiants via la table user_classes
    $students = DB::table('user_classes')
        ->where('klassci_classe_id', $seance->klassci_classe_id)
        ->join('users', 'user_classes.user_id', '=', 'users.id')
        ->select('users.id', 'users.name', 'users.nom', 'users.prenom', 'users.email', 'users.role')
        ->get();

    echo "Étudiants trouvés via user_classes: {$students->count()}\n\n";

    if ($students->count() > 0) {
        foreach ($students as $student) {
            echo "   📖 {$student->nom} {$student->prenom} (ID: {$student->id})\n";
            echo "      - Email: {$student->email}\n";
            echo "      - Role: {$student->role}\n";
            echo "\n";
        }
    } else {
        echo "   ⚠️ Aucun étudiant trouvé dans user_classes pour cette classe\n\n";

        // Chercher tous les étudiants
        echo "   Cherchons tous les étudiants en BDD...\n\n";
        $allStudents = User::where('role', 'etudiant')->get();
        echo "   Total étudiants en BDD: {$allStudents->count()}\n";
    }
} else {
    echo "⚠️ Pas de klassci_classe_id pour cette séance!\n";
    echo "Impossible de retrouver les étudiants de la classe.\n\n";

    // Afficher tous les étudiants disponibles
    $allStudents = User::where('role', 'etudiant')
        ->select('id', 'name', 'nom', 'prenom', 'email')
        ->get();

    echo "Tous les étudiants en BDD: {$allStudents->count()}\n\n";

    foreach ($allStudents as $s) {
        echo "   📖 " . ($s->nom ?? $s->name) . " " . ($s->prenom ?? '') . " (ID: {$s->id})\n";
        echo "      - Email: {$s->email}\n";
        echo "\n";
    }
}

echo str_repeat('=', 80) . "\n";
echo "🔍 ANALYSE:\n";
echo str_repeat('-', 80) . "\n";

echo "\n1. Participants qui ont rejoint: {$actualParticipants->count()}\n";
echo "2. Classe ID: " . ($seance->klassci_classe_id ?? 'NON DÉFINI') . "\n";

if (!$seance->klassci_classe_id) {
    echo "\n⚠️ PROBLÈME: La séance n'a pas de klassci_classe_id!\n";
    echo "   Sans classe ID, impossible de récupérer la liste complète des étudiants.\n";
    echo "   Le modal ne montrera QUE les participants qui ont rejoint.\n";
}

echo "\n✅ Debug terminé!\n";
