# ✅ CORRECTION : Heartbeat persiste lors de la navigation

## 🎯 Problème résolu

**Avant** : Quand un utilisateur naviguait entre les pages pendant une séance (ex: emploi du temps → Dashboard), le Worker de heartbeat s'arrêtait et l'utilisateur était marqué comme déconnecté.

**Après** : Le Worker de heartbeat **persiste** lors de la navigation et continue d'envoyer des heartbeats toutes les 30 secondes.

---

## 📦 Fichiers créés

### 1. Store Pinia global : `src/stores/visio.js`

**Nouveau fichier** contenant toute la logique de participation aux visioconférences.

**Points clés** :
- ✅ État global persistant (`activeSeanceId`, `isInVisio`, `heartbeatWorker`)
- ✅ Worker créé au niveau du store (ne se détruit pas)
- ✅ Actions : `joinVisio()`, `leaveVisio()`, `sendHeartbeat()`
- ✅ Listeners globaux : `visibilitychange`, `beforeunload`

**Architecture** :
```javascript
export const useVisioStore = defineStore('visio', () => {
  // États
  const activeSeanceId = ref(null)
  const isInVisio = ref(false)
  const heartbeatWorker = ref(null)

  // Actions
  const joinVisio = async (seanceId, jitsiLink) => { /* ... */ }
  const leaveVisio = async () => { /* ... */ }
  const sendHeartbeat = async () => { /* ... */ }

  return { activeSeanceId, isInVisio, joinVisio, leaveVisio, sendHeartbeat }
})
```

---

## 🔧 Fichiers modifiés

### 1. `src/components/visio/VisioManager.vue`

**Changements** :

#### Avant (utilisation du composable)
```javascript
import { useVisioParticipation } from '@/composables/useVisioParticipation'

setup(props) {
  const visioParticipation = useVisioParticipation(props.seance.id)
  return { ...visioParticipation }
}

// Dans les méthodes
await this.joinVisio(jitsiLink)
```

#### Après (utilisation du store)
```javascript
import { useVisioStore } from '@/stores/visio'

setup() {
  const visioStore = useVisioStore()
  return { visioStore }
}

// Dans les méthodes
await this.visioStore.joinVisio(this.seance.id, jitsiLink)
```

**Lignes modifiées** :
- Ligne 117 : Import du store au lieu du composable
- Lignes 130-138 : Setup() utilise le store
- Ligne 310 : `visioStore.joinVisio()` au lieu de `this.joinVisio()`
- Ligne 348 : Idem pour les étudiants
- Lignes 226-227 : Nouveau commentaire expliquant que le store gère le cleanup

---

## 🎯 Différence clé : Composable vs Store

### ❌ Avant (Composable)

```
Page MatiereDetails.vue
  └─> Composant VisioManager.vue (mounted)
        └─> useVisioParticipation(seanceId) créé
              └─> heartbeatWorker créé

[User navigue vers Dashboard]

Page Dashboard.vue (MatiereDetails démonté)
  └─> VisioManager.vue (unmounted) ❌
        └─> onBeforeUnmount() appelé ❌
              └─> cleanup() appelé ❌
                    └─> stopHeartbeat() ❌
                    └─> leaveVisio() ❌
```

**Résultat** : Worker terminé, heartbeats arrêtés, user déconnecté

### ✅ Après (Store Pinia)

```
Application (main.js)
  └─> Store Pinia global (créé une fois)
        └─> useVisioStore
              └─> heartbeatWorker (persiste)

Page MatiereDetails.vue
  └─> VisioManager.vue utilise le store

[User navigue vers Dashboard]

Page Dashboard.vue
  └─> Store toujours actif ✅
        └─> heartbeatWorker continue ✅
              └─> Heartbeats envoyés toutes les 30s ✅
```

**Résultat** : Worker actif, heartbeats continus, user reste connecté

---

## 🧪 Comment tester

### Test 1 : Navigation basique

1. **Marcel rejoint une séance** (page emploi du temps)
   - Vérifier dans la console : `[VisioStore] 💓 Worker démarré`
   - Vérifier dans la console : `[VisioStore] 💓 Heartbeat envoyé (séance X)`

2. **Marcel navigue vers Dashboard**
   - Cliquer sur "Dashboard" dans le menu

3. **Attendre 30 secondes**
   - Vérifier dans la console : Les heartbeats continuent !
   - `[VisioStore] 💓 Heartbeat envoyé (séance X)` toutes les 30s

4. **Vérifier côté backend**
   ```bash
   php check_marcel_status.php
   ```
   - Status : `connected` ✅
   - `last_seen_at` : Mis à jour toutes les 30s ✅

### Test 2 : Navigation multiple

1. Marcel rejoint une séance
2. Marcel navigue : Emploi du temps → Dashboard → Mes notes → Emploi du temps
3. Vérifier que les heartbeats continuent sans interruption

### Test 3 : Fermeture réelle

1. Marcel rejoint une séance
2. Marcel **ferme l'onglet du navigateur** (ou ferme le navigateur)
3. Vérifier côté backend :
   ```bash
   php check_marcel_status.php
   ```
   - Status : `disconnected` ✅ (le Beacon a envoyé la déconnexion)

---

## 📊 Console logs attendus

### Démarrage de la séance
```
[VisioStore] 💓 Worker démarré
[VisioStore] 💓 Heartbeat envoyé (séance 35)
```

### Pendant la navigation (toutes les 30s)
```
[VisioStore] 💓 Heartbeat envoyé (séance 35)
[VisioStore] 💓 Heartbeat envoyé (séance 35)
[VisioStore] 💓 Heartbeat envoyé (séance 35)
...
```

### Retour sur l'onglet (après inactivité)
```
[VisioStore] 👁️ Retour sur onglet, heartbeat immédiat
[VisioStore] 💓 Heartbeat envoyé (séance 35)
```

### Fermeture de la fenêtre Jitsi
```
[VisioStore] 🚪 Fenêtre Jitsi fermée
[VisioStore] Quitter séance 35
[VisioStore] 💔 Worker terminé
[VisioStore] 📡 Beacon envoyé pour leaveVisio
[VisioStore] ✅ Sortie enregistrée
```

---

## ⚠️ Points d'attention

### 1. Le composable `useVisioParticipation.js` est obsolète

**Fichier** : `src/composables/useVisioParticipation.js`

**Action** : Ce fichier peut être **supprimé** ou **archivé** car il n'est plus utilisé.

### 2. Pinia déjà configuré

Pinia est déjà configuré dans `main.js` (ligne 36), pas de configuration supplémentaire nécessaire.

### 3. Nettoyage manuel toujours possible

Si besoin de quitter manuellement la visio (pour débug) :

```javascript
// Dans la console du navigateur
const visioStore = useVisioStore()
visioStore.leaveVisio()
```

---

## 🎉 Bénéfices de cette correction

1. ✅ **Navigation fluide** : Les utilisateurs peuvent changer de page sans être déconnectés
2. ✅ **Statistiques précises** : Les durées de participation sont correctes
3. ✅ **UX améliorée** : Pas besoin de rester sur la page de la visio
4. ✅ **Backend cohérent** : Les règles de fermeture automatique fonctionnent correctement
5. ✅ **Code maintenable** : Store centralisé, pattern Vue recommandé

---

## 📝 Build et déploiement

### Développement (local)

```bash
cd lms-frontend
npm run dev
```

### Production (build)

```bash
cd lms-frontend
npm run build
```

Les fichiers buildés seront dans `dist/`.

---

## 🔍 Vérification rapide

Pour vérifier que la correction fonctionne :

```bash
# Terminal 1 : Backend
cd lms-backend
php artisan schedule:work

# Terminal 2 : Frontend
cd lms-frontend
npm run dev

# Terminal 3 : Monitoring
cd lms-backend
watch -n 5 "php check_marcel_status.php"
```

---

## 📚 Références

- **Store Pinia** : `src/stores/visio.js`
- **Composant mis à jour** : `src/components/visio/VisioManager.vue`
- **Diagnostic complet** : `DIAGNOSTIC_HEARTBEAT_PROBLEM.md`
- **Jobs backend** :
  - `app/Jobs/DetectDisconnectedParticipants.php` (détection inactivité 5 min)
  - `app/Jobs/AutoCloseEmptySeances.php` (fermeture automatique)
