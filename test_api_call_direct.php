<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

echo "═══════════════════════════════════════════════════════════════════\n";
echo "   TEST APPEL API DIRECT\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// Simuler une vraie requête HTTP
$request = \Illuminate\Http\Request::create(
    '/api/lms/seances/16/attendances',
    'GET'
);

// Créer le contrôleur et appeler la méthode
$controller = new \App\Http\Controllers\API\LMSDataController();

try {
    $response = $controller->getSeanceAttendances($request, 16);
    $content = $response->getContent();
    $data = json_decode($content, true);

    echo "STATUS: " . $response->getStatusCode() . "\n\n";

    if (isset($data['success']) && $data['success']) {
        echo "✅ SUCCESS\n\n";

        echo "SEANCE INFO:\n";
        echo "  - enseignant_nom: " . ($data['seance']['enseignant_nom'] ?? 'NULL') . "\n";
        echo "  - coordinateur_nom: " . ($data['seance']['coordinateur_nom'] ?? 'NULL') . "\n";
        echo "  - is_finished: " . ($data['seance']['is_finished'] ? 'true' : 'false') . "\n\n";

        echo "ATTENDANCES:\n";
        if (isset($data['attendances'])) {
            echo "  Type: " . (is_array($data['attendances']) ? 'ARRAY ✅' : 'OBJECT ❌') . "\n";
            echo "  Count: " . count($data['attendances']) . "\n\n";

            if (count($data['attendances']) > 0) {
                echo "  Liste:\n";
                foreach ($data['attendances'] as $att) {
                    echo "    • {$att['nom']} - {$att['email']}\n";
                }
            } else {
                echo "  ⚠️  ARRAY VIDE!\n";
            }
        } else {
            echo "  ❌ Clé 'attendances' n'existe pas!\n";
        }

        echo "\n\nRÉPONSE COMPLÈTE (extrait):\n";
        echo substr(json_encode($data, JSON_PRETTY_PRINT), 0, 1000) . "...\n";

    } else {
        echo "❌ ERREUR\n";
        echo $content . "\n";
    }

} catch (\Exception $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "SI CETTE LISTE CONTIENT 3 ÉTUDIANTS, LE BACKEND EST OK\n";
echo "LE PROBLÈME SERAIT ALORS CÔTÉ FRONTEND (cache navigateur ou code Vue)\n";
echo "═══════════════════════════════════════════════════════════════════\n";
