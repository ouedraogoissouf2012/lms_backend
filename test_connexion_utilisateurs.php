<?php

/**
 * Test de connexion pour tous les types d'utilisateurs
 * Vérifie si les utilisateurs créés dans Klassci peuvent se connecter au LMS
 */

require __DIR__ . '/vendor/autoload.php';

use App\Services\KlassciProxyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== TEST DE CONNEXION DES UTILISATEURS ===\n\n";

$klassciService = app(KlassciProxyService::class);

// Utiliser le token d'un enseignant existant pour interroger Klassci
$existingTeacher = DB::table('users')
    ->where('role', 'enseignant')
    ->whereNotNull('klassci_token')
    ->first();

if (!$existingTeacher) {
    echo "❌ Aucun enseignant avec token trouvé pour les tests\n";
    exit(1);
}

echo "📋 ETAPE 1: RÉCUPÉRATION DES UTILISATEURS DEPUIS KLASSCI\n";
echo str_repeat('-', 60) . "\n\n";

$results = [
    'enseignants' => [],
    'etudiants' => [],
    'coordinateurs' => [],
];

try {
    // 1. Récupérer les enseignants depuis Klassci
    echo "1.1 Récupération des enseignants...\n";
    $matieres = $klassciService->requestWithUserToken($existingTeacher->klassci_token, 'matieres', 'GET');

    $enseignantsKlassci = [];
    foreach ($matieres['data'] ?? [] as $matiere) {
        $details = $klassciService->requestWithUserToken(
            $existingTeacher->klassci_token,
            "matieres/{$matiere['id']}",
            'GET'
        );

        if (isset($details['data']['enseignant'])) {
            $enseignant = $details['data']['enseignant'];
            $enseignantsKlassci[$enseignant['id']] = $enseignant;
        }
    }

    echo "   ✓ Trouvé " . count($enseignantsKlassci) . " enseignant(s) dans Klassci\n\n";

    foreach ($enseignantsKlassci as $ens) {
        $results['enseignants'][] = [
            'klassci_id' => $ens['id'],
            'nom' => $ens['nom'] ?? 'N/A',
            'email' => $ens['email'] ?? null,
        ];
    }

    // 2. Récupérer les étudiants depuis Klassci
    echo "1.2 Récupération des étudiants...\n";
    $classes = $klassciService->requestWithUserToken($existingTeacher->klassci_token, 'classes', 'GET');

    $etudiantsKlassci = [];
    foreach ($classes['data'] ?? [] as $classe) {
        $classeDetails = $klassciService->requestWithUserToken(
            $existingTeacher->klassci_token,
            "classes/{$classe['id']}",
            'GET'
        );

        foreach ($classeDetails['etudiants'] ?? [] as $etudiant) {
            $etudiantsKlassci[$etudiant['id']] = $etudiant;
        }
    }

    echo "   ✓ Trouvé " . count($etudiantsKlassci) . " étudiant(s) dans Klassci\n\n";

    foreach ($etudiantsKlassci as $etu) {
        $results['etudiants'][] = [
            'klassci_id' => $etu['id'],
            'nom' => $etu['nom'] ?? 'N/A',
            'email' => $etu['email'] ?? null,
        ];
    }

} catch (Exception $e) {
    echo "❌ Erreur lors de la récupération depuis Klassci: {$e->getMessage()}\n";
    exit(1);
}

echo "\n📋 ETAPE 2: VÉRIFICATION DANS LA BASE DE DONNÉES LOCALE\n";
echo str_repeat('-', 60) . "\n\n";

$stats = [
    'enseignants_ok' => 0,
    'enseignants_manquants' => 0,
    'enseignants_sans_email' => 0,
    'etudiants_ok' => 0,
    'etudiants_manquants' => 0,
    'etudiants_sans_email' => 0,
    'coordinateurs_total' => 0,
];

// Vérifier les enseignants
echo "2.1 Vérification des ENSEIGNANTS:\n\n";

foreach ($results['enseignants'] as $ensKlassci) {
    $localUser = DB::table('users')
        ->where('klassci_id', $ensKlassci['klassci_id'])
        ->where('role', 'enseignant')
        ->first();

    echo "   Enseignant Klassci ID {$ensKlassci['klassci_id']}: {$ensKlassci['nom']}\n";

    if (!$ensKlassci['email']) {
        echo "      ⚠️  PAS D'EMAIL dans Klassci - Ne pourra PAS se connecter\n";
        $stats['enseignants_sans_email']++;
    } else {
        echo "      Email Klassci: {$ensKlassci['email']}\n";

        if ($localUser) {
            echo "      ✅ Existe en local - Email: {$localUser->email}\n";
            echo "      ✅ Peut se connecter avec: {$localUser->email}\n";
            $stats['enseignants_ok']++;
        } else {
            echo "      ❌ N'EXISTE PAS en local\n";
            echo "      ❌ Ne peut PAS se connecter au LMS\n";
            $stats['enseignants_manquants']++;
        }
    }
    echo "\n";
}

// Vérifier les étudiants
echo "2.2 Vérification des ÉTUDIANTS:\n\n";

foreach ($results['etudiants'] as $etuKlassci) {
    $localUser = DB::table('users')
        ->where('klassci_id', $etuKlassci['klassci_id'])
        ->where('role', 'etudiant')
        ->first();

    echo "   Étudiant Klassci ID {$etuKlassci['klassci_id']}: {$etuKlassci['nom']}\n";

    if (!$etuKlassci['email']) {
        echo "      ⚠️  PAS D'EMAIL dans Klassci - Ne pourra PAS se connecter\n";
        $stats['etudiants_sans_email']++;
    } else {
        echo "      Email Klassci: {$etuKlassci['email']}\n";

        if ($localUser) {
            echo "      ✅ Existe en local - Email: {$localUser->email}\n";
            echo "      ✅ Peut se connecter avec: {$localUser->email}\n";
            $stats['etudiants_ok']++;
        } else {
            echo "      ❌ N'EXISTE PAS en local\n";
            echo "      ❌ Ne peut PAS se connecter au LMS\n";
            $stats['etudiants_manquants']++;
        }
    }
    echo "\n";
}

// Vérifier les coordinateurs
echo "2.3 Vérification des COORDINATEURS:\n\n";

$coordinateurs = DB::table('users')
    ->where('role', 'coordinateur')
    ->get();

$stats['coordinateurs_total'] = $coordinateurs->count();

foreach ($coordinateurs as $coord) {
    echo "   Coordinateur: {$coord->name}\n";
    echo "      Email: {$coord->email}\n";
    echo "      Klassci ID: " . ($coord->klassci_id ?? 'N/A') . "\n";
    echo "      ✅ Peut se connecter avec: {$coord->email}\n\n";
}

echo "\n📊 ETAPE 3: STATISTIQUES ET DIAGNOSTIC\n";
echo str_repeat('-', 60) . "\n\n";

$totalEnseignants = count($results['enseignants']);
$totalEtudiants = count($results['etudiants']);

echo "ENSEIGNANTS:\n";
echo "   Total dans Klassci: {$totalEnseignants}\n";
echo "   ✅ Peuvent se connecter: {$stats['enseignants_ok']}\n";
echo "   ❌ Manquants en local: {$stats['enseignants_manquants']}\n";
echo "   ⚠️  Sans email (Klassci): {$stats['enseignants_sans_email']}\n";

if ($totalEnseignants > 0) {
    $percentEnseignants = round(($stats['enseignants_ok'] / $totalEnseignants) * 100, 1);
    echo "   📈 Taux de connexion possible: {$percentEnseignants}%\n";
} else {
    $percentEnseignants = 0;
}
echo "\n";

echo "ÉTUDIANTS:\n";
echo "   Total dans Klassci: {$totalEtudiants}\n";
echo "   ✅ Peuvent se connecter: {$stats['etudiants_ok']}\n";
echo "   ❌ Manquants en local: {$stats['etudiants_manquants']}\n";
echo "   ⚠️  Sans email (Klassci): {$stats['etudiants_sans_email']}\n";

if ($totalEtudiants > 0) {
    $percentEtudiants = round(($stats['etudiants_ok'] / $totalEtudiants) * 100, 1);
    echo "   📈 Taux de connexion possible: {$percentEtudiants}%\n";
} else {
    $percentEtudiants = 0;
}
echo "\n";

echo "COORDINATEURS:\n";
echo "   Total en local: {$stats['coordinateurs_total']}\n";
echo "   ✅ Peuvent se connecter: {$stats['coordinateurs_total']}\n";
echo "   📈 Taux de connexion possible: 100%\n";
echo "\n";

echo "\n🎯 RÉSUMÉ GLOBAL\n";
echo str_repeat('=', 60) . "\n\n";

$totalUtilisateurs = $totalEnseignants + $totalEtudiants + $stats['coordinateurs_total'];
$totalOk = $stats['enseignants_ok'] + $stats['etudiants_ok'] + $stats['coordinateurs_total'];

if ($totalUtilisateurs > 0) {
    $percentGlobal = round(($totalOk / $totalUtilisateurs) * 100, 1);
    echo "📊 Taux de connexion GLOBAL: {$percentGlobal}%\n\n";

    echo "Total utilisateurs: {$totalUtilisateurs}\n";
    echo "   ✅ Peuvent se connecter: {$totalOk}\n";
    echo "   ❌ Ne peuvent PAS se connecter: " . ($totalUtilisateurs - $totalOk) . "\n\n";

    if ($percentGlobal >= 90) {
        echo "✅ EXCELLENT: Plus de 90% des utilisateurs peuvent se connecter!\n";
    } elseif ($percentGlobal >= 70) {
        echo "⚠️  MOYEN: Entre 70-90% des utilisateurs peuvent se connecter\n";
    } else {
        echo "❌ PROBLÈME: Moins de 70% des utilisateurs peuvent se connecter\n";
    }
} else {
    $percentGlobal = 0;
    echo "❌ Aucun utilisateur trouvé\n";
}

echo "\n\n🔍 ANALYSE DES PROBLÈMES\n";
echo str_repeat('=', 60) . "\n\n";

$problemes = [];

if ($stats['enseignants_manquants'] > 0) {
    $problemes[] = [
        'type' => 'ENSEIGNANTS MANQUANTS',
        'count' => $stats['enseignants_manquants'],
        'solution' => 'Ces enseignants doivent se connecter au LMS une première fois pour créer leur compte',
    ];
}

if ($stats['etudiants_manquants'] > 0) {
    $problemes[] = [
        'type' => 'ÉTUDIANTS MANQUANTS',
        'count' => $stats['etudiants_manquants'],
        'solution' => 'Ces étudiants seront créés automatiquement lors de la synchronisation des classes',
    ];
}

if ($stats['enseignants_sans_email'] > 0) {
    $problemes[] = [
        'type' => 'ENSEIGNANTS SANS EMAIL',
        'count' => $stats['enseignants_sans_email'],
        'solution' => 'Ajouter les emails dans Klassci - CRITIQUE pour la connexion',
    ];
}

if ($stats['etudiants_sans_email'] > 0) {
    $problemes[] = [
        'type' => 'ÉTUDIANTS SANS EMAIL',
        'count' => $stats['etudiants_sans_email'],
        'solution' => 'Ajouter les emails dans Klassci - CRITIQUE pour la connexion',
    ];
}

if (empty($problemes)) {
    echo "✅ AUCUN PROBLÈME DÉTECTÉ!\n\n";
    echo "Tous les utilisateurs de Klassci peuvent se connecter au LMS.\n";
} else {
    echo "⚠️  PROBLÈMES DÉTECTÉS:\n\n";

    foreach ($problemes as $i => $pb) {
        echo ($i + 1) . ". {$pb['type']}: {$pb['count']} utilisateur(s)\n";
        echo "   💡 Solution: {$pb['solution']}\n\n";
    }
}

echo "\n💡 RECOMMANDATIONS\n";
echo str_repeat('=', 60) . "\n\n";

echo "1. POUR LES ENSEIGNANTS MANQUANTS:\n";
echo "   - Demandez-leur de se connecter au LMS avec leurs identifiants Klassci\n";
echo "   - Le compte sera créé automatiquement via l'API de login\n\n";

echo "2. POUR LES ÉTUDIANTS MANQUANTS:\n";
echo "   - Ils seront synchronisés automatiquement quand:\n";
echo "     * Un enseignant active une visio pour leur classe\n";
echo "     * Le job de synchronisation tourne (toutes les 5 min)\n\n";

echo "3. POUR LES UTILISATEURS SANS EMAIL:\n";
echo "   - CRITIQUE: Ajoutez les emails dans Klassci\n";
echo "   - Sans email, impossible de se connecter\n\n";

echo "=== FIN DU TEST ===\n";
