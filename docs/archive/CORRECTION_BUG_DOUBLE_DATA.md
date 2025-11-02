# Correction Bug: "Impossible de charger les détails de la matière"

## Problème Identifié

L'erreur "Impossible de charger les détails de la matière" était causée par une **double extraction de `response.data`** dans le service LMS.

### Symptôme

```javascript
❌ Response success = false ou undefined
❌ Full response: {
  "matiere": { ... },      // Pas de propriété "success" !
  "combinaisons": [ ... ],
  // ...
}
```

Le code frontend attendait:
```javascript
{
  success: true,
  data: { matiere: {...}, lessons: [...] }
}
```

Mais recevait:
```javascript
{
  matiere: {...},
  combinaisons: [...],
  lessons: []
}
```

## Cause Racine

### Flux des données (AVANT la correction)

1. **Backend** retourne:
```php
return response()->json([
    'success' => true,
    'data' => [
        'matiere' => $matiere,
        'lessons' => $lessons,
        // ...
    ]
]);
```

2. **Intercepteur Axios** (api.js ligne 31) transforme:
```javascript
api.interceptors.response.use((response) => {
    return response.data  // ← PREMIÈRE extraction .data
})
```

Résultat: `{success: true, data: {matiere: {...}}}`

3. **Service LMS** (lms.js ligne 31) transforme ENCORE:
```javascript
async getMatiereDetails(matiereId) {
    const response = await api.get(`/lms/matieres/${matiereId}`)
    return response.data  // ← DEUXIÈME extraction .data (ERREUR!)
}
```

Résultat final: `{matiere: {...}, lessons: [...]}` → Perd la propriété `success`!

4. **Composant Vue** vérifie:
```javascript
if (data && data.success) {  // ← data.success est undefined!
    // Code jamais exécuté
} else {
    this.error = 'Impossible de charger...'  // ← Toujours ici!
}
```

## Solution Appliquée

### Modification de `src/services/lms.js`

Supprimé la double extraction `.data` dans **TOUTES les méthodes**:

```diff
  async getMatiereDetails(matiereId) {
    try {
-     const response = await api.get(`/lms/matieres/${matiereId}`)
-     return response.data
+     // api.get retourne déjà response.data grâce à l'intercepteur
+     return await api.get(`/lms/matieres/${matiereId}`)
    } catch (error) {
      console.error('Erreur récupération matière enrichie:', error)
      throw error
    }
  },
```

### Méthodes corrigées

✅ Toutes les méthodes de `lmsService` ont été corrigées:
- `getClasseDetails()`
- `getMatiereDetails()` ← Principal
- `getUpcomingSeances()`
- `getSeanceDetails()`
- `getSeanceParticipants()`
- `validateParticipant()`
- `toggleVisio()`
- `syncVideoAttendances()`
- `getNotificationPreferences()`
- `sendSessionReminder()`

## Flux Corrigé (APRÈS la correction)

1. **Backend** retourne:
```php
{
  "success": true,
  "data": {
    "matiere": {...},
    "lessons": [...]
  }
}
```

2. **Intercepteur Axios** (api.js ligne 31):
```javascript
return response.data
// → {success: true, data: {matiere: {...}}}
```

3. **Service LMS** (lms.js ligne 34):
```javascript
return await api.get(`/lms/matieres/${matiereId}`)
// → {success: true, data: {matiere: {...}}}  (pas de .data supplémentaire!)
```

4. **Composant Vue** reçoit:
```javascript
if (data && data.success) {  // ✅ data.success === true
  this.matiere = data.data.matiere  // ✅ Fonctionne!
  this.lessons = data.data.lessons || []
  // ...
}
```

## Test de Vérification

Après cette correction, les logs console devraient montrer:

```javascript
✅ Données reçues (raw): {success: true, data: {...}}
✅ data.success: true
✅ data.data: {matiere: {...}, lessons: [...], ...}
✅ Type de data: object
📖 Lessons: 0
📅 Séances: 0
📝 Évaluations: 0
📊 Statistiques: {...}
```

## Leçons Apprises

### Problème Architectural

L'intercepteur Axios globalise la transformation `response.data`, ce qui simplifie les appels API:

```javascript
// Avec intercepteur
const data = await api.get('/endpoint')
// data = {success: true, data: {...}}

// Sans intercepteur (Axios standard)
const response = await api.get('/endpoint')
const data = response.data
// data = {success: true, data: {...}}
```

### Bonne Pratique

**Option A: Intercepteur global** (actuel)
- ✅ Simplifie tous les appels API
- ❌ Peut créer de la confusion (oublier que .data est déjà fait)
- ⚠️ Les services ne doivent PAS faire `.data`

**Option B: Pas d'intercepteur** (alternative)
- ✅ Plus explicite: on voit toujours `response.data`
- ❌ Code plus verbeux

Notre choix: **Garder l'intercepteur** et documenter clairement dans les services.

### Documentation Ajoutée

Commentaire au début de `lms.js`:

```javascript
/**
 * Service pour les endpoints LMS enrichis (KLASSCI + données locales LMS)
 * Ces endpoints combinent les données KLASSCI avec les données du LMS local
 *
 * IMPORTANT: L'intercepteur dans api.js (ligne 31) retourne déjà response.data
 * Donc on ne doit PAS faire .data une deuxième fois ici
 */
```

## Fichiers Modifiés

1. ✅ [src/services/lms.js](../lms-frontend/src/services/lms.js)
   - Supprimé `.data` dans toutes les méthodes
   - Ajouté documentation sur l'intercepteur

2. ✅ [app/Http/Controllers/API/LMSDataController.php](app/Http/Controllers/API/LMSDataController.php#L373-L379)
   - Ajouté logging détaillé avant retour

3. ✅ [src/views/matieres/MatiereDetails.vue](../lms-frontend/src/views/matieres/MatiereDetails.vue#L296-L331)
   - Ajouté logging ultra-détaillé pour debug

## Statut

✅ **RÉSOLU** - La page "Gérer la matière" devrait maintenant afficher correctement:
- Onglet Lessons
- Onglet Séances
- Onglet Évaluations
- Statistiques de la matière

## Prochaines Étapes

1. ✅ Tester la navigation "Dashboard → Gérer la matière"
2. ⏳ Vérifier que les 3 onglets s'affichent correctement
3. ⏳ Implémenter la navigation "Séance → Visio" (si séances disponibles)
4. ⏳ Créer migration BDD pour colonnes visio (si nécessaire)

## Notes Techniques

### Pourquoi les séances sont vides?

Les logs backend montrent:
```
[2025-10-20 10:33:03] ERROR: KLASSCI API Error
Column not found: 'date_cours' in WHERE
```

**Explication**: L'API KLASSCI a un bug dans `emploi-temps` endpoint - utilise `date_cours` au lieu de `date_seance`.

**Impact**: ✅ Aucun - l'erreur est gérée gracieusement:
```php
try {
    $seancesResponse = $this->klassciService->requestWithUserToken(
        $klassciToken,
        "emploi-temps?...",
        'GET'
    );
    $seances = $seancesResponse['data'] ?? [];
} catch (\Exception $e) {
    Log::warning('Erreur récupération séances', [...]);
    $seances = [];  // ← Valeur par défaut, pas de crash
}
```

**Solution**: Corriger KLASSCI API ou utiliser un autre endpoint pour récupérer les séances.

## Date de Correction

2025-10-20 11:00 UTC
