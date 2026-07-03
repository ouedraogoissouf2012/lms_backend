# FIX FINAL - HEURE DE SORTIE VISIO

## LE PROBLÈME

Vous testez avec le **COORDINATEUR** mais le code a été ajouté dans `SeanceDetails.vue` qui est pour les **ÉTUDIANTS**.

Le coordinateur utilise `TeacherSeances.vue` qui a un **lien direct** vers Jitsi, donc `leaveVisio()` n'est jamais appelé!

---

## SOLUTION RAPIDE

### Fichier: `lms-frontend/src/views/TeacherSeances.vue`

**Ligne 270-277**, REMPLACER:

```vue
<a
  :href="`https://meet.jit.si/${seance.visio.room_id}`"
  target="_blank"
  class="btn-action btn-success"
>
  <span class="btn-icon">◉</span>
  Rejoindre
</a>
```

PAR:

```vue
<button
  @click="handleJoinVisio(seance)"
  class="btn-action btn-success"
>
  <span class="btn-icon">◉</span>
  Rejoindre
</button>
```

---

### Ajouter ces 3 méthodes dans `methods` (après `handleEndVisio`):

```javascript
async handleJoinVisio(seance) {
  try {
    // Appeler l'API join pour enregistrer l'entrée
    await lmsService.joinVisio(seance.id)

    // Ouvrir Jitsi et stocker la référence
    const roomId = seance.visio.room_id
    this.visioWindow = window.open(`https://meet.jit.si/${roomId}`, '_blank')

    // Surveiller la fermeture
    this.watchVisioWindow(seance.id)
  } catch (error) {
    console.error('Erreur join visio:', error)
  }
},

watchVisioWindow(seanceId) {
  if (!this.visioWindow) return

  const checkClosed = setInterval(() => {
    if (this.visioWindow.closed) {
      clearInterval(checkClosed)
      console.log('🚪 Fenêtre Jitsi fermée')
      this.leaveVisio(seanceId)
      this.visioWindow = null
    }
  }, 1000)
},

async leaveVisio(seanceId) {
  try {
    await lmsService.leaveVisio(seanceId)
    console.log('👋 Sortie enregistrée')
  } catch (error) {
    console.error('❌ Erreur leave:', error)
  }
},
```

---

### Ajouter dans `data()`:

```javascript
data() {
  return {
    // ... autres variables ...
    visioWindow: null  // AJOUTER CETTE LIGNE
  }
}
```

---

## C'EST TOUT!

Maintenant:
1. Rechargez le navigateur
2. Coordinateur rejoint la visio
3. Fermez Jitsi
4. L'heure de sortie sera enregistrée! ✅
