# Debug: "Impossible de charger les détails de la matière"

## Statut Actuel (2025-10-20 10:45)

### ✅ Backend fonctionne correctement

Les logs montrent que le backend:
1. ✅ Récupère la matière depuis KLASSCI avec succès (10:33:03)
2. ⚠️ Rencontre une erreur 500 lors de la récupération de l'emploi-temps (colonne `date_cours` n'existe pas dans KLASSCI)
3. ✅ Gère l'erreur gracieusement (catch block) et continue
4. ✅ Récupère les lessons LMS (10:33:04)
5. ✅ Retourne une réponse complète

**Preuve dans les logs**:
```
[2025-10-20 10:33:02] INFO: Récupération détails matière {"matiere_id":2}
[2025-10-20 10:33:03] INFO: KLASSCI API Response {"success":true}
[2025-10-20 10:33:03] ERROR: KLASSCI API Error emploi-temps (date_cours n'existe pas)
[2025-10-20 10:33:04] WARNING: Erreur récupération séances (géré gracieusement)
[2025-10-20 10:33:04] INFO: Lessons LMS récupérés {"count":0}
```

### ❓ Frontend: Cause inconnue

Le frontend affiche "Impossible de charger les détails de la matière" mais on ne sait pas exactement pourquoi:
- Soit la réponse HTTP n'arrive pas au frontend
- Soit `data.success` est `undefined` ou `false`
- Soit une exception est levée dans le service LMS

## Modifications Apportées

### 1. Backend - Logging amélioré

**Fichier**: [app/Http/Controllers/API/LMSDataController.php:360-381](app/Http/Controllers/API/LMSDataController.php#L360-L381)

Ajouté un log détaillé avant le retour de la réponse:
```php
Log::info('✅ Matière details response', [
    'matiere_id' => $matiereId,
    'has_matiere' => !empty($matiere),
    'lessons_count' => count($lessons),
    'seances_count' => count($seances),
    'evaluations_count' => count($evaluations)
]);
```

Ce log apparaîtra dans `storage/logs/laravel.log` à chaque requête réussie.

### 2. Frontend - Logging ultra-détaillé

**Fichier**: [src/views/matieres/MatiereDetails.vue:296-331](../lms-frontend/src/views/matieres/MatiereDetails.vue#L296-L331)

Ajouté des logs console pour détecter exactement où le problème se situe:
```javascript
console.log('✅ Données reçues (raw):', data)
console.log('✅ data.success:', data.success)
console.log('✅ data.data:', data.data)
console.log('✅ Type de data:', typeof data)

// Dans le catch
console.error('❌ Error message:', error.message)
console.error('❌ Error response:', error.response)
console.error('❌ Error response data:', error.response?.data)
```

## Prochaines Étapes

### Étape 1: Recharger le frontend

Puisque `MatiereDetails.vue` a été modifié, le navigateur doit recharger:

```bash
# Option A: Reload automatique (si Vite tourne)
# → Le navigateur devrait recharger automatiquement

# Option B: Forcer le reload
# → Appuyer sur Ctrl+Shift+R dans le navigateur
```

### Étape 2: Reproduire l'erreur avec les nouveaux logs

1. Ouvrir la console navigateur (F12)
2. Se connecter comme enseignant
3. Cliquer sur "Gérer la matière" pour une matière
4. Observer les logs dans la console

### Étape 3: Analyser les logs

#### Cas A: Logs "✅ Données reçues (raw)"

Si vous voyez ces logs, le problème est dans la structure de données:
```javascript
✅ Données reçues (raw): {...}
✅ data.success: undefined (ou false)
```

**Solution**: Vérifier si la réponse a la structure attendue.

#### Cas B: Logs "❌ Erreur chargement matière"

Si vous voyez ces logs, une exception est levée:
```javascript
❌ Erreur chargement matière: TypeError: ...
❌ Error response data: {...}
```

**Solution**: L'API retourne une erreur HTTP (404, 500, etc.).

#### Cas C: Aucun log

Si aucun log n'apparaît, le service `lmsService.getMatiereDetails()` ne s'exécute pas:
- Vérifier que l'import est correct
- Vérifier que la méthode `loadMatiereDetails()` est appelée au mount

### Étape 4: Vérifier les logs backend

En parallèle, vérifier les logs Laravel:

```bash
cd lms-backend
tail -f storage/logs/laravel.log | grep -E "(Récupération détails matière|Matière details response)"
```

Vous devriez voir:
```
[2025-10-20 HH:MM:SS] INFO: Récupération détails matière {"matiere_id":2}
...
[2025-10-20 HH:MM:SS] INFO: ✅ Matière details response {"has_matiere":true,...}
```

Si vous voyez le log "✅ Matière details response", cela confirme que le backend retourne bien les données.

## Solutions Potentielles

### Solution 1: Problème de structure de réponse

Si `data.success` est `undefined`, c'est que `lmsService.getMatiereDetails()` retourne déjà `response.data` (ligne 31 dans lms.js).

La réponse Axios a cette structure:
```javascript
{
  data: {
    success: true,
    data: { matiere: {...}, lessons: [...], ... }
  },
  status: 200,
  ...
}
```

Donc `lmsService.getMatiereDetails()` retourne:
```javascript
{
  success: true,
  data: { matiere: {...}, lessons: [...], ... }
}
```

**C'est correct!** Le code frontend devrait fonctionner.

### Solution 2: Erreur CORS

Si la requête n'arrive pas au backend, vérifier:
```bash
# Dans les logs Vite frontend
GET http://localhost:8000/api/lms/matieres/2
# Statut: 404, 500, ou CORS error?
```

### Solution 3: Token expiré

Si le token Sanctum a expiré:
```javascript
❌ Error response data: { message: "Unauthenticated" }
```

**Solution**: Se reconnecter.

### Solution 4: Route non définie

Si la route `/api/lms/matieres/{id}` n'existe pas dans le backend déployé:
```javascript
❌ Error response: 404 Not Found
```

**Solution**: Vérifier [routes/api.php:72](routes/api.php#L72) contient:
```php
Route::get('/lms/matieres/{id}', [LMSDataController::class, 'matiereDetails']);
```

## Problème KLASSCI: Column 'date_cours' not found

### Contexte

L'API KLASSCI à `http://presentation.klassci.com/api/lms/emploi-temps` a un bug:
```sql
SELECT * FROM esbtp_seance_cours
WHERE date_cours BETWEEN ... -- ❌ Colonne n'existe pas
```

La colonne correcte est probablement `date_seance`.

### Impact

✅ **Aucun impact sur notre LMS** car:
1. L'erreur est gérée par un try-catch (ligne 276-289)
2. La variable `$seances` est définie à `[]` en cas d'erreur (ligne 288)
3. La réponse finale retourne quand même les données disponibles

### Correction (optionnel)

Si vous avez accès au code KLASSCI:

**Fichier probable**: `app/Http/Controllers/API/LMSDataController.php` (côté KLASSCI)

Remplacer:
```php
->whereBetween('date_cours', [$dateDebut, $dateFin])
->orderBy('date_cours', 'asc')
```

Par:
```php
->whereBetween('date_seance', [$dateDebut, $dateFin])
->orderBy('date_seance', 'asc')
```

Mais ce n'est **pas bloquant** pour notre LMS.

## Checklist Debug

- [ ] Frontend rechargé (Ctrl+Shift+R)
- [ ] Console navigateur ouverte (F12)
- [ ] Cliqué sur "Gérer la matière"
- [ ] Logs console observés
- [ ] Logs backend vérifiés (`tail -f storage/logs/laravel.log`)
- [ ] Screenshot des logs console pris
- [ ] Résultat communiqué

## Contact

Après avoir effectué ces tests, communiquer:
1. Les logs console (screenshot ou copier-coller)
2. Le statut HTTP de la requête (onglet Network dans F12)
3. Les dernières lignes de `storage/logs/laravel.log`
