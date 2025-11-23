<?php

/**
 * PATCH: Ajouter tracking de sortie visio pour étudiants
 *
 * Modifications à faire dans SeanceDetails.vue:
 * 1. Ajouter visioWindow dans data()
 * 2. Modifier joinVisio() pour stocker la référence de la fenêtre
 * 3. Ajouter watchVisioWindow() pour surveiller la fermeture
 * 4. Appeler leaveVisio() à la fermeture
 */

echo "═══════════════════════════════════════════════════════════════════\n";
echo "PATCH: VISIO TRACKING - ENREGISTREMENT HEURE DE SORTIE\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$frontendPath = "C:/Users/USER PC/Documents/propre à moi/lms-frontend";
$file = "$frontendPath/src/views/seances/SeanceDetails.vue";

if (!file_exists($file)) {
    die("❌ Fichier non trouvé: $file\n");
}

$content = file_get_contents($file);

echo "📄 Fichier: SeanceDetails.vue\n\n";

// Vérification 1: visioWindow existe déjà?
if (strpos($content, 'visioWindow:') !== false) {
    echo "✅ visioWindow déjà présent dans data()\n";
} else {
    echo "❌ visioWindow manquant - À ajouter manuellement\n";
    echo "   Ajouter dans data(): visioWindow: null\n\n";
}

// Vérification 2: leaveVisio method existe?
if (strpos($content, 'async leaveVisio()') !== false || strpos($content, 'leaveVisio()') !== false) {
    echo "✅ Méthode leaveVisio() trouvée\n";
} else {
    echo "❌ Méthode leaveVisio() manquante - À créer\n\n";
}

// Vérification 3: window.open existe?
if (strpos($content, 'window.open(link') !== false) {
    echo "✅ window.open(link, '_blank') trouvé\n";
    echo "   ⚠️  DOIT ÊTRE MODIFIÉ EN: this.visioWindow = window.open(link, '_blank')\n\n";
} else {
    echo "⚠️  window.open() non trouvé - vérifier le code\n\n";
}

echo "═══════════════════════════════════════════════════════════════════\n";
echo "CODE À AJOUTER/MODIFIER:\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

echo "1. Dans data() - Ajouter:\n";
echo "```javascript\n";
echo "visioWindow: null // Référence à la fenêtre Jitsi ouverte\n";
echo "```\n\n";

echo "2. Dans joinVisio() - Modifier:\n";
echo "```javascript\n";
echo "// AVANT:\n";
echo "window.open(link, '_blank')\n\n";
echo "// APRÈS:\n";
echo "this.visioWindow = window.open(link, '_blank')\n";
echo "this.watchVisioWindow() // Surveiller la fermeture\n";
echo "```\n\n";

echo "3. Ajouter nouvelle méthode watchVisioWindow():\n";
echo "```javascript\n";
echo "watchVisioWindow() {\n";
echo "  if (!this.visioWindow) return\n\n";
echo "  const checkClosed = setInterval(() => {\n";
echo "    if (this.visioWindow.closed) {\n";
echo "      clearInterval(checkClosed)\n";
echo "      console.log('🚪 Fenêtre Jitsi fermée, enregistrement sortie')\n";
echo "      this.leaveVisio()\n";
echo "      this.visioWindow = null\n";
echo "    }\n";
echo "  }, 1000) // Vérifier toutes les secondes\n";
echo "}\n";
echo "```\n\n";

echo "4. Ajouter nouvelle méthode leaveVisio():\n";
echo "```javascript\n";
echo "async leaveVisio() {\n";
echo "  try {\n";
echo "    const response = await lmsService.leaveVisio(this.seanceId)\n";
echo "    console.log('👋 Sortie de visio enregistrée:', response)\n";
echo "  } catch (error) {\n";
echo "    console.error('❌ Erreur leave visio:', error)\n";
echo "  }\n";
echo "}\n";
echo "```\n\n";

echo "═══════════════════════════════════════════════════════════════════\n";
echo "INSTRUCTIONS MANUELLES:\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

echo "Ouvrir: $file\n\n";

echo "1. Trouver data() et ajouter visioWindow: null\n";
echo "2. Trouver joinVisio() et modifier window.open\n";
echo "3. Ajouter les deux nouvelles méthodes dans la section methods\n\n";

echo "✅ Une fois modifié, les étudiants auront leur heure de sortie enregistrée!\n\n";
