<?php

/**
 * DIAGNOSTIC APPROFONDI - Pourquoi les enseignants n'apparaissent pas
 */

$token = '13|b9DLXrfhWnHdqYx3da3GbjRKoYvzH89Vs5xt8Jrdfc0ecc2a';
$baseUrl = 'http://localhost:8000/api';

function makeRequest($endpoint, $token, $baseUrl) {
    $ch = curl_init($baseUrl . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'response' => json_decode($response, true),
        'raw' => $response
    ];
}

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  DIAGNOSTIC APPROFONDI - ENSEIGNANTS DES MATIÈRES                ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

// ÉTAPE 1: Voir toutes les matières disponibles
echo "┌─ ÉTAPE 1: Toutes les matières (via /proxy/matieres) ─────────────┐\n\n";

$allMatieres = makeRequest('/proxy/matieres', $token, $baseUrl);

if ($allMatieres['http_code'] === 200) {
    $matieres = $allMatieres['response']['data'] ?? [];
    echo "✅ " . count($matieres) . " matières trouvées\n\n";

    foreach ($matieres as $matiere) {
        echo "📚 Matière: {$matiere['nom']} (ID: {$matiere['id']})\n";
        echo "   Code: {$matiere['code']}\n";

        if (isset($matiere['enseignants'])) {
            $nbEns = count($matiere['enseignants']);
            echo "   Enseignants dans /proxy/matieres: {$nbEns}\n";

            if ($nbEns > 0) {
                foreach ($matiere['enseignants'] as $ens) {
                    echo "      → " . ($ens['nom'] ?? $ens['name'] ?? 'N/A') .
                         " (ID: " . ($ens['id'] ?? 'N/A') . ")\n";
                }
            } else {
                echo "      ⚠️  Tableau vide\n";
            }
        } else {
            echo "   ❌ Champ 'enseignants' absent\n";
        }
        echo "\n";
    }
} else {
    echo "❌ Erreur HTTP " . $allMatieres['http_code'] . "\n\n";
}

echo "└───────────────────────────────────────────────────────────────────┘\n\n";

// ÉTAPE 2: Tester l'endpoint /matieres/{id}/enseignants pour chaque matière
echo "┌─ ÉTAPE 2: Test /proxy/matieres/{id}/enseignants ─────────────────┐\n\n";

$matiereIds = [1, 2, 3]; // Marketing digital, Algorithme, Anglais

foreach ($matiereIds as $id) {
    echo "🔍 Test /proxy/matieres/{$id}/enseignants\n";

    $ensResult = makeRequest("/proxy/matieres/{$id}/enseignants", $token, $baseUrl);

    echo "   HTTP: {$ensResult['http_code']}\n";

    if ($ensResult['http_code'] === 200) {
        if (isset($ensResult['response']['data'])) {
            $enseignants = $ensResult['response']['data'];
            echo "   Nombre: " . (is_array($enseignants) ? count($enseignants) : 'Non array') . "\n";

            if (is_array($enseignants) && count($enseignants) > 0) {
                echo "   ✅ Enseignants trouvés:\n";
                foreach ($enseignants as $ens) {
                    echo "      → " . ($ens['nom'] ?? $ens['name'] ?? json_encode($ens)) . "\n";
                }
            } else {
                echo "   ⚠️  Tableau vide ou null\n";
            }
        } else {
            echo "   Structure: " . json_encode(array_keys($ensResult['response'] ?? [])) . "\n";
        }
    } else if ($ensResult['http_code'] === 404) {
        echo "   ❌ Endpoint n'existe pas (404)\n";
    } else {
        echo "   ❌ Erreur\n";
        if (isset($ensResult['response']['message'])) {
            echo "   Message: " . $ensResult['response']['message'] . "\n";
        }
    }
    echo "\n";
}

echo "└───────────────────────────────────────────────────────────────────┘\n\n";

// ÉTAPE 3: Vérifier ce que retourne /lms/classes/1
echo "┌─ ÉTAPE 3: Vérifier /lms/classes/1 (notre API enrichie) ──────────┐\n\n";

$classeResult = makeRequest('/lms/classes/1', $token, $baseUrl);

if ($classeResult['http_code'] === 200) {
    $data = $classeResult['response']['data'];
    $matieres = $data['matieres'] ?? [];

    echo "✅ API fonctionne\n";
    echo "Nombre de matières: " . count($matieres) . "\n\n";

    foreach ($matieres as $matiere) {
        echo "📚 Matière: {$matiere['nom']}\n";

        if (isset($matiere['enseignants'])) {
            $nbEns = is_array($matiere['enseignants']) ? count($matiere['enseignants']) : 0;
            echo "   Enseignants: {$nbEns}\n";

            if ($nbEns > 0) {
                foreach ($matiere['enseignants'] as $ens) {
                    echo "      → " . ($ens['nom'] ?? $ens['name'] ?? json_encode($ens)) . "\n";
                }
            } else {
                echo "      ⚠️  VIDE - C'est ici le problème!\n";
            }
        } else {
            echo "   ❌ Champ 'enseignants' absent\n";
        }
        echo "\n";
    }
} else {
    echo "❌ Erreur HTTP " . $classeResult['http_code'] . "\n";
}

echo "└───────────────────────────────────────────────────────────────────┘\n\n";

// ÉTAPE 4: Vérifier l'API KlassCI directement
echo "┌─ ÉTAPE 4: API KlassCI directe (si token disponible) ─────────────┐\n\n";

// Essayer de récupérer le token KlassCI depuis la DB
try {
    require_once __DIR__ . '/vendor/autoload.php';

    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    $pdo = new PDO(
        'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_DATABASE'],
        $_ENV['DB_USERNAME'],
        $_ENV['DB_PASSWORD']
    );

    $stmt = $pdo->query("SELECT klassci_token FROM users WHERE id = 2 LIMIT 1");
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && $user['klassci_token']) {
        $klassciToken = $user['klassci_token'];
        echo "✅ Token KlassCI récupéré\n\n";

        // Test direct sur API KlassCI
        $klassciUrl = 'https://api-klassci.devsmatflow.com/api';

        echo "Test: GET {$klassciUrl}/matieres/1/enseignants\n";

        $ch = curl_init($klassciUrl . '/matieres/1/enseignants');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $klassciToken,
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        echo "HTTP: {$httpCode}\n";

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            echo "Réponse:\n";
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        } else if ($httpCode === 404) {
            echo "❌ Endpoint /matieres/{id}/enseignants n'existe pas dans KlassCI!\n";
        } else {
            echo "Réponse brute: " . substr($response, 0, 500) . "\n";
        }

    } else {
        echo "⚠️  Token KlassCI non trouvé dans la DB\n";
    }

} catch (Exception $e) {
    echo "⚠️  Impossible de tester l'API KlassCI: " . $e->getMessage() . "\n";
}

echo "\n└───────────────────────────────────────────────────────────────────┘\n\n";

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  FIN DU DIAGNOSTIC                                               ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
