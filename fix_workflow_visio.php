<?php

/**
 * FIX: Remettre le workflow en 2 étapes (programmee → active)
 */

echo "🔧 Correction du workflow visio en 2 étapes...\n\n";

$file = 'app/Http/Controllers/API/LMSDataController.php';

if (!file_exists($file)) {
    die("❌ Fichier non trouvé\n");
}

$content = file_get_contents($file);

// 1. Remettre activateVisio avec status='programmee'
$search = "'visio_enabled' => true,
                    'visio_type' => 'jitsi',
                    'visio_status' => 'active',
                    'visio_room_id' => 'lms_seance_' . \$seanceId . '_' . time(),
                    'visio_active' => true,
                    'visio_started_at' => now(),
                    'updated_by' => \$user->id,";

$replace = "'visio_enabled' => true,
                    'visio_type' => 'jitsi',
                    'visio_status' => 'programmee',
                    'visio_room_id' => 'lms_seance_' . \$seanceId . '_' . time(),
                    'visio_active' => false,
                    'updated_by' => \$user->id,";

if (strpos($content, "'visio_status' => 'active',") !== false) {
    $content = str_replace($search, $replace, $content);
    echo "✅ activateVisio() remis à status='programmee'\n";
} else {
    echo "✅ activateVisio() déjà correct\n";
}

// 2. Modifier la réponse JSON de activateVisio
$search2 = "'success' => true,
                'message' => 'Visioconférence activée et démarrée',
                'data' => [
                    'visio_enabled' => true,
                    'visio_status' => 'active',
                    'visio_room_id' => \$visio->visio_room_id,
                    'visio_started_at' => \$visio->visio_started_at
                ]";

$replace2 = "'success' => true,
                'message' => 'Visioconférence activée',
                'data' => [
                    'visio_enabled' => true,
                    'visio_status' => 'programmee',
                    'visio_room_id' => \$visio->visio_room_id
                ]";

$content = str_replace($search2, $replace2, $content);
echo "✅ Réponse JSON de activateVisio() corrigée\n";

file_put_contents($file, $content);

echo "\n✅ BACKEND CORRIGÉ!\n\n";
echo "Maintenant il faut modifier le frontend...\n";
