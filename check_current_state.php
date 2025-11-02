<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Seance;
use App\Models\User;
use App\Models\ESBTPAttendance;

echo "🔍 ÉTAT ACTUEL DU SYSTÈME\n";
echo "=========================\n\n";

// 1. Vérifier les séances actives
echo "1️⃣ SÉANCES VISIO ACTIVES:\n";
echo "===========================\n";

$seancesActives = Seance::where('visio_status', 'active')->get();

if ($seancesActives->count() == 0) {
    echo "❌ Aucune séance active actuellement\n\n";
} else {
    foreach ($seancesActives as $seance) {
        echo "✅ Séance KLASSCI ID: {$seance->klassci_seance_id}\n";
        echo "   - Matière: {$seance->matiere_nom}\n";
        echo "   - Enseignant: {$seance->enseignant_nom}\n";
        echo "   - Status: {$seance->visio_status}\n";
        echo "   - Démarrée le: {$seance->visio_started_at}\n";
        echo "   - Room ID: {$seance->visio_room_id}\n";
        echo "   - Classe ID: " . ($seance->klassci_classe_id ?? 'N/A') . "\n";
        echo "   - Matière ID: " . ($seance->klassci_matiere_id ?? 'N/A') . "\n\n";

        // Vérifier les participants
        $participants = ESBTPAttendance::where('seance_id', $seance->id)
            ->where('status', 'connected')
            ->get();

        echo "   👥 Participants actuels: {$participants->count()}\n";
        if ($participants->count() > 0) {
            foreach ($participants as $p) {
                echo "      • {$p->nom} ({$p->email}) - Rejoint: {$p->joined_at}\n";
            }
        }
        echo "\n";
    }
}

// 2. Vérifier MARCEL
echo "2️⃣ STATUT ÉTUDIANT MARCEL:\n";
echo "===========================\n";

$marcel = User::where('email', 'marcel.ouedraogo@esbtp.edu')->first();

if (!$marcel) {
    echo "❌ MARCEL non trouvé\n\n";
} else {
    echo "✅ MARCEL trouvé:\n";
    echo "   - ID: {$marcel->id}\n";
    echo "   - Nom: {$marcel->name}\n";
    echo "   - Email: {$marcel->email}\n";
    echo "   - Role: {$marcel->role}\n";
    echo "   - KLASSCI ID: {$marcel->klassci_id}\n";
    echo "   - Token présent: " . ($marcel->klassci_token ? 'OUI' : 'NON') . "\n";
    echo "   - Dernière sync: " . ($marcel->last_klassci_sync ?? 'Jamais') . "\n\n";

    // Vérifier ses classes
    $classes = $marcel->klassciClasses;
    echo "   📚 Classes synchronisées: {$classes->count()}\n";
    if ($classes->count() > 0) {
        foreach ($classes as $classe) {
            echo "      • {$classe->classe_nom} (ID: {$classe->klassci_classe_id})\n";
        }
    }
    echo "\n";
}

// 3. Vérifier l'enseignant connecté
echo "3️⃣ ENSEIGNANTS CONNECTÉS RÉCEMMENT:\n";
echo "====================================\n";

$enseignants = User::where('role', 'enseignant')
    ->orWhere('role', 'teacher')
    ->whereNotNull('klassci_token')
    ->get();

if ($enseignants->count() == 0) {
    echo "❌ Aucun enseignant connecté\n\n";
} else {
    foreach ($enseignants as $prof) {
        echo "✅ {$prof->name}\n";
        echo "   - Email: {$prof->email}\n";
        echo "   - KLASSCI ID: {$prof->klassci_id}\n";
        echo "   - Dernière sync: " . ($prof->last_klassci_sync ?? 'Jamais') . "\n\n";
    }
}

echo "✅ Vérification terminée\n";
