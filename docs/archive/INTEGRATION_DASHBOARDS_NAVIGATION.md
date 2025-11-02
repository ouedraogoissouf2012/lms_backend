# Intégration Dashboards - Navigation Hiérarchique

## Problème Initial

L'utilisateur a rapporté: **"je remarque rien n'a été fait"**

### Cause
Les fichiers Vue.js ont été créés mais **n'étaient pas intégrés dans les dashboards existants**. Les utilisateurs ne voyaient pas de changement car:
- Aucun lien vers les nouvelles pages dans les dashboards
- Aucun bouton pour naviguer vers `/matieres/{id}` ou `/seances/{id}`
- Le bouton "Gestion Séances & Visio" pour coordinateurs manquait

---

## Solutions Appliquées

### ✅ 1. StudentDashboard.vue

**Fichier**: `src/views/dashboards/StudentDashboard.vue`

#### Modifications:

**A. Cartes de cours cliquables** (lignes 102-127):
```vue
<div
  v-for="cours in dashboardData.cours"
  :key="cours.id || cours.matiere_id"
  class="border rounded-lg p-4 hover:shadow-md transition cursor-pointer"
  @click="navigateToMatiere(cours)"
>
  <!-- Contenu carte -->

  <div class="flex gap-2">
    <button
      @click.stop="navigateToMatiere(cours)"
      class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center justify-center gap-2 transition"
    >
      <BookOpenIcon class="w-5 h-5" />
      Voir détails
    </button>
  </div>
</div>
```

**B. Méthode de navigation** (lignes 301-314):
```javascript
navigateToMatiere(cours) {
  // Naviguer vers la page de détails de la matière
  const matiereId = cours.id || cours.matiere_id || cours.matiere?.id
  if (matiereId) {
    console.log('📚 Navigation vers matière:', matiereId)
    this.$router.push({
      name: 'matiere-details',
      params: { id: matiereId }
    })
  } else {
    console.error('❌ ID matière non trouvé:', cours)
    alert('Impossible de naviguer vers cette matière')
  }
}
```

**Résultat**:
- ✅ Clic sur une carte de cours → Navigation vers `/matieres/{id}`
- ✅ Bouton "Voir détails" avec icône `BookOpenIcon`
- ✅ Logs console pour debug

---

### ✅ 2. TeacherDashboard.vue

**Fichier**: `src/views/dashboards/TeacherDashboard.vue`

#### Modifications:

**A. Cartes de matières cliquables** (lignes 80-101):
```vue
<div
  v-for="matiere in dashboardData.matieres"
  :key="matiere.id || matiere.matiere_id"
  class="border rounded-lg p-4 hover:shadow-md transition cursor-pointer"
  @click="navigateToMatiere(matiere)"
>
  <h3 class="font-semibold text-lg mb-2">
    {{ matiere.name || matiere.nom || matiere.libelle || 'Matière sans nom' }}
  </h3>
  <p v-if="matiere.coefficient" class="text-sm text-gray-600 mb-3">Coefficient: {{ matiere.coefficient }}</p>

  <button
    @click.stop="navigateToMatiere(matiere)"
    class="w-full mt-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center justify-center gap-2 transition"
  >
    <BookOpenIcon class="w-5 h-5" />
    Gérer la matière
  </button>
</div>
```

**B. Méthode de navigation** (lignes 273-286):
```javascript
navigateToMatiere(matiere) {
  // Naviguer vers la page de détails de la matière
  const matiereId = matiere.id || matiere.matiere_id
  if (matiereId) {
    console.log('📚 Navigation vers matière:', matiereId)
    this.$router.push({
      name: 'matiere-details',
      params: { id: matiereId }
    })
  } else {
    console.error('❌ ID matière non trouvé:', matiere)
    alert('Impossible de naviguer vers cette matière')
  }
}
```

**Changement de libellé**:
- Avant: "Démarrer cours en ligne" avec `VideoCameraIcon`
- Après: "Gérer la matière" avec `BookOpenIcon`

**Résultat**:
- ✅ Clic sur une carte de matière → Navigation vers `/matieres/{id}`
- ✅ Bouton "Gérer la matière" pour accéder aux lessons/séances/évaluations
- ✅ Ancienne fonction `startCourse()` conservée pour compatibilité

---

### ✅ 3. AdminDashboard.vue (Coordinateurs)

**Fichier**: `src/views/dashboards/AdminDashboard.vue`

#### Modifications:

**A. Nouvelle carte "Gestion Séances & Visio"** (lignes 117-127):
```vue
<!-- Gestion des séances (Coordinateurs uniquement) -->
<router-link
  v-if="user?.role === 'coordinateur' || user?.role === 'superAdmin'"
  to="/coordinateur/seances"
  class="bg-white p-6 rounded-lg shadow-lg hover:shadow-xl transition transform hover:-translate-y-1 border-2 border-orange-400"
>
  <CalendarIcon class="w-12 h-12 text-orange-600 mb-3" />
  <h3 class="font-bold text-lg mb-2">Gestion Séances & Visio</h3>
  <p class="text-gray-600 text-sm">Activer/désactiver les visioconférences</p>
</router-link>
```

**Caractéristiques**:
- ✅ Visible uniquement pour `coordinateur` et `superAdmin`
- ✅ Bordure orange pour se démarquer
- ✅ Icône `CalendarIcon` (déjà importée)
- ✅ Navigation vers `/coordinateur/seances`

**Résultat**:
- ✅ Coordinateurs voient une nouvelle carte dans le menu d'actions
- ✅ Accès direct à l'interface de gestion des visioconférences
- ✅ Autres rôles ne voient pas cette carte (contrôle d'accès)

---

## Flux de Navigation Complet

### Pour un Étudiant:

1. **Connexion** → Redirection vers `/student/dashboard`
2. **Dashboard étudiant** affiche:
   - Stats (moyenne, présence, nombre de cours)
   - Section "Mes Cours" avec cartes de matières
3. **Clic sur une matière** → Navigation vers `/matieres/{id}`
4. **Page MatiereDetails** affiche 3 onglets:
   - **Onglet Lessons**: Liste des lessons avec progression
   - **Onglet Séances**: Liste des séances programmées
   - **Onglet Évaluations**: Liste des évaluations
5. **Clic sur une séance** → Navigation vers `/seances/{id}`
6. **Page SeanceDetails** affiche:
   - Informations séance (date, heure, enseignant, salle)
   - Section visio (si activée):
     - Avant H-15min: Badge "En attente de l'enseignant"
     - Après démarrage: Bouton "Rejoindre le cours"
     - Hors fenêtre: Message "Cours terminé"
7. **Clic "Rejoindre le cours"** → Ouverture Jitsi Meet dans nouvel onglet

### Pour un Enseignant:

1. **Connexion** → Redirection vers `/teacher/dashboard`
2. **Dashboard enseignant** affiche:
   - Stats (matières, classes, évaluations, séances)
   - Section "Mes Matières" avec cartes
3. **Clic sur "Gérer la matière"** → Navigation vers `/matieres/{id}`
4. **Page MatiereDetails** (même interface que étudiant)
5. **Clic sur une séance** → Navigation vers `/seances/{id}`
6. **Page SeanceDetails** affiche:
   - Section visio (si activée):
     - Avant H-15min: Bouton désactivé avec message
     - Dans fenêtre (H-15min à H+30min): Bouton "Démarrer le cours" actif
     - Après H+30min: Message "Fenêtre fermée"
7. **Clic "Démarrer le cours"** → Ouverture Jitsi avec droits modérateur

### Pour un Coordinateur:

1. **Connexion** → Redirection vers `/admin/dashboard`
2. **Dashboard admin** affiche:
   - Stats KLASSCI (enseignants, étudiants, classes, matières)
   - Menu d'actions avec **nouvelle carte "Gestion Séances & Visio"**
3. **Clic sur "Gestion Séances & Visio"** → Navigation vers `/coordinateur/seances`
4. **Page SeanceManagement** affiche:
   - Filtres (période, enseignant, classe)
   - Liste des séances à venir
   - Toggle visio pour chaque séance
   - Sélecteur type visio (Jitsi/Zoom/Teams/BBB)
   - Stats (nombre séances, visio activées, taux)
5. **Toggle ON** → Séance devient visible avec visio dans les détails
6. **Toggle OFF** → Visio désactivée pour cette séance

---

## Récapitulatif des Modifications

### Fichiers Modifiés:

| Fichier | Lignes | Modifications |
|---------|--------|---------------|
| `StudentDashboard.vue` | 102-127, 301-314 | Cartes cliquables + méthode `navigateToMatiere()` |
| `TeacherDashboard.vue` | 80-101, 273-286 | Bouton "Gérer la matière" + méthode `navigateToMatiere()` |
| `AdminDashboard.vue` | 117-127 | Carte "Gestion Séances & Visio" pour coordinateurs |

### Fichiers Créés Précédemment (maintenant accessibles):

| Fichier | Route | Description |
|---------|-------|-------------|
| `MatiereDetails.vue` | `/matieres/:id` | 3 onglets (Lessons, Séances, Évaluations) |
| `SeanceDetails.vue` | `/seances/:id` | Détails séance + boutons visio |
| `SeanceManagement.vue` | `/coordinateur/seances` | Toggle visio (coordinateurs) |
| `seance.js` | N/A | Service API pour séances |

### Routes Configurées:

```javascript
// src/router/index.js:185-208

// Matières - Navigation hiérarchique
{
  path: '/matieres/:id',
  name: 'matiere-details',
  component: MatiereDetails,
  meta: { requiresAuth: true }
},

// Séances - Détails avec visioconférence
{
  path: '/seances/:id',
  name: 'seance-details',
  component: SeanceDetails,
  meta: { requiresAuth: true }
},

// Coordinateur - Gestion des séances et visio
{
  path: '/coordinateur/seances',
  name: 'seance-management',
  component: SeanceManagement,
  meta: {
    requiresAuth: true,
    roles: ['coordinateur', 'superAdmin']
  }
}
```

---

## Tests à Effectuer

### Test 1: Navigation Étudiant
```
1. Se connecter comme étudiant
2. Vérifier affichage du dashboard avec cartes de cours
3. Cliquer sur une carte de cours
4. Vérifier redirection vers /matieres/{id}
5. Vérifier affichage des 3 onglets
6. Cliquer sur une séance dans l'onglet "Séances"
7. Vérifier redirection vers /seances/{id}
8. Vérifier affichage section visio (si activée)
```

### Test 2: Navigation Enseignant
```
1. Se connecter comme enseignant
2. Vérifier affichage du dashboard avec cartes de matières
3. Cliquer sur "Gérer la matière"
4. Vérifier redirection vers /matieres/{id}
5. Cliquer sur une séance
6. Vérifier bouton "Démarrer le cours" (si dans fenêtre)
```

### Test 3: Navigation Coordinateur
```
1. Se connecter comme coordinateur
2. Vérifier présence de la carte "Gestion Séances & Visio" (bordure orange)
3. Cliquer sur la carte
4. Vérifier redirection vers /coordinateur/seances
5. Vérifier affichage de la liste des séances
6. Tester toggle visio pour une séance
7. Vérifier que le changement se reflète dans SeanceDetails
```

### Test 4: Contrôle d'Accès
```
1. Se connecter comme étudiant
2. Tenter d'accéder à /coordinateur/seances
3. Vérifier redirection vers dashboard étudiant (ou erreur 403)

1. Se connecter comme enseignant
2. Tenter d'accéder à /coordinateur/seances
3. Vérifier redirection vers dashboard enseignant (ou erreur 403)
```

---

## Problèmes Potentiels et Solutions

### Problème 1: ID Matière Introuvable
**Symptôme**: Alert "Impossible de naviguer vers cette matière"

**Cause**: La réponse API KLASSCI ne contient pas `id` ou `matiere_id`

**Solution**:
```javascript
// Vérifier dans la console réseau la structure de la réponse
console.log('Structure cours:', dashboardData.cours)

// Adapter le code si nécessaire:
const matiereId = cours.id || cours.matiere_id || cours.matiere?.id || cours.code_matiere
```

### Problème 2: Carte "Gestion Séances" Invisible
**Symptôme**: Coordinateur ne voit pas la carte

**Cause**: Rôle utilisateur incorrect

**Solution**:
```javascript
// Vérifier le rôle dans la console
console.log('User role:', auth.getUser()?.role)

// Vérifier que le rôle est bien 'coordinateur' ou 'superAdmin'
// Si différent, modifier la condition:
v-if="user?.role === 'nom_du_role_reel'"
```

### Problème 3: Routes 404
**Symptôme**: Page blanche ou erreur 404

**Cause**: Routes non configurées ou nom incorrect

**Solution**:
```bash
# Vérifier que les routes sont bien dans router/index.js
grep -n "matiere-details" src/router/index.js
grep -n "seance-details" src/router/index.js
grep -n "seance-management" src/router/index.js

# Vérifier que le nom de route correspond:
this.$router.push({ name: 'matiere-details' }) # Doit matcher routes[].name
```

---

## Prochaines Étapes

### Améliorations UI:

1. **Badges de statut** sur cartes de matières:
   - Badge vert "Nouveau lesson" si lesson publié < 7 jours
   - Badge orange "Séance aujourd'hui" si séance le jour même
   - Badge rouge "Évaluation en cours" si dans fenêtre temporelle

2. **Indicateurs visuels**:
   - Icône 📹 si séance avec visio activée
   - Icône ⏰ avec heure de la prochaine séance
   - Barre de progression globale pour la matière

3. **Recherche et filtres**:
   - Barre de recherche dans liste des matières
   - Filtres par filière, niveau, coefficient
   - Tri par nom, coefficient, date

### Optimisations Performance:

1. **Lazy loading** des composants:
```javascript
component: () => import('@/views/matieres/MatiereDetails.vue')
```

2. **Mise en cache** des données matières:
```javascript
// Éviter de recharger si déjà en cache
if (this.$store.state.matieresCache[matiereId]) {
  this.matiere = this.$store.state.matieresCache[matiereId]
} else {
  this.matiere = await api.get(`/lms/matieres/${matiereId}`)
}
```

3. **Pagination** si trop de matières:
```vue
<Pagination
  :total="totalMatieres"
  :per-page="12"
  @page-changed="loadPage"
/>
```

---

## Conclusion

✅ **Les dashboards sont maintenant intégrés** avec la navigation hiérarchique complète:

- Étudiants: Cartes de cours → Détails matière → Détails séance → Rejoindre visio
- Enseignants: Cartes de matières → Détails matière → Détails séance → Démarrer visio
- Coordinateurs: Carte "Gestion Séances & Visio" → Interface de toggle visio

**L'utilisateur devrait maintenant voir les changements** lorsqu'il navigue dans son dashboard!
