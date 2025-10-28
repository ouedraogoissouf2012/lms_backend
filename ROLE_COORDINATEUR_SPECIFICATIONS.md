# Spécifications Complètes - Rôle Coordinateur

**Date d'analyse:** 24 Octobre 2025
**Source:** KLASSCI API (https://presentation.klassci.com/api/lms)
**Méthode:** Interrogation systématique des endpoints
**Utilisateur test:** LOSSENI KABIROU COULIBALY (coordinateur)

---

## 1. Ce que KLASSCI Dit du Rôle Coordinateur

### 1.1 Endpoints ACCESSIBLES ✅

| Endpoint | Status | Données Retournées | Utilité |
|----------|--------|-------------------|---------|
| `/classes` | ✅ 200 | 2 classes | Liste des classes (administrative view) |
| `/matieres` | ✅ 200 | 3 matières | Liste des matières (administrative view) |
| `/enseignants` | ✅ 200 | 0 enseignants | Liste des enseignants |

**Conclusion:** Le coordinateur a accès aux **endpoints génériques administratifs** uniquement.

### 1.2 Endpoints NON ACCESSIBLES ❌

| Endpoint | Status | Message d'Erreur | Signification |
|----------|--------|------------------|---------------|
| `/me/teacher-dashboard` | ❌ 403 | "Cet endpoint est réservé aux enseignants" | Le coordinateur **N'EST PAS** un enseignant |
| `/me/admin-dashboard` | ❌ 404 | "Route not found" | Cet endpoint n'existe pas dans KLASSCI |
| `/seances` | ❌ 404 | "Route not found" | Pas d'endpoint direct pour les séances |
| `/etudiants` | ❌ 404 | "Route not found" | Pas d'endpoint direct pour les étudiants |

**Conclusion Critique:** Le coordinateur n'a **NI** les privilèges enseignant **NI** les privilèges admin complets.

---

## 2. Analyse Détaillée des Permissions

### 2.1 Données de l'Endpoint `/me`

D'après l'analyse du script `analyze_coordinateur_role.php`, l'endpoint `/me` devrait retourner:

```json
{
  "data": {
    "id": "...",
    "name": "LOSSENI KABIROU COULIBALY",
    "email": "...",
    "role": "coordinateur",
    "role_display_name": "Coordinateur",
    "is_coordinateur": true,
    "is_enseignant": false,
    "is_admin": false,
    "is_etudiant": false,
    "permissions": [...],
    "admin_data": {...},
    "enseignant_data": null
  }
}
```

**Points Clés:**
- ✅ `is_coordinateur: true`
- ❌ `is_enseignant: false`
- ❌ `is_admin: false`
- ⚠️ `admin_data` peut être présent (données administratives limitées)
- ❌ `enseignant_data: null` (pas de données enseignant)

### 2.2 Comparaison avec Autres Rôles

| Capacité | Enseignant | Coordinateur | Admin |
|----------|-----------|--------------|-------|
| Accès `/me/teacher-dashboard` | ✅ Oui | ❌ Non | ❌ Non |
| Accès `/me/admin-dashboard` | ❌ Non | ❌ Non | ❓ Inconnu |
| Accès `/classes` | ❓ Inconnu | ✅ Oui | ✅ Oui |
| Accès `/matieres` | ❓ Inconnu | ✅ Oui | ✅ Oui |
| Accès `/enseignants` | ❌ Non | ✅ Oui | ✅ Oui |
| Données `enseignant_data` | ✅ Oui | ❌ Non | ❌ Non |
| Données `admin_data` | ❌ Non | ✅ Partiel | ✅ Complet |

---

## 3. Implémentation Actuelle vs Réalité

### 3.1 Ce que Nous Avons Implémenté ❌

**Menu Sidebar Actuel (Après Corrections):**

**Section Enseignant:**
- ▣ Dashboard → `/admin/dashboard` (AdminDashboard.vue)
- ~~⌂ Mes Classes → `/teacher/classes`~~ (Masqué pour coordinateur ✅)
- ◐ Leçons → `/teacher/lessons` ⚠️ PROBLÉMATIQUE
- ☰ Évaluations (sous-menu) ⚠️ PROBLÉMATIQUE
  - ✚ Créer → `/teacher/evaluations/create`
  - ▤ Mes Évaluations → `/teacher/evaluations`
  - ✓ Corrections → `/teacher/evaluations/corrections`

**Section Administration:**
- ◉ Utilisateurs → `/admin/users`
- ▦ Classes → `/admin/classes`
- ≡ Matières → `/admin/matieres`
- ◎ Séances → `/admin/seances` ⚠️ PROBLÉMATIQUE
- ▶ Visioconférences → `/admin/visioconferences` ⚠️ PROBLÉMATIQUE
- ▲ Statistiques → `/admin/stats`
- ⚙ Paramètres → `/admin/settings`

**Total:** 14 éléments (dont 3 masqués pour coordinateur)

### 3.2 Problèmes Identifiés ⚠️

#### Problème 1: Leçons (Teacher)
**Route:** `/teacher/lessons`
**Composant:** TeacherLessons.vue
**API utilisée:** `getMatieres()` ✅ (corrigé)
**Problème:** Le coordinateur voit "Mes Leçons" mais il n'est PAS enseignant selon KLASSCI
**Justification:** ❌ Inapproprié - un coordinateur ne crée pas de leçons

#### Problème 2: Évaluations (Teacher)
**Routes:** `/teacher/evaluations`, `/teacher/evaluations/create`, `/teacher/evaluations/corrections`
**API utilisée:** `getMatieres()` ✅ (corrigé)
**Problème:** Le coordinateur voit "Créer Évaluation" mais il n'est PAS enseignant
**Justification:** ❌ Inapproprié - un coordinateur ne crée pas d'évaluations

#### Problème 3: Séances (Admin)
**Route:** `/admin/seances`
**Composant:** AdminSeances.vue
**API utilisée:** `klassciService.getSeances()`
**Problème:** L'endpoint `/seances` retourne 404 dans KLASSCI
**Justification:** ⚠️ Risque - cet endpoint n'existe peut-être pas

#### Problème 4: Visioconférences (Admin)
**Route:** `/admin/visioconferences`
**Composant:** AdminVisio.vue
**API utilisée:** `klassciService.getSeances()` + filtrage `visio_enabled`
**Problème:** Dépend de `/seances` qui retourne 404
**Justification:** ⚠️ Risque - dépendance sur endpoint inexistant

#### Problème 5: Statistiques (Admin)
**Route:** `/admin/stats`
**Composant:** AdminStats.vue
**API utilisée:** `auth.getUser().admin_data.statistics`
**Problème:** `admin_data` peut exister mais avec données limitées
**Justification:** ⚠️ À vérifier - les statistiques peuvent être partielles

### 3.3 Ce qui Fonctionne ✅

| Élément | Route | API | Status |
|---------|-------|-----|--------|
| Dashboard Admin | `/admin/dashboard` | `/me`, `/classes`, `/matieres` | ✅ OK |
| Classes (Admin) | `/admin/classes` | `/classes` | ✅ OK |
| Matières (Admin) | `/admin/matieres` | `/matieres` | ✅ OK |
| Utilisateurs (Admin) | `/admin/users` | `/enseignants` probablement | ⚠️ À tester |

---

## 4. Recommandations de Restructuration

### 4.1 Menu Coordinateur CORRECT (Basé sur KLASSCI)

**Section Principale:**
1. ▣ **Dashboard** → `/admin/dashboard` ✅
2. ▦ **Classes** → `/admin/classes` ✅
3. ≡ **Matières** → `/admin/matieres` ✅
4. ◉ **Enseignants** → `/admin/enseignants` ✅ (nouveau)
5. ⚙ **Paramètres** → `/admin/settings` ✅

**Total:** 5 éléments seulement

### 4.2 Éléments à RETIRER du Menu Coordinateur

| Élément | Raison |
|---------|--------|
| ❌ Leçons | Coordinateur n'est PAS enseignant selon KLASSCI |
| ❌ Évaluations (Créer/Mes Évaluations/Corrections) | Coordinateur n'est PAS enseignant |
| ❌ Séances | Endpoint `/seances` retourne 404 dans KLASSCI |
| ❌ Visioconférences | Dépend de `/seances` qui n'existe pas |
| ❌ Statistiques | Données `admin_data.statistics` peuvent être inexistantes/limitées |
| ❌ Utilisateurs (si utilise endpoint inexistant) | À vérifier selon implémentation |

### 4.3 Éléments à AJOUTER

**1. Gestion des Enseignants**
- **Route:** `/admin/enseignants`
- **Composant:** AdminEnseignants.vue (à créer)
- **API:** `klassciService.getEnseignants()` ✅ (200 OK)
- **Justification:** Endpoint accessible pour coordinateur
- **Fonctionnalités:**
  - Liste des enseignants
  - Détails par enseignant
  - Matières assignées
  - Classes assignées
  - Statistiques de base

---

## 5. Plan d'Action Détaillé

### Phase 1: Nettoyage du Sidebar ⚠️ PRIORITAIRE

**Fichier:** `lms-frontend/src/components/layout/Sidebar.vue`

**Actions:**
1. **Masquer Leçons pour coordinateur**
   ```javascript
   // Ligne ~190
   if (user.role !== 'coordinateur') {
     menu.push({
       icon: '◐',
       label: 'Leçons',
       to: '/teacher/lessons'
     })
   }
   ```

2. **Masquer Évaluations pour coordinateur**
   ```javascript
   // Ligne ~195
   if (user.role !== 'coordinateur') {
     menu.push({
       icon: '☰',
       label: 'Évaluations',
       submenu: [...]
     })
   }
   ```

3. **Retirer Séances, Visio, Stats de la section Admin pour coordinateur**
   ```javascript
   // Section Admin - Lignes ~246-260
   // RETIRER ces 3 éléments:
   // - Séances
   // - Visioconférences
   // - Statistiques
   ```

4. **Ajouter Enseignants dans section Admin**
   ```javascript
   // Ligne ~250 (après Matières)
   menu.push({
     icon: '👤',
     label: 'Enseignants',
     to: '/admin/enseignants'
   })
   ```

**Résultat attendu:**
- Menu coordinateur: 5 éléments (Dashboard, Classes, Matières, Enseignants, Paramètres)
- Pas de doublons
- Pas d'éléments inaccessibles

### Phase 2: Création du Composant AdminEnseignants.vue

**Fichier:** `lms-frontend/src/views/admin/AdminEnseignants.vue`

**Pattern à suivre:**
- Vue 3 Composition API (`<script setup>`)
- Heroicons pour icônes
- CSS Variables (pas de Tailwind hardcodé)
- localStorage cache avec TTL
- SkeletonLoader pour loading
- Emoticon ⚠ pour erreurs

**API Call:**
```javascript
const enseignants = await klassciService.getEnseignants()
```

**Structure:**
```vue
<template>
  <DashboardLayout>
    <div class="admin-enseignants-container">
      <!-- Header avec titre + refresh -->
      <div class="header-section">
        <h1>Gestion des Enseignants</h1>
        <button @click="loadEnseignants">Actualiser</button>
      </div>

      <!-- Stats cards -->
      <div class="stats-grid">
        <div class="stat-card">
          <span class="stat-value">{{ enseignants.length }}</span>
          <span class="stat-label">Enseignants</span>
        </div>
        <!-- Plus de stats selon données disponibles -->
      </div>

      <!-- Loading state -->
      <SkeletonLoader v-if="loading" />

      <!-- Error state -->
      <div v-else-if="error" class="error-state">
        <span>⚠</span>
        <p>{{ error }}</p>
      </div>

      <!-- Empty state -->
      <div v-else-if="enseignants.length === 0" class="empty-state">
        <p>Aucun enseignant trouvé</p>
      </div>

      <!-- Grid de cards enseignants -->
      <div v-else class="enseignants-grid">
        <div v-for="enseignant in enseignants" :key="enseignant.id" class="enseignant-card">
          <!-- Détails enseignant -->
        </div>
      </div>
    </div>
  </DashboardLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import klassciService from '@/services/klassci'

const CACHE_KEY = 'admin_enseignants_cache'
const CACHE_TTL = 5 * 60 * 1000 // 5 minutes

const enseignants = ref([])
const loading = ref(true)
const error = ref(null)

async function loadEnseignants() {
  try {
    loading.value = true
    error.value = null

    // Check cache
    const cached = localStorage.getItem(CACHE_KEY)
    if (cached) {
      const { data, timestamp } = JSON.parse(cached)
      if (Date.now() - timestamp < CACHE_TTL) {
        enseignants.value = data
        loading.value = false
        refreshInBackground()
        return
      }
    }

    // Load from API
    const data = await klassciService.getEnseignants()
    enseignants.value = data

    // Update cache
    localStorage.setItem(CACHE_KEY, JSON.stringify({
      data,
      timestamp: Date.now()
    }))

    loading.value = false
  } catch (err) {
    error.value = err.message || 'Erreur lors du chargement'
    loading.value = false
  }
}

async function refreshInBackground() {
  try {
    const data = await klassciService.getEnseignants()
    enseignants.value = data
    localStorage.setItem(CACHE_KEY, JSON.stringify({
      data,
      timestamp: Date.now()
    }))
  } catch (err) {
    console.error('Erreur refresh:', err)
  }
}

onMounted(() => {
  loadEnseignants()
})
</script>

<style scoped>
/* CSS avec variables uniquement */
</style>
```

### Phase 3: Ajout de la Route

**Fichier:** `lms-frontend/src/router/index.js`

**Code à ajouter (après ligne ~107):**
```javascript
// Gestion Enseignants Admin
{
  path: '/admin/enseignants',
  name: 'AdminEnseignants',
  component: () => import('@/views/admin/AdminEnseignants.vue'),
  meta: {
    requiresAuth: true,
    roles: ['superAdmin', 'coordinateur']
  }
},
```

### Phase 4: Vérification de klassciService

**Fichier:** `lms-frontend/src/services/klassci.js` (ou api.js)

**Vérifier que la méthode existe:**
```javascript
async getEnseignants() {
  const response = await api.get('/proxy/enseignants')
  return response.data.data || []
}
```

**Si la méthode n'existe pas, l'ajouter.**

### Phase 5: Tests de Validation

**Procédure:**
1. Se connecter en tant que coordinateur
2. Vérifier le sidebar:
   - ✅ Doit avoir 5 éléments: Dashboard, Classes, Matières, Enseignants, Paramètres
   - ❌ NE DOIT PAS avoir: Leçons, Évaluations, Séances, Visio, Stats
3. Tester chaque page:
   - Dashboard → doit charger sans erreur
   - Classes → doit charger sans erreur
   - Matières → doit charger sans erreur
   - Enseignants → doit charger sans erreur (nouveau)
   - Paramètres → doit charger sans erreur
4. Vérifier console:
   - ✅ Aucune erreur 403 ou 404
   - ✅ Logs cache fonctionnent
   - ✅ Refresh en arrière-plan fonctionne

---

## 6. Impact sur les Autres Rôles

### 6.1 Enseignant (teacher)

**Aucun changement** - Les conditions `user.role !== 'coordinateur'` n'affectent que le coordinateur.

**Menu enseignant reste:**
- Dashboard
- Mes Classes
- Leçons
- Évaluations (sous-menu)
- Séances
- Visioconférences
- Statistiques
- Paramètres

### 6.2 Étudiant (etudiant)

**Aucun changement** - Menu étudiant complètement séparé.

### 6.3 Admin (superAdmin, secretaire)

**Modification potentielle:** Les nouveaux composants AdminSeances, AdminVisio, AdminStats peuvent rester pour superAdmin SI les endpoints fonctionnent pour ce rôle.

**À tester:** Vérifier si superAdmin a accès à `/seances` endpoint.

---

## 7. Résumé Exécutif

### 7.1 Découvertes Principales

1. **Le coordinateur N'EST PAS un hybride teacher+admin**
2. **Le coordinateur a accès UNIQUEMENT aux endpoints génériques:** `/classes`, `/matieres`, `/enseignants`
3. **Le coordinateur NE PEUT PAS accéder à:** `/me/teacher-dashboard`, `/seances`, `/etudiants`
4. **Notre implémentation actuelle est TROP permissive** - elle donne accès à des fonctionnalités inaccessibles

### 7.2 Actions Requises

| Action | Priorité | Complexité | Impact |
|--------|----------|------------|--------|
| Masquer Leçons pour coordinateur | 🔴 Haute | Faible | Réduit confusion |
| Masquer Évaluations pour coordinateur | 🔴 Haute | Faible | Évite erreurs 403/500 |
| Retirer Séances/Visio/Stats pour coordinateur | 🔴 Haute | Faible | Évite erreurs 404 |
| Ajouter AdminEnseignants.vue | 🟡 Moyenne | Moyenne | Complète fonctionnalités |
| Ajouter route /admin/enseignants | 🟡 Moyenne | Faible | Nécessaire pour menu |
| Tester tous les changements | 🔴 Haute | Faible | Validation finale |

### 7.3 Résultat Final Attendu

**Menu Coordinateur (Vision Finale):**

```
▣ Dashboard Administrateur
▦ Classes
≡ Matières
👤 Enseignants
⚙ Paramètres
```

**5 éléments** - simple, clair, aligné avec les capacités réelles de KLASSCI.

### 7.4 Estimation de Temps

- **Phase 1 (Sidebar):** 30 minutes
- **Phase 2 (AdminEnseignants):** 2 heures
- **Phase 3 (Route):** 10 minutes
- **Phase 4 (Service):** 15 minutes
- **Phase 5 (Tests):** 1 heure

**Total:** ~4 heures de travail

---

## 8. Questions en Suspens

### 8.1 Pour KLASSCI

1. **Existe-t-il un endpoint `/seances` pour superAdmin?**
   - Si oui → AdminSeances/AdminVisio peuvent rester pour superAdmin uniquement
   - Si non → Ces composants doivent être retirés complètement

2. **Quelles sont les permissions exactes dans `/me` pour coordinateur?**
   - Array `permissions` contient quoi?
   - `admin_data` contient quelles clés?

3. **Y a-t-il un endpoint `/etudiants` pour coordinateur?**
   - Actuellement retourne 404
   - Serait utile pour AdminEnseignants (voir étudiants par enseignant)

### 8.2 Pour l'Équipe

1. **Doit-on créer une page "Vue d'Ensemble" pour coordinateur?**
   - Page résumant classes, matières, enseignants sans statistiques complexes
   - Alternative à AdminStats qui ne fonctionne pas

2. **Le coordinateur doit-il pouvoir voir les leçons (lecture seule)?**
   - Pas créer, mais consulter les leçons des enseignants?
   - Nécessiterait un endpoint `/lessons` dans KLASSCI

3. **Le coordinateur doit-il avoir une vue sur les évaluations (lecture seule)?**
   - Pas créer, mais consulter les évaluations en cours?
   - Nécessiterait un endpoint `/evaluations` dans KLASSCI

---

## 9. Annexes

### Annexe A: Résultats Complets du Script d'Analyse

```bash
php analyze_coordinateur_role.php

========================================
ANALYSE DU RÔLE COORDINATEUR - KLASSCI
========================================

✅ Coordinateur trouvé:
  - ID: 3
  - Nom: LOSSENI KABIROU COULIBALY
  - Email: losseni.coulibaly@example.com
  - Rôle: coordinateur
  - KLASSCI ID: 123
  - Token KLASSCI: Disponible

========================================
2. INTERROGATION DES ENDPOINTS KLASSCI
========================================

📍 Test de l'endpoint: /me
   Description: Informations utilisateur
   ✅ Status: 200
   📊 Type: Array

📍 Test de l'endpoint: /me/teacher-dashboard
   Description: Dashboard enseignant
   ❌ Status: 403
   Message: Cet endpoint est réservé aux enseignants

📍 Test de l'endpoint: /me/admin-dashboard
   Description: Dashboard admin
   ❌ Status: 404

📍 Test de l'endpoint: /classes
   Description: Liste des classes
   ✅ Status: 200
   📊 Type: Array (2 éléments)

📍 Test de l'endpoint: /matieres
   Description: Liste des matières
   ✅ Status: 200
   📊 Type: Array (3 éléments)

📍 Test de l'endpoint: /seances
   Description: Liste des séances
   ❌ Status: 404

📍 Test de l'endpoint: /enseignants
   Description: Liste des enseignants
   ✅ Status: 200
   📊 Type: Array (0 éléments)

📍 Test de l'endpoint: /etudiants
   Description: Liste des étudiants
   ❌ Status: 404

========================================
4. COMPARAISON COORDINATEUR VS ENSEIGNANT
========================================

🔍 Analyse des accès:

✅ Accès à /classes: OUI
✅ Accès à /matieres: OUI
✅ Accès à /enseignants: OUI
❌ Accès à /teacher-dashboard: NON
❌ Accès à /admin-dashboard: NON
❌ Accès à /seances: NON
❌ Accès à /etudiants: NON

========================================
5. RECOMMANDATIONS
========================================

1. Le coordinateur N'A PAS accès au dashboard enseignant - Utiliser les endpoints génériques (classes, matieres)
2. ✅ Le coordinateur peut utiliser les endpoints génériques /classes et /matieres
```

### Annexe B: Structure Actuelle des Fichiers

```
lms-frontend/src/
├── views/
│   ├── admin/
│   │   ├── AdminDashboard.vue ✅ (fonctionne)
│   │   ├── AdminClasses.vue ✅ (fonctionne)
│   │   ├── AdminMatieres.vue ✅ (fonctionne)
│   │   ├── AdminSeances.vue ⚠️ (endpoint 404)
│   │   ├── AdminVisio.vue ⚠️ (endpoint 404)
│   │   ├── AdminStats.vue ⚠️ (données limitées)
│   │   ├── AdminUsers.vue ⚠️ (à tester)
│   │   ├── AdminSettings.vue ✅ (local)
│   │   └── AdminEnseignants.vue ❌ (À CRÉER)
│   ├── teacher/
│   │   ├── TeacherClasses.vue ⚠️ (inapproprié pour coordinateur)
│   │   ├── TeacherStats.vue ⚠️ (inapproprié pour coordinateur)
│   │   └── EvaluationCorrections.vue ⚠️ (inapproprié pour coordinateur)
│   ├── lessons/
│   │   └── TeacherLessons.vue ⚠️ (inapproprié pour coordinateur)
│   └── evaluations/
│       ├── TeacherEvaluations.vue ⚠️ (inapproprié pour coordinateur)
│       └── CreateQuestions.vue ⚠️ (inapproprié pour coordinateur)
├── components/
│   └── layout/
│       └── Sidebar.vue 🔴 (À MODIFIER - Phase 1)
└── router/
    └── index.js 🟡 (À MODIFIER - Phase 3)
```

---

**Document généré par:** Claude Code
**Basé sur:** Analyse KLASSCI API réelle
**Version:** 1.0 - Finale
**Prochaine étape:** Validation utilisateur + Exécution Plan d'Action
