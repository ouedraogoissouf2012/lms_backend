# 🔍 DIAGNOSTIC : Arrêt des heartbeats lors de la navigation

## ❌ Problème identifié

Quand un utilisateur navigue entre les pages de l'application pendant une séance (ex: emploi du temps → Dashboard), **le Worker de heartbeat s'arrête** et l'utilisateur est marqué comme déconnecté.

## 🎯 Cause racine

### Architecture actuelle (PROBLÉMATIQUE)

```
MatiereDetails.vue (page emploi du temps)
  └─> VisioManager.vue (composant)
        └─> useVisioParticipation(seanceId) (composable)
              └─> heartbeatWorker (Web Worker)
```

**Le problème** : Le composable est créé au niveau d'un composant de page

### Séquence du bug

1. **Marcel ouvre la séance** dans `MatiereDetails.vue`
   - `VisioManager` est monté
   - `useVisioParticipation` est créé
   - Le Worker démarre et envoie des heartbeats

2. **Marcel navigue vers Dashboard** (`/dashboard`)
   - Vue Router change de route
   - `MatiereDetails.vue` est démonté
   - `VisioManager` est démonté ❌
   - **`onBeforeUnmount()` est appelé** (ligne 285 de useVisioParticipation.js)
   - **`cleanup()` est exécuté** qui :
     - Arrête le Worker (ligne 276)
     - Appelle `leaveVisio()` (ligne 279-281)
     - Envoie un Beacon au backend pour quitter la séance

3. **Backend reçoit leaveVisio**
   - Marque Marcel comme `disconnected`
   - Set `left_at` timestamp

4. **DetectDisconnectedParticipants s'exécute**
   - Voit que Marcel n'a plus de heartbeat depuis 5+ minutes
   - Confirme la déconnexion

## 📋 Code problématique

### `src/composables/useVisioParticipation.js` ligne 285-287

```javascript
// Nettoyage automatique au démontage du composant Vue
onBeforeUnmount(() => {
  cleanup()  // ❌ PROBLÈME : Arrête le Worker quand le composant est démonté
})
```

### `cleanup()` fonction ligne 275-282

```javascript
const cleanup = () => {
  stopHeartbeat()  // ❌ Arrête le Worker
  document.removeEventListener('visibilitychange', handleVisibilityChange)

  if (isInVisio.value) {
    leaveVisio()  // ❌ Envoie une requête pour quitter la séance
  }
}
```

## ✅ Ce qui devrait se passer

Le Worker de heartbeat devrait :
- ✅ Être créé au niveau **global de l'application**
- ✅ **Persister** pendant toute la session de visio
- ✅ **Ne PAS être détruit** lors de la navigation entre pages
- ✅ Continuer d'envoyer des heartbeats même si l'utilisateur change de page

## 🔧 Solutions possibles

### Solution 1 : Store Pinia global (RECOMMANDÉ)

Créer un store Pinia `useVisioStore` pour gérer la participation :

```javascript
// src/stores/visio.js
import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useVisioStore = defineStore('visio', () => {
  const activeSeanceId = ref(null)
  const heartbeatWorker = ref(null)
  const isInVisio = ref(false)

  // Méthodes globales pour join/leave/heartbeat
  // Le Worker persiste au niveau du store, pas du composant

  return {
    activeSeanceId,
    isInVisio,
    joinVisio,
    leaveVisio,
    // ...
  }
})
```

**Avantages** :
- ✅ État global persistant
- ✅ Worker global (ne se détruit pas)
- ✅ Accessible depuis n'importe quel composant
- ✅ Pattern Vue recommandé

### Solution 2 : Plugin Vue global

Créer un plugin Vue qui gère la visio au niveau de l'application :

```javascript
// src/plugins/visioPlugin.js
export default {
  install(app) {
    // Créer une instance globale de gestion visio
    const visioManager = new VisioGlobalManager()
    app.config.globalProperties.$visio = visioManager
    app.provide('visio', visioManager)
  }
}
```

### Solution 3 : Composable persistant avec provide/inject

Créer le composable au niveau de `App.vue` et le fournir à tous les enfants :

```javascript
// App.vue
setup() {
  const visioParticipation = useVisioParticipation()
  provide('visioParticipation', visioParticipation)
}

// Dans les composants enfants
const visioParticipation = inject('visioParticipation')
```

## 🎯 Recommandation finale

**Solution 1 (Store Pinia)** est la meilleure option car :
- Pattern officiellement recommandé par Vue
- Réactivité native
- Facile à tester
- Déjà utilisé dans le projet pour d'autres états globaux

## 📝 Checklist d'implémentation

1. [ ] Créer `src/stores/visio.js` avec le store Pinia
2. [ ] Migrer la logique de `useVisioParticipation.js` vers le store
3. [ ] **RETIRER `onBeforeUnmount()`** du store (ne plus cleanup automatiquement)
4. [ ] Ajouter un cleanup manuel uniquement sur `beforeunload` (fermeture vraie)
5. [ ] Mettre à jour `VisioManager.vue` pour utiliser le store
6. [ ] Tester la navigation pendant une séance active
7. [ ] Vérifier que les heartbeats continuent après navigation

## 🔍 Vérification après correction

Pour tester que le bug est corrigé :

1. Marcel rejoint une séance
2. Marcel navigue vers Dashboard (ou autre page)
3. **Attendu** : Heartbeats continuent d'être envoyés toutes les 30 secondes
4. **Vérification backend** :
   ```php
   php check_marcel_status.php
   ```
   - Status doit rester `connected`
   - `last_seen_at` doit s'actualiser toutes les 30s

## 📊 Impact

**Utilisateurs affectés** : TOUS les participants qui naviguent pendant une séance

**Gravité** : 🔴 **CRITIQUE**
- Les participants sont déconnectés alors qu'ils sont toujours dans la visio
- Les statistiques de présence sont fausses
- Les séances peuvent être fermées prématurément (Règle 1 ou 2)

**Urgence** : **HAUTE** - À corriger avant mise en production
