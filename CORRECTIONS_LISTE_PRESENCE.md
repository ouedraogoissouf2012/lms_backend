# CORRECTIONS LISTE DE PRÉSENCE - VISIOCONFÉRENCE

## PROBLÈMES IDENTIFIÉS

1. ❌ **Heure de sortie non enregistrée** - Les étudiants rejoignent la visio mais quand ils ferment Jitsi, l'heure de sortie (`left_at`) n'est pas enregistrée
2. ❌ **Nom de l'enseignant non affiché** - Dans la liste de présence, le nom de l'enseignant n'apparaît pas

---

## ANALYSE

### Problème 1: Heure de sortie non enregistrée

**Cause**:
- Les étudiants ouvrent Jitsi avec `window.open(link, '_blank')` (ligne 405 de SeanceDetails.vue)
- La référence de la fenêtre n'est **pas stockée**
- Aucun mécanisme ne **surveille la fermeture** de la fenêtre
- La méthode `leaveVisio()` existe dans l'API backend mais n'est **jamais appelée** par les étudiants

**Comparaison**:
- ✅ **VisioManager.vue** (enseignants): Stocke `this.visioWindow`, surveille la fermeture, appelle `leaveVisio()`
- ❌ **SeanceDetails.vue** (étudiants): Ouvre Jitsi et... rien. Pas de tracking.

### Problème 2: Nom enseignant non affiché

**Cause**:
- L'endpoint `/lms/seances/{seanceId}/participants` retourne la liste des étudiants
- Mais ne retourne **PAS** l'information sur l'enseignant
- Le modal ParticipantsModal.vue affiche seulement "Liste de Présence - Séance #50"
- L'enseignant est stocké dans `visio.enseignant_nom` (table `esbtp_seances`)

---

## SOLUTION 1: FIXER L'HEURE DE SORTIE

### Fichier: `lms-frontend/src/views/seances/SeanceDetails.vue`

#### Modification 1: Ajouter `visioWindow` dans `data()`

**Ligne 248** - Ajouter:
```javascript
data() {
  return {
    loading: false,
    error: null,
    seance: null,
    visio: null,
    participants: {
      teacher: null,
      students: [],
      total: 0
    },
    roomActive: false,
    joiningVisio: false,
    visioWindow: null // ← AJOUTER CETTE LIGNE
  }
},
```

#### Modification 2: Modifier `joinVisio()` pour stocker la référence

**Lignes 404-407** - Modifier:
```javascript
// AVANT:
// Ouvrir Jitsi
window.open(link, '_blank')

console.log('✅ Étudiant a rejoint la visio et participation enregistrée')
```

```javascript
// APRÈS:
// Ouvrir Jitsi et stocker la référence
this.visioWindow = window.open(link, '_blank')

// Surveiller la fermeture de la fenêtre
this.watchVisioWindow()

console.log('✅ Étudiant a rejoint la visio et participation enregistrée')
```

#### Modification 3: Ajouter méthode `watchVisioWindow()`

**Après la méthode `joinVisio()`** - Ajouter:
```javascript
/**
 * Surveiller la fermeture de la fenêtre Jitsi
 */
watchVisioWindow() {
  if (!this.visioWindow) return

  const checkClosed = setInterval(() => {
    if (this.visioWindow.closed) {
      clearInterval(checkClosed)
      console.log('🚪 Fenêtre Jitsi fermée, enregistrement sortie')
      this.leaveVisio()
      this.visioWindow = null
    }
  }, 1000) // Vérifier toutes les secondes
},
```

#### Modification 4: Ajouter méthode `leaveVisio()`

**Après la méthode `watchVisioWindow()`** - Ajouter:
```javascript
/**
 * Enregistrer la sortie de la visio
 */
async leaveVisio() {
  try {
    const response = await lmsService.leaveVisio(this.seanceId)
    console.log('👋 Sortie de visio enregistrée:', response)
  } catch (error) {
    console.error('❌ Erreur leave visio:', error)
  }
},
```

---

## SOLUTION 2: AFFICHER NOM ENSEIGNANT

### Fichier: `lms-backend/app/Http/Controllers/API/LMSDataController.php`

#### Modification: Ajouter info enseignant dans `getVisioParticipants()`

**Ligne 2997-3003** - Modifier:
```php
// AVANT:
return response()->json([
    'success' => true,
    'data' => [
        'students' => $unifiedList,
        'statistics' => $stats
    ]
]);
```

```php
// APRÈS:
// Récupérer l'info enseignant depuis la table seances
$teacherInfo = [
    'nom' => $visio->enseignant_nom ?? null,
    'prenom' => $visio->enseignant_prenom ?? null,
];

return response()->json([
    'success' => true,
    'data' => [
        'students' => $unifiedList,
        'statistics' => $stats,
        'teacher' => $teacherInfo,  // ← AJOUTER CETTE LIGNE
        'seance' => [               // ← AJOUTER CETTE SECTION
            'id' => $seanceId,
            'matiere' => $visio->matiere_nom,
            'classe' => $visio->klassci_classe_id,
        ]
    ]
]);
```

### Fichier: `lms-frontend/src/components/visio/ParticipantsModal.vue`

#### Modification 1: Ajouter `teacher` dans `data()`

**Ligne 247** - Ajouter:
```javascript
data() {
  return {
    loading: false,
    error: null,
    students: [],
    teacher: null,  // ← AJOUTER CETTE LIGNE
    stats: {
      total_students: 0,
      present_count: 0,
      // ...
    },
    refreshInterval: null
  }
},
```

#### Modification 2: Récupérer `teacher` dans `loadParticipants()`

**Ligne 279-284** - Modifier:
```javascript
// AVANT:
if (response && response.success) {
  this.students = response.data.students || []
  this.stats = response.data.statistics || this.stats
  // ...
}
```

```javascript
// APRÈS:
if (response && response.success) {
  this.students = response.data.students || []
  this.stats = response.data.statistics || this.stats
  this.teacher = response.data.teacher || null  // ← AJOUTER CETTE LIGNE
  // ...
}
```

#### Modification 3: Afficher nom enseignant dans le header

**Ligne 10** - Modifier:
```vue
<!-- AVANT: -->
<h3 class="text-xl font-bold flex items-center">
  <svg class="w-6 h-6 mr-2" ...>...</svg>
  📋 Liste de Présence - Séance #{{ seanceId }}
</h3>
```

```vue
<!-- APRÈS: -->
<h3 class="text-xl font-bold flex items-center">
  <svg class="w-6 h-6 mr-2" ...>...</svg>
  📋 Liste de Présence - Séance #{{ seanceId }}
</h3>
<!-- AJOUTER SOUS LE TITRE: -->
<p v-if="teacher && teacher.nom" class="text-sm font-normal opacity-90 mt-1">
  Enseignant: {{ teacher.prenom ? teacher.prenom + ' ' : '' }}{{ teacher.nom }}
</p>
```

---

## RÉSULTAT ATTENDU

### Avant:
```
📋 Liste de Présence - Séance #50

Total: 1  Présents: 1  Absents: 0  Retards: 1  Durée moy: -

NOM                  STATUT        DURÉE    REJOINT   QUITTÉ
MARCEL OUEDRAOGO    Présent (0%)    -       12:09      -
```

### Après:
```
📋 Liste de Présence - Séance #50
Enseignant: Jean DUPONT

Total: 1  Présents: 1  Absents: 0  Retards: 1  Durée moy: 45min

NOM                  STATUT        DURÉE    REJOINT   QUITTÉ
MARCEL OUEDRAOGO    Présent (75%)  45min    12:09    12:54
```

---

## TESTS À EFFECTUER

### Test 1: Heure de sortie
1. ✅ Étudiant rejoint la visio
2. ✅ Reste connecté 10 minutes
3. ✅ Ferme la fenêtre Jitsi
4. ✅ Vérifier dans la BDD: `esbtp_attendance.left_at` est rempli
5. ✅ Vérifier dans la liste de présence: colonne QUITTÉ affiche l'heure

### Test 2: Nom enseignant
1. ✅ Ouvrir liste de présence
2. ✅ Vérifier que le nom de l'enseignant apparaît sous le titre
3. ✅ Format: "Enseignant: Prénom NOM"

---

## FICHIERS À MODIFIER

1. **Frontend**:
   - `lms-frontend/src/views/seances/SeanceDetails.vue`
   - `lms-frontend/src/components/visio/ParticipantsModal.vue`

2. **Backend**:
   - `lms-backend/app/Http/Controllers/API/LMSDataController.php`

---

## NOTES TECHNIQUES

### Pourquoi `setInterval` au lieu de `beforeunload`?

L'événement `beforeunload` ne fonctionne pas pour les fenêtres ouvertes avec `window.open()`. La seule façon fiable de détecter la fermeture est de vérifier périodiquement `window.closed`.

### Performance

Le `setInterval` vérifie toutes les secondes. C'est négligeable en termes de performance et s'arrête automatiquement dès que la fenêtre est fermée.

### Durée calculée

La durée est automatiquement calculée dans le modèle `ESBTPAttendance`:
```php
public function markAsDisconnected()
{
    $this->left_at = now();
    $this->status = 'disconnected';
    $this->duration_minutes = $this->joined_at ?
        $this->joined_at->diffInMinutes($this->left_at) : 0;
    $this->save();
}
```

---

## PRIORITÉ

🔴 **CRITIQUE**: Heure de sortie (empêche calcul précis de la présence)
🟡 **IMPORTANT**: Nom enseignant (améliore UX mais pas bloquant)
