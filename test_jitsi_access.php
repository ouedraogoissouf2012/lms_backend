<?php

/**
 * Script de diagnostic Jitsi
 * Teste l'accessibilité de meet.jit.si
 */

echo "═══════════════════════════════════════════════════════════════\n";
echo "   DIAGNOSTIC JITSI MEET\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// Test 1: Vérifier connexion à meet.jit.si
echo "🔍 Test 1: Vérification de meet.jit.si...\n";

$ch = curl_init('https://meet.jit.si');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Pour Windows local

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($httpCode === 200) {
    echo "   ✅ meet.jit.si accessible (HTTP $httpCode)\n\n";
} else {
    echo "   ❌ meet.jit.si inaccessible\n";
    echo "   HTTP Code: $httpCode\n";
    if ($error) {
        echo "   Erreur: $error\n";
    }
    echo "\n   🔧 SOLUTION: Vérifier votre connexion Internet\n\n";
}

// Test 2: Créer une salle test
echo "🔍 Test 2: Test de salle Jitsi...\n";

$testRoom = 'lms-test-' . time();
$testUrl = "https://meet.jit.si/$testRoom";

echo "   🔗 URL de test: $testUrl\n";
echo "   📝 Ouvrez cette URL dans votre navigateur pour tester\n\n";

// Test 3: Vérifier la configuration PHP
echo "🔍 Test 3: Configuration PHP...\n";

if (function_exists('curl_init')) {
    echo "   ✅ cURL activé\n";
} else {
    echo "   ❌ cURL désactivé (requis pour API)\n";
}

if (ini_get('allow_url_fopen')) {
    echo "   ✅ allow_url_fopen activé\n";
} else {
    echo "   ⚠️  allow_url_fopen désactivé\n";
}

echo "\n";

echo "═══════════════════════════════════════════════════════════════\n";
echo "   INSTRUCTIONS DE TEST NAVIGATEUR\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

echo "1. Ouvrez votre navigateur\n";
echo "2. Appuyez sur F12 pour ouvrir la Console\n";
echo "3. Allez sur: $testUrl\n\n";

echo "4. Vérifiez:\n";
echo "   • La page Jitsi s'affiche ? ✅/❌\n";
echo "   • Erreurs dans la Console ? ✅/❌\n";
echo "   • Demande d'autorisation caméra/micro ? ✅/❌\n\n";

echo "═══════════════════════════════════════════════════════════════\n";
echo "   PROBLÈMES FRÉQUENTS\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

echo "❌ POPUP BLOQUÉE\n";
echo "   → Dans l'interface LMS, vérifiez l'icône à droite de la barre d'adresse\n";
echo "   → Clic droit → Autoriser popups pour localhost:5174\n\n";

echo "❌ JAVASCRIPT DÉSACTIVÉ\n";
echo "   → Navigateur → Paramètres → Confidentialité\n";
echo "   → Activer JavaScript\n\n";

echo "❌ ERREUR 'window.open returned null'\n";
echo "   → C'est une popup bloquée\n";
echo "   → Autoriser les popups pour localhost\n\n";

echo "❌ FIREWALL/ANTIVIRUS\n";
echo "   → Certains antivirus bloquent Jitsi\n";
echo "   → Désactiver temporairement pour tester\n\n";

echo "═══════════════════════════════════════════════════════════════\n";
