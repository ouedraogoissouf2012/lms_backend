<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Seance;
use App\Models\ESBTPAttendance;
use App\Models\User;

echo "🧪 TEST: Liste de présence unifiée\n";
echo str_repeat('=', 80) . "\n\n";

// Trouver une séance active ou récente
$seance = Seance::where('visio_status', 'active')
    ->orWhere('visio_status', 'ended')
    ->orderBy('updated_at', 'desc')
    ->first();

if (!$seance) {
    echo "❌ Aucune séance trouvée\n";
    exit(1);
}

echo "📌 Séance testée:\n";
echo "   - ID: {$seance->klassci_seance_id}\n";
echo "   - Matière: {$seance->matiere_nom}\n";
echo "   - Enseignant: {$seance->enseignant_nom}\n";
echo "   - Status: {$seance->visio_status}\n\n";

// Récupérer les participants réels (qui ont rejoint)
$actualParticipants = ESBTPAttendance::where('seance_id', $seance->id)
    ->with('user')
    ->get();

echo "👥 Participants qui ont rejoint la visio: " . $actualParticipants->count() . "\n";
foreach ($actualParticipants as $p) {
    $duration = $p->duration_minutes ?? 0;
    echo "   ✅ {$p->nom} {$p->prenom} - {$duration} min\n";
}
echo "\n";

// Simuler l'appel de l'API pour avoir tous les étudiants de la classe
// En réalité, cela viendrait de seanceDetails, mais simulons avec tous les étudiants
$allStudents = User::where('role', 'etudiant')
    ->select('id', 'nom', 'prenom', 'email')
    ->limit(15)
    ->get()
    ->map(function($u) {
        return [
            'id' => $u->id,
            'nom' => $u->nom ?? $u->name,
            'prenom' => $u->prenom ?? '',
            'email' => $u->email
        ];
    })
    ->toArray();

echo "📚 Total étudiants dans la classe (simulé): " . count($allStudents) . "\n\n";

// Simuler la logique du contrôleur
$seanceDurationMinutes = 120; // 2h par défaut

$actualParticipantsById = [];
foreach ($actualParticipants as $attendance) {
    $actualParticipantsById[$attendance->user_id] = $attendance;
}

$unifiedList = [];

foreach ($allStudents as $student) {
    $studentId = $student['id'];
    $isPresent = isset($actualParticipantsById[$studentId]);

    $studentData = [
        'user_id' => $studentId,
        'nom' => $student['nom'] ?? '',
        'prenom' => $student['prenom'] ?? '',
        'email' => $student['email'] ?? '',
    ];

    if ($isPresent) {
        // Étudiant PRÉSENT
        $attendance = $actualParticipantsById[$studentId];
        $durationMinutes = $attendance->duration_minutes ?? 0;
        $percentage = $seanceDurationMinutes > 0
            ? round(($durationMinutes / $seanceDurationMinutes) * 100)
            : 0;

        // Déterminer le statut
        if ($percentage === 100) {
            $status = 'Présent (complet)';
            $icon = '✅';
        } elseif ($percentage >= 90) {
            $status = "Présent ({$percentage}%)";
            $icon = '✅';
        } elseif ($percentage >= 50) {
            $status = "Présent ({$percentage}%)";
            $icon = '⚠️';
        } else {
            $status = "Présent ({$percentage}%)";
            $icon = '⚠️';
        }

        $studentData = array_merge($studentData, [
            'status' => $status,
            'status_icon' => $icon,
            'percentage' => $percentage,
            'duration_minutes' => $durationMinutes,
            'duration_formatted' => $attendance->formatted_duration ?? "{$durationMinutes}min",
            'is_present' => true
        ]);
    } else {
        // Étudiant ABSENT
        $studentData = array_merge($studentData, [
            'status' => 'Absent',
            'status_icon' => '❌',
            'percentage' => 0,
            'duration_minutes' => 0,
            'duration_formatted' => '-',
            'is_present' => false
        ]);
    }

    $unifiedList[] = $studentData;
}

// Trier: présents d'abord, puis par pourcentage
usort($unifiedList, function($a, $b) {
    if ($a['is_present'] !== $b['is_present']) {
        return $b['is_present'] <=> $a['is_present'];
    }
    if ($a['is_present'] && $b['is_present']) {
        return $b['percentage'] <=> $a['percentage'];
    }
    return strcmp($a['nom'], $b['nom']);
});

echo "📊 LISTE DE PRÉSENCE UNIFIÉE:\n";
echo str_repeat('-', 80) . "\n";
echo sprintf("%-3s | %-25s | %-20s | %-10s\n", "#", "NOM", "STATUT", "DURÉE");
echo str_repeat('-', 80) . "\n";

foreach ($unifiedList as $index => $student) {
    $name = trim($student['nom'] . ' ' . $student['prenom']);
    $status = $student['status_icon'] . ' ' . $student['status'];
    $duration = $student['duration_formatted'];

    echo sprintf("%-3d | %-25s | %-20s | %-10s\n",
        $index + 1,
        substr($name, 0, 25),
        substr($status, 0, 20),
        $duration
    );
}

echo str_repeat('-', 80) . "\n\n";

// Statistiques
$presentCount = count(array_filter($unifiedList, fn($s) => $s['is_present']));
$absentCount = count($unifiedList) - $presentCount;
$presenceRate = count($unifiedList) > 0 ? round(($presentCount / count($unifiedList)) * 100) : 0;

echo "📈 STATISTIQUES:\n";
echo "   • Total étudiants: " . count($unifiedList) . "\n";
echo "   • Présents: {$presentCount} ({$presenceRate}%)\n";
echo "   • Absents: {$absentCount}\n\n";

echo "✅ Test terminé avec succès!\n";
