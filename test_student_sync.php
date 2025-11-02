<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\UserClass;

echo "🧪 TEST SYNCHRONISATION CLASSES ÉTUDIANT\n";
echo "==========================================\n\n";

// Vérifier MARCEL
$marcel = User::where('email', 'marcel.ouedraogo@esbtp.edu')->first();

if (!$marcel) {
    echo "❌ MARCEL non trouvé\n";
    exit(1);
}

echo "✅ Étudiant trouvé:\n";
echo "  - ID: {$marcel->id}\n";
echo "  - Nom: {$marcel->name}\n";
echo "  - Email: {$marcel->email}\n";
echo "  - KLASSCI ID: {$marcel->klassci_id}\n\n";

// Vérifier ses classes
$classes = $marcel->klassciClasses;

echo "📚 Classes de l'étudiant:\n";
echo "  - Nombre de classes: " . $classes->count() . "\n\n";

if ($classes->count() > 0) {
    echo "✅ CLASSES SYNCHRONISÉES:\n";
    foreach ($classes as $classe) {
        echo "  • {$classe->classe_nom} (KLASSCI ID: {$classe->klassci_classe_id})\n";
        echo "    - Libellé: {$classe->classe_libelle}\n";
        echo "    - Synchronisé: {$classe->synced_at}\n\n";
    }
} else {
    echo "⚠️  Aucune classe synchronisée.\n";
    echo "   L'étudiant doit se connecter pour synchroniser ses classes.\n\n";
}

echo "✅ Test terminé\n";
