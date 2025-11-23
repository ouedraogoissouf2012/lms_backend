<?php

$file = '../lms-frontend/src/views/TeacherSeances.vue';

if (!file_exists($file)) {
    die("❌ Fichier non trouvé\n");
}

echo "📝 Application du fix heure de sortie...\n\n";

$content = file_get_contents($file);

// 1. Ajouter visioWindow dans les refs
$search1 = "const actionLoading = ref(null)";
$replace1 = "const actionLoading = ref(null)\nconst visioWindow = ref(null)";

if (strpos($content, 'const visioWindow') === false) {
    $content = str_replace($search1, $replace1, $content);
    echo "✅ Ajout de visioWindow\n";
} else {
    echo "✅ visioWindow déjà présent\n";
}

// 2. Remplacer le lien <a> par un bouton
$search2 = '<a
                      :href="`https://meet.jit.si/${seance.visio.room_id}`"
                      target="_blank"
                      class="btn-action btn-success"
                    >
                      <span class="btn-icon">◉</span>
                      Rejoindre
                    </a>';

$replace2 = '<button
                      @click="handleJoinVisio(seance)"
                      class="btn-action btn-success"
                    >
                      <span class="btn-icon">◉</span>
                      Rejoindre
                    </button>';

if (strpos($content, 'handleJoinVisio') === false) {
    $content = str_replace($search2, $replace2, $content);
    echo "✅ Remplacement du lien par bouton\n";
} else {
    echo "✅ Bouton déjà présent\n";
}

// 3. Ajouter les 3 méthodes avant handleEndVisio
$methodsToAdd = "
async function handleJoinVisio(seance) {
  try {
    console.log('[VISIO] Rejoindre visio:', seance.id)

    // Appeler l'API join pour enregistrer l'entrée
    await lmsService.joinVisio(seance.id)

    // Ouvrir Jitsi et stocker la référence
    const roomId = seance.visio.room_id
    visioWindow.value = window.open(`https://meet.jit.si/\${roomId}`, '_blank')

    // Surveiller la fermeture
    watchVisioWindow(seance.id)

    console.log('[VISIO] Fenêtre ouverte et surveillance activée')
  } catch (error) {
    console.error('[ERREUR] Join visio:', error)
  }
}

function watchVisioWindow(seanceId) {
  if (!visioWindow.value) return

  const checkClosed = setInterval(() => {
    if (visioWindow.value.closed) {
      clearInterval(checkClosed)
      console.log('🚪 Fenêtre Jitsi fermée')
      leaveVisio(seanceId)
      visioWindow.value = null
    }
  }, 1000)
}

async function leaveVisio(seanceId) {
  try {
    await lmsService.leaveVisio(seanceId)
    console.log('👋 Sortie visio enregistrée')
  } catch (error) {
    console.error('❌ Erreur leave visio:', error)
  }
}

";

if (strpos($content, 'function handleJoinVisio') === false) {
    // Trouver handleEndVisio
    $pos = strpos($content, 'async function handleEndVisio');
    if ($pos !== false) {
        $content = substr_replace($content, $methodsToAdd, $pos, 0);
        echo "✅ Ajout des 3 méthodes\n";
    } else {
        echo "❌ handleEndVisio non trouvé\n";
    }
} else {
    echo "✅ Méthodes déjà présentes\n";
}

file_put_contents($file, $content);

echo "\n✅ FIX APPLIQUÉ AVEC SUCCÈS!\n\n";
echo "Rechargez le navigateur et testez!\n";
