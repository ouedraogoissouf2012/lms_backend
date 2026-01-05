# 🧪 GUIDE COMPLET DES TESTS

Guide pour tester la correction du bug de heartbeat lors de la navigation.

---

## 📋 Table des matières

1. [Préparation](#préparation)
2. [Test Frontend (Interface visuelle)](#test-frontend-interface-visuelle)
3. [Test Backend (Monitoring)](#test-backend-monitoring)
4. [Test Intégration (Frontend + Backend)](#test-intégration-frontend--backend)
5. [Validation finale](#validation-finale)

---

## Préparation

### 1. Vérifier que tout est à jour

**Frontend** :
```bash
cd "C:\Users\USER PC\Documents\propre à moi\lms-frontend"
npm install
```

**Backend** :
```bash
cd "C:\Users\USER PC\Documents\propre à moi\lms-backend"
composer install
```

### 2. Vérifier les fichiers créés

**Frontend** :
- ✅ `src/stores/visio.js` (Store Pinia)
- ✅ `src/components/test/VisioStoreTest.vue` (Tests unitaires)

**Backend** :
- ✅ `app/Jobs/AutoCloseEmptySeances.php` (Fermeture automatique)
- ✅ `watch_heartbeats.php` (Monitoring temps réel)
- ✅ `test_heartbeat_persistence.php` (Test automatisé)
- ✅ `check_marcel_status.php` (Vérification ponctuelle)

---

## Test Frontend (Interface visuelle)

### Étape 1 : Ajouter la route de test

**Fichier** : `src/router/index.js`

Ajouter cette route :

```javascript
{
  path: '/test-visio',
  name: 'TestVisio',
  component: () => import('@/components/test/VisioStoreTest.vue')
}
```

### Étape 2 : Démarrer le frontend

```bash
cd "C:\Users\USER PC\Documents\propre à moi\lms-frontend"
npm run dev
```

### Étape 3 : Accéder à la page de test

Ouvrir dans le navigateur :
```
http://localhost:5173/test-visio
```

### Étape 4 : Exécuter les tests automatisés

1. Cliquer sur le bouton **"▶️ Lancer tous les tests"**
2. Observer les résultats en temps réel
3. Vérifier que **6/6 tests passent** ✅

**Résultat attendu** :
```
Tests totaux: 6
✅ Réussis: 6
❌ Échoués: 0
⏳ En attente: 0
```

### Étape 5 : Test manuel avec vraie séance

1. **Ouvrir un nouvel onglet** : `http://localhost:5173`
2. **Se connecter** (Marcel OUEDRAOGO)
3. **Aller sur Emploi du temps**
4. **Rejoindre une séance active** (ou en créer une)
5. **Retourner sur la page de test** : `http://localhost:5173/test-visio`

**Vérifier** :
- `activeSeanceId` : affiche l'ID de la séance ✅
- `isInVisio` : affiche `true` ✅
- `Worker actif` : affiche `true` ✅

6. **Cliquer sur "💓 Vérifier heartbeat"**
   - Un heartbeat est envoyé
   - Le compteur augmente
   - Message dans les logs : `💓 Heartbeat envoyé`

7. **Cliquer sur "🧭 Simuler navigation"**
   - L'état reste `isInVisio: true` ✅
   - Aucun message `💔 Worker terminé` ✅

---

## Test Backend (Monitoring)

### Option 1 : Monitoring en temps réel (RECOMMANDÉ)

**Terminal 1** :
```bash
cd "C:\Users\USER PC\Documents\propre à moi\lms-backend"
php watch_heartbeats.php
```

**Ce qu'il affiche** :
- Séances actives
- Participants connectés
- Dernier heartbeat de chaque participant
- État en temps réel (rafraîchissement 5s)

**Utilisation** :
1. Laisser ce terminal ouvert
2. Rejoindre une séance dans le frontend
3. Observer les heartbeats arriver toutes les 30s
4. Naviguer entre les pages
5. Vérifier que les heartbeats continuent ✅

---

### Option 2 : Test automatisé (2 minutes)

**Terminal** :
```bash
cd "C:\Users\USER PC\Documents\propre à moi\lms-backend"
php test_heartbeat_persistence.php
```

**Ce qu'il fait** :
- Lance un test de 2 minutes
- Capture tous les heartbeats reçus
- Affiche un résumé à la fin
- Calcule la fréquence moyenne

**Instructions** :
1. Lancer le script
2. Rejoindre une séance dans le frontend
3. **Naviguer entre les pages** pendant 2 minutes
4. Le script affiche automatiquement les résultats

**Résultat attendu** :
```
✅ SUCCÈS : Des heartbeats ont été reçus
Fréquence moyenne: 30 secondes
✅ Fréquence correcte (25-35s) ✅
🎉 VALIDATION : Les heartbeats ont continué pendant la navigation ! ✅
```

---

### Option 3 : Vérification ponctuelle

**Terminal** :
```bash
cd "C:\Users\USER PC\Documents\propre à moi\lms-backend"
php check_marcel_status.php
```

**Ce qu'il affiche** :
- Infos utilisateur (Marcel)
- Ses 5 dernières participations
- Status, heartbeat, durée
- Analyse des causes si déconnecté

---

## Test Intégration (Frontend + Backend)

### Configuration complète

**Terminal 1 - Backend scheduler** :
```bash
cd "C:\Users\USER PC\Documents\propre à moi\lms-backend"
php artisan schedule:work
```

**Terminal 2 - Monitoring heartbeats** :
```bash
cd "C:\Users\USER PC\Documents\propre à moi\lms-backend"
php watch_heartbeats.php
```

**Terminal 3 - Frontend** :
```bash
cd "C:\Users\USER PC\Documents\propre à moi\lms-frontend"
npm run dev
```

### Scénario de test complet

#### 1. Démarrage d'une séance

1. **Ouvrir le navigateur** : `http://localhost:5173`
2. **Se connecter** comme enseignant
3. **Aller sur Emploi du temps**
4. **Démarrer une séance**

**Vérification Terminal 2** :
```
💓 NOUVEAU HEARTBEAT reçu ! ✅
```

#### 2. Test de navigation

5. **Dans le navigateur** : Ouvrir la console (F12)
6. **Naviguer vers Dashboard**
7. **Observer dans la console** :
   ```
   [VisioStore] 💓 Heartbeat envoyé (séance 35)
   ```
   ⏰ Toutes les 30 secondes

**Vérification Terminal 2** :
- Marcel reste `connected` ✅
- Heartbeats continuent d'arriver ✅

#### 3. Navigation multiple

8. **Naviguer entre plusieurs pages** :
   - Dashboard → Emploi du temps
   - Emploi du temps → Mes notes
   - Mes notes → Dashboard
   - Dashboard → Emploi du temps

**Vérification** :
- Console : Heartbeats continuent ✅
- Terminal 2 : Marcel toujours `connected` ✅
- Aucun message `💔 Worker terminé` ✅

#### 4. Onglet inactif

9. **Minimiser ou changer d'onglet** (aller sur un autre site)
10. **Attendre 1 minute**
11. **Revenir sur l'onglet**

**Vérification Console** :
```
[VisioStore] 👁️ Retour sur onglet, heartbeat immédiat
[VisioStore] 💓 Heartbeat envoyé (séance 35)
```

#### 5. Fermeture propre

12. **Retourner sur la page de la visio**
13. **Fermer la fenêtre Jitsi**

**Vérification Console** :
```
[VisioStore] 🚪 Fenêtre Jitsi fermée
[VisioStore] Quitter séance 35
[VisioStore] 💔 Worker terminé
[VisioStore] 📡 Beacon envoyé pour leaveVisio
[VisioStore] ✅ Sortie enregistrée
```

**Vérification Terminal 2** :
- Marcel passe à `disconnected` ✅
- `left_at` est renseigné ✅

---

## Validation finale

### ✅ Checklist de validation

#### Frontend

- [ ] Store Pinia créé et importé
- [ ] VisioManager utilise le store (pas le composable)
- [ ] Tests unitaires passent (6/6)
- [ ] Console affiche les heartbeats toutes les 30s
- [ ] Navigation ne stoppe pas les heartbeats
- [ ] Retour sur onglet envoie heartbeat immédiat
- [ ] Fermeture fenêtre Jitsi envoie Beacon

#### Backend

- [ ] Job `AutoCloseEmptySeances` créé
- [ ] Scheduling ajouté dans `console.php`
- [ ] Relation `attendances()` ajoutée au modèle `Seance`
- [ ] Monitoring affiche les heartbeats en temps réel
- [ ] Participants restent `connected` lors de la navigation
- [ ] `last_seen_at` se met à jour toutes les 30s
- [ ] Règle 1 (enseignant 5 min) fonctionne
- [ ] Règle 2 (tous 10 min) fonctionne
- [ ] Règle 3 (aucun 30 min) fonctionne
- [ ] Règle 4 (protection heartbeat) fonctionne

---

## Résolution de problèmes

### ❌ "Store non trouvé"

**Cause** : Pinia mal configuré

**Solution** :
```javascript
// main.js
import { createPinia } from 'pinia'
app.use(createPinia())
```

---

### ❌ Heartbeats s'arrêtent lors de la navigation

**Cause** : Ancien code (composable) toujours utilisé

**Solution** :
1. Vérifier que `VisioManager.vue` importe le store :
   ```javascript
   import { useVisioStore } from '@/stores/visio'
   ```
2. Vérifier que `joinVisio` appelle :
   ```javascript
   await this.visioStore.joinVisio(this.seance.id, jitsiLink)
   ```

---

### ⚠️ Warning "Worker non supporté"

**Cause** : Web Workers désactivés ou non supportés

**Solution** : Le fallback `setInterval` s'active automatiquement

---

### ❌ Erreur 404 sur `/heartbeat-worker.js`

**Cause** : Fichier non accessible

**Solution** : Vérifier que le fichier existe dans :
```
lms-frontend/public/heartbeat-worker.js
```

---

## Résumé des commandes

```bash
# Frontend
cd lms-frontend
npm run dev

# Backend - Scheduler
cd lms-backend
php artisan schedule:work

# Backend - Monitoring temps réel
cd lms-backend
php watch_heartbeats.php

# Backend - Test automatisé (2 min)
cd lms-backend
php test_heartbeat_persistence.php

# Backend - Vérification ponctuelle
cd lms-backend
php check_marcel_status.php
```

---

## Prochaines étapes

### Après validation des tests

1. ✅ Commit des changements
2. ✅ Build production : `npm run build`
3. ✅ Déployer sur serveur
4. ✅ Tester en production
5. ✅ Supprimer le composable obsolète : `src/composables/useVisioParticipation.js`

### Tests en production

Vérifier que :
- [ ] Les heartbeats continuent en production
- [ ] La navigation fonctionne correctement
- [ ] Les fermetures automatiques fonctionnent
- [ ] Les statistiques de présence sont exactes

---

## 📚 Documentation complète

- **Diagnostic** : `DIAGNOSTIC_HEARTBEAT_PROBLEM.md`
- **Correction** : `CORRECTION_HEARTBEAT_NAVIGATION.md`
- **Tests frontend** : `TEST_VISIO_STORE.md`
- **Ce guide** : `GUIDE_TESTS_COMPLET.md`

---

## 🎉 Conclusion

Si tous les tests passent, le bug est **définitivement corrigé** !

Les utilisateurs peuvent maintenant naviguer librement dans l'application pendant une séance, sans être déconnectés.

**Bug résolu** : ✅ Heartbeat persiste lors de la navigation
