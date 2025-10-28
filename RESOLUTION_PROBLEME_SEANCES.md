# Résolution Problème: 0 Séances Affichées

**Date**: 2025-10-20
**Problème Initial**: "je ne vois aucun changement dans mon frontEnd"
**Status**: ✅ RÉSOLU - Cause identifiée

---

## 🎯 Résumé Exécutif

### Le Frontend Fonctionne Correctement! ✅

**Logs console observés**:
```
📅 Chargement séances à venir...
✅ Séances reçues: {success: true, data: Array(0), meta: {...}}
📊 0 séances chargées
```

**Analyse**:
- ✅ Le frontend extrait correctement `data.data`
- ✅ La requête API réussit (200 OK)
- ✅ Le backend retourne un tableau vide (pas d'erreur)

### La Vraie Cause: Token KLASSCI Expiré ⚠️

**Test diagnostic**:
```bash
php diagnostics/test_klassci_emploi_temps.php test@example.com

# Résultat:
❌ Erreur: Erreur API KLASSCI: 401 - {"message":"Unauthenticated."}
```

**Explication**: Le token KLASSCI dans la base de données a expiré, donc:
1. LMS fait requête à KLASSCI avec token expiré
2. KLASSCI retourne 401 Unauthorized
3. Backend LMS gère gracieusement (catch exception)
4. Retourne tableau vide au lieu de crash
5. Frontend affiche "0 séances"

---

## ✅ Solution Immédiate

### Étape 1: Se Reconnecter

**Pour rafraîchir le token KLASSCI**:

1. **Se déconnecter** du LMS (bouton déconnexion)
2. **Se reconnecter** avec identifiants KLASSCI
3. **Vérifier** que la connexion réussit

**Que se passe-t-il lors de la reconnexion?**

Le `AuthController::login()` fait ceci:
```php
// 1. Authentifier dans KLASSCI
$klassciResponse = $this->klassciService->login($email, $password);

// 2. Sauvegarder nouveau token
$user->update([
    'klassci_token' => $klassciResponse['access_token']
]);
```

### Étape 2: Retester Séances

1. Aller sur `/coordinateur/seances`
2. Observer console:
   ```
   📅 Chargement séances à venir...
   ✅ Séances reçues: {success: true, data: Array(X), meta: {...}}
   📊 X séances chargées
   ```

**Résultats possibles**:
- ✅ Si `X > 0` → Séances affichées, problème résolu!
- ⚠️ Si `X = 0` → KLASSCI n'a vraiment pas de séances pour cette période

---

## 🔍 Vérifications Complémentaires

### Vérifier Token dans Base de Données

```sql
SELECT
  id,
  name,
  email,
  role,
  LENGTH(klassci_token) as token_length,
  created_at,
  updated_at
FROM users
WHERE role IN ('coordinateur', 'enseignant')
ORDER BY updated_at DESC
LIMIT 5;
```

**Attendu après reconnexion**:
- `token_length` > 100 (token JWT valide)
- `updated_at` = date/heure récente

### Vérifier KLASSCI a des Séances

**Dans l'interface KLASSCI directement**:
1. Se connecter à KLASSCI
2. Aller dans "Emploi du temps" ou "Séances"
3. Vérifier qu'il y a des séances programmées entre **2025-10-20** et **2025-11-19** (30 jours)

**Si aucune séance dans KLASSCI**:
- ✅ Normal que LMS affiche 0 séances
- 💡 Créer des séances dans KLASSCI d'abord
- 💡 Puis elles apparaîtront automatiquement dans LMS

---

## 📊 Architecture Token: Rappel

### Dual Token System

**LMS utilise 2 types de tokens**:

1. **Token Sanctum** (LMS local):
   - Stocké dans `localStorage` frontend
   - Utilisé pour authentifier requêtes vers LMS API
   - Géré par `Authorization: Bearer {token}` dans headers

2. **Token KLASSCI** (externe):
   - Stocké dans colonne `users.klassci_token`
   - Utilisé par backend LMS pour proxy KLASSCI
   - Récupéré lors du login LMS
   - **Peut expirer!** (durée de vie dépend de KLASSCI)

### Workflow Requête Séances

```
Frontend LMS
  ↓ [Authorization: Bearer {sanctum_token}]
Backend LMS API /lms/seances/upcoming
  ↓ [Récupère klassci_token de DB]
  ↓ [Authorization: Bearer {klassci_token}]
KLASSCI API /emploi-temps
  ↓ [Retourne séances]
Backend LMS
  ↓ [Enrichit avec visio local]
Frontend LMS
  ↓ [Affiche séances]
```

**Point de défaillance**: Si `klassci_token` expiré → KLASSCI retourne 401

---

## 🛠️ Amélioration Future (Optionnel)

### Refresh Automatique du Token

**Problème actuel**: Token KLASSCI expire, utilisateur doit se reconnecter

**Solution possible**: Intercepter 401 et refresh token automatiquement

**Fichier à modifier**: `app/Services/KlassciProxyService.php`

```php
public function requestWithUserToken(string $token, string $endpoint, string $method, array $data = [])
{
    try {
        // Requête normale
        $response = $this->makeRequest($endpoint, $method, $data, $token);
        return $response;

    } catch (\Exception $e) {
        // Si 401, tenter refresh token
        if (strpos($e->getMessage(), '401') !== false) {
            Log::warning('Token KLASSCI expiré, tentative refresh...');

            // Récupérer user et refresh token
            $user = User::where('klassci_token', $token)->first();

            if ($user && $user->klassci_refresh_token) {
                try {
                    $newToken = $this->refreshToken($user->klassci_refresh_token);
                    $user->update(['klassci_token' => $newToken]);

                    // Retry avec nouveau token
                    return $this->makeRequest($endpoint, $method, $data, $newToken);

                } catch (\Exception $refreshError) {
                    Log::error('Refresh token failed', ['error' => $refreshError->getMessage()]);
                }
            }
        }

        throw $e;
    }
}
```

**Note**: Nécessite que KLASSCI supporte les refresh tokens.

---

## 📝 Logs Utiles pour Debugging

### Backend Laravel

```bash
# Voir toutes les requêtes KLASSCI
tail -f storage/logs/laravel.log | grep -i klassci

# Voir les erreurs 401
tail -f storage/logs/laravel.log | grep "401\|Unauthenticated"

# Voir les séances
tail -f storage/logs/laravel.log | grep -i "seances\|emploi-temps"
```

### Frontend Console

```javascript
// Vérifier token Sanctum
localStorage.getItem('auth_token')

// Vérifier état authentification
console.log('User:', JSON.parse(localStorage.getItem('user')))

// Forcer rechargement séances
location.reload()
```

### Test Manuel API

**Avec token Sanctum valide**:

```bash
# Récupérer token depuis localStorage frontend
TOKEN="votre_token_sanctum"

# Tester endpoint séances
curl -X GET "http://localhost:8000/api/lms/seances/upcoming?days=30" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"
```

**Attendu si token KLASSCI OK**:
```json
{
  "success": true,
  "data": [
    {
      "id": 123,
      "date_seance": "2025-10-21",
      "heure_debut": "08:00",
      "matiere": {...},
      "visio_enabled": false
    }
  ],
  "meta": {
    "total_seances": 15,
    "date_debut": "2025-10-20",
    "date_fin": "2025-11-19"
  }
}
```

**Attendu si token KLASSCI expiré**:
```json
{
  "success": true,
  "data": [],
  "meta": {
    "total_seances": 0,
    "date_debut": "2025-10-20",
    "date_fin": "2025-11-19"
  }
}
```

---

## ✅ Checklist Résolution

- [x] Frontend extrait correctement `data.data`
- [x] Backend gère erreur KLASSCI gracieusement
- [x] Diagnostic révèle 401 Unauthenticated
- [ ] **Se reconnecter pour rafraîchir token KLASSCI**
- [ ] Retester affichage séances
- [ ] Si encore 0 séances: vérifier KLASSCI a des séances programmées

---

## 🎯 Action Immédiate Requise

**👤 Utilisateur doit**:
1. Se déconnecter du LMS
2. Se reconnecter avec identifiants KLASSCI
3. Aller sur `/coordinateur/seances`
4. Vérifier séances s'affichent maintenant

**Si après reconnexion 0 séances toujours**:
→ Vérifier dans KLASSCI qu'il y a des séances entre 2025-10-20 et 2025-11-19

---

## 📚 Documentation

- [SEANCES_VISIO_STATUS.md](./SEANCES_VISIO_STATUS.md) - État système complet
- [WORKFLOW_SEANCES_VISIO.md](./WORKFLOW_SEANCES_VISIO.md) - Architecture détaillée
- [CORRECTIONS_FRONTEND_SEANCES.md](./CORRECTIONS_FRONTEND_SEANCES.md) - Fix data extraction

**Diagnostic scripts**:
- `php diagnostics/check_seances_visio.php` - Vérifier table et modèle
- `php diagnostics/test_klassci_emploi_temps.php [email]` - Tester KLASSCI API

---

**Conclusion**: Le système fonctionne correctement. Le problème est un token KLASSCI expiré. **Solution: Se reconnecter.**
