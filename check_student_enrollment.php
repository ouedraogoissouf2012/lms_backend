<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Seance;
use App\Services\KlassciProxyService;

echo "🔍 VÉRIFICATION INSCRIPTION ÉTUDIANT\n";
echo "=====================================\n\n";

// 1. Récupérer l'étudiant MARCEL OUEDRAOGO
$student = User::where('name', 'LIKE', '%MARCEL%OUEDRAOGO%')
    ->where('role', 'etudiant')
    ->first();

if (!$student) {
    echo "❌ Étudiant MARCEL OUEDRAOGO non trouvé dans la BDD locale\n";
    exit(1);
}

echo "✅ Étudiant trouvé dans BDD locale:\n";
echo "   - ID local: {$student->id}\n";
echo "   - Name: {$student->name}\n";
echo "   - KLASSCI ID: " . ($student->klassci_id ?? 'N/A') . "\n";
echo "   - Email: " . ($student->email ?? 'N/A') . "\n";
echo "   - Token KLASSCI: " . (empty($student->klassci_token) ? '❌ MANQUANT' : '✅ Présent') . "\n\n";

// 2. Récupérer la séance 37
$seance = Seance::where('klassci_seance_id', 37)->first();

if (!$seance) {
    echo "❌ Séance 37 non trouvée\n";
    exit(1);
}

echo "✅ Séance 37 trouvée:\n";
echo "   - ID local: {$seance->id}\n";
echo "   - Classe KLASSCI ID: {$seance->klassci_classe_id}\n";
echo "   - Matière: {$seance->matiere_libelle}\n";
echo "   - Visio activée: " . ($seance->visio_enabled ? 'Oui' : 'Non') . "\n";
echo "   - Visio active: " . ($seance->visio_active ? 'Oui' : 'Non') . "\n\n";

// 3. Vérifier dans KLASSCI si l'étudiant est inscrit dans la classe
if (empty($student->klassci_token)) {
    echo "⚠️  Impossible de vérifier dans KLASSCI : token manquant\n";
    exit(1);
}

echo "🔄 Vérification inscription dans KLASSCI...\n";

try {
    $klassciService = app(KlassciProxyService::class);

    // Récupérer la liste des étudiants de la classe
    $classeId = $seance->klassci_classe_id;
    echo "   Classe ID: {$classeId}\n";

    $response = $klassciService->get("classes/{$classeId}/etudiants");
    $etudiants = $response['data'] ?? [];

    echo "   Nombre d'étudiants dans la classe: " . count($etudiants) . "\n\n";

    // Chercher l'étudiant dans la liste
    $found = false;
    foreach ($etudiants as $etudiant) {
        if ($etudiant['id'] == $student->klassci_id) {
            $found = true;
            echo "✅ MARCEL OUEDRAOGO EST INSCRIT dans la classe !\n\n";
            echo "Détails KLASSCI:\n";
            echo "   - ID: {$etudiant['id']}\n";
            echo "   - Nom: {$etudiant['nom']}\n";
            echo "   - Prénom: {$etudiant['prenom']}\n";
            echo "   - Email: {$etudiant['email']}\n";
            break;
        }
    }

    if (!$found) {
        echo "❌ MARCEL OUEDRAOGO N'EST PAS INSCRIT dans la classe !\n\n";
        echo "📋 Liste des étudiants inscrits dans la classe:\n";
        foreach ($etudiants as $index => $etudiant) {
            echo "   " . ($index + 1) . ". {$etudiant['nom']} {$etudiant['prenom']} (ID: {$etudiant['id']})\n";
        }
        echo "\n";
        echo "🔍 Recherché: ID KLASSCI = {$student->klassci_id}\n";
    }

} catch (\Exception $e) {
    echo "❌ Erreur lors de la vérification KLASSCI:\n";
    echo "   " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✅ Vérification terminée\n";
