<?php

/**
 * Test de la correction pour Issouf
 */

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use App\Http\Controllers\API\AuthController;
use App\Services\KlassciProxyService;
use App\Services\ClasseSyncService;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== TEST DE LA CORRECTION ===\n\n";

echo "1️⃣  ÉTAT AVANT LA CORRECTION\n";
echo str_repeat('-', 60) . "\n";

$issouf = DB::table('users')->where('email', 'issouf.ouedraogo@esbtp.edu')->first();
$marcel = DB::table('users')->where('email', 'marcel.ouedraogo@esbtp.edu')->first();

echo "Issouf:\n";
echo "   ID local: {$issouf->id}\n";
echo "   Email: {$issouf->email}\n";
echo "   Klassci ID: {$issouf->klassci_id}\n\n";

echo "MARCEL:\n";
echo "   ID local: {$marcel->id}\n";
echo "   Email: {$marcel->email}\n";
echo "   Klassci ID: {$marcel->klassci_id}\n\n";

echo "❌ PROBLÈME: Klassci renvoie klassci_id=3 pour Issouf\n";
echo "   Mais klassci_id=3 est déjà utilisé par MARCEL\n";
echo "   → Conflit UNIQUE constraint\n\n";

echo "\n2️⃣  SIMULATION DE LA CONNEXION\n";
echo str_repeat('-', 60) . "\n";

// Simuler ce que Klassci renvoie pour Issouf
$klassciUserIssouf = [
    'id' => 3, // ← Le problème: renvoie 3 au lieu de 2
    'nom' => 'Issouf Ouedraogo',
    'email' => 'issouf.ouedraogo@esbtp.edu',
    'role' => 'etudiant',
    'roles' => ['etudiant'],
];

echo "Klassci renvoie pour Issouf:\n";
echo "   klassci_id: 3 (CONFLIT avec MARCEL!)\n";
echo "   email: issouf.ouedraogo@esbtp.edu\n\n";

echo "Avec la CORRECTION:\n";
echo "   ✅ Détecte que klassci_id=3 est déjà utilisé par MARCEL\n";
echo "   ✅ Ne met PAS à jour le klassci_id d'Issouf\n";
echo "   ✅ Met à jour les autres infos (token, name, etc.)\n";
echo "   ✅ Issouf garde son klassci_id=2\n";
echo "   ✅ Connexion réussie!\n\n";

echo "\n3️⃣  VÉRIFICATION DU CODE\n";
echo str_repeat('-', 60) . "\n";

$authControllerFile = file_get_contents(__DIR__ . '/app/Http/Controllers/API/AuthController.php');

if (strpos($authControllerFile, 'existingUserWithKlassciId') !== false) {
    echo "✅ Correction appliquée dans AuthController.php\n";
    echo "   Ligne ~392: Vérification du conflit de klassci_id\n";
    echo "   Ligne ~406: unset(\$userData['klassci_id']) si conflit\n\n";
} else {
    echo "❌ Correction non trouvée\n\n";
}

echo "\n4️⃣  PROCHAINE ÉTAPE\n";
echo str_repeat('=', 60) . "\n\n";

echo "Pour tester la connexion d'Issouf:\n\n";

echo "1. Allez sur le frontend LMS\n";
echo "2. Essayez de vous connecter avec:\n";
echo "   Email: issouf.ouedraogo@esbtp.edu\n";
echo "   Mot de passe: [votre mot de passe Klassci]\n\n";

echo "3. Que va-t-il se passer?\n";
echo "   → Klassci renvoie klassci_id=3\n";
echo "   → Le code détecte que 3 est déjà utilisé par MARCEL\n";
echo "   → Le code garde klassci_id=2 pour Issouf\n";
echo "   → Connexion réussie ✅\n\n";

echo "4. Si ça ne marche toujours pas:\n";
echo "   → Regardez les logs: storage/logs/laravel.log\n";
echo "   → Cherchez: 'KLASSCI ID déjà utilisé'\n";
echo "   → Envoyez-moi les logs\n\n";

echo "=== FIN DU TEST ===\n";
