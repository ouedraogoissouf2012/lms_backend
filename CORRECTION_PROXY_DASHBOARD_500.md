# Correction Erreur 500 sur les Dashboards Proxy

## 🔍 Problème Identifié

### Symptômes
```
GET /api/proxy/me/teacher-dashboard → 500 Internal Server Error
Erreur API KLASSCI: 401 - {"message":"Unauthenticated."}
```

### Cause Racine

Le **ProxyController** utilisait le **token Sanctum** de l'header Authorization pour appeler KLASSCI, mais KLASSCI nécessite le **token KLASSCI original**.

**Flux erroné**:
```
1. Utilisateur se connecte → Token Sanctum créé + Token KLASSCI stocké en BDD
2. Requête /proxy/me/teacher-dashboard avec token Sanctum
3. ProxyController prend le token Sanctum de l'header
4. ProxyController envoie le token Sanctum à KLASSCI
5. KLASSCI ne reconnaît pas le token Sanctum → 401 ❌
```

---

## ✅ Solutions Appliquées

### 1. ProxyController - teacherDashboard()

**Fichier**: `app/Http/Controllers/API/ProxyController.php`

**AVANT** (ligne 276-309):
```php
public function teacherDashboard(Request $request): JsonResponse
{
    // Récupérer le token depuis l'en-tête Authorization
    $authHeader = $request->header('Authorization');
    $userToken = substr($authHeader, 7); // Token Sanctum ❌

    // Utiliser le token Sanctum pour appeler KLASSCI (ERREUR!)
    $data = $this->klassciService->requestWithUserToken(
        $userToken,
        'me/teacher-dashboard',
        'GET'
    );
}
```

**APRÈS**:
```php
public function teacherDashboard(Request $request): JsonResponse
{
    // Récupérer l'utilisateur authentifié via Sanctum ✅
    $user = $request->user();

    // Récupérer le token KLASSCI depuis la BDD ✅
    $klassciToken = $user->klassci_token;

    if (!$klassciToken) {
        return $this->errorResponse('Token KLASSCI non trouvé. Veuillez vous reconnecter.', 401);
    }

    // Utiliser le token KLASSCI pour appeler KLASSCI ✅
    $data = $this->klassciService->requestWithUserToken(
        $klassciToken,
        'me/teacher-dashboard',
        'GET'
    );
}
```

### 2. ProxyController - studentDashboard()

**Fichier**: `app/Http/Controllers/API/ProxyController.php`

**Même correction** (ligne 235-271):
```php
public function studentDashboard(Request $request): JsonResponse
{
    // Récupérer l'utilisateur authentifié via Sanctum
    $user = $request->user();

    // Récupérer le token KLASSCI depuis la BDD
    $klassciToken = $user->klassci_token;

    if (!$klassciToken) {
        return $this->errorResponse('Token KLASSCI non trouvé. Veuillez vous reconnecter.', 401);
    }

    // Utiliser le token KLASSCI
    $data = $this->klassciService->requestWithUserToken(
        $klassciToken,
        'me/dashboard',
        'GET'
    );
}
```

### 3. Routes API - Ajout de auth:sanctum

**Fichier**: `routes/api.php`

**AVANT** (ligne 197):
```php
Route::prefix('proxy')->group(function () {
    Route::get('/me/dashboard', [ProxyController::class, 'studentDashboard']);
    Route::get('/me/teacher-dashboard', [ProxyController::class, 'teacherDashboard']);
});
```

**APRÈS**:
```php
Route::prefix('proxy')->middleware('auth:sanctum')->group(function () {
    Route::get('/me/dashboard', [ProxyController::class, 'studentDashboard']);
    Route::get('/me/teacher-dashboard', [ProxyController::class, 'teacherDashboard']);
});
```

**Raison**: Le controller utilise maintenant `$request->user()` qui nécessite l'authentification Sanctum.

---

## 🔄 Flux Corrigé

```
1. Utilisateur se connecte
   → AuthController authentifie via KLASSCI
   → Stocke klassci_token dans users.klassci_token
   → Crée token Sanctum
   → Retourne token Sanctum au frontend

2. Requête /proxy/me/teacher-dashboard
   → Frontend envoie token Sanctum dans Authorization header
   → Middleware auth:sanctum valide et charge l'utilisateur
   → ProxyController récupère $user via $request->user()
   → ProxyController lit klassci_token depuis $user->klassci_token
   → ProxyController appelle KLASSCI avec le klassci_token ✅
   → KLASSCI valide le klassci_token et retourne les données ✅
```

---

## 📊 Table users - Colonnes Impliquées

| Colonne | Type | Description | Utilisation |
|---------|------|-------------|-------------|
| `id` | INT | ID local Laravel | Sanctum tokenable_id |
| `klassci_id` | BIGINT | ID utilisateur KLASSCI | Référence externe |
| `klassci_token` | TEXT | Token KLASSCI original | **Appels proxy** |
| `email` | VARCHAR | Email | Identification |
| `name` | VARCHAR | Nom complet | Affichage |
| `role` | VARCHAR | Rôle (enseignant, etudiant) | Permissions |
| `last_klassci_sync` | TIMESTAMP | Dernière synchro | Cache validity |

**Important**: Le `klassci_token` est utilisé **uniquement** pour les appels proxy vers KLASSCI. Il n'est PAS utilisé pour l'authentification des routes LMS.

---

## 🧪 Tests de Validation

### Test 1: Vérifier que le token KLASSCI est stocké

```bash
# Dans le terminal backend
php artisan tinker
```

```php
// Chercher un utilisateur
$user = App\Models\User::where('email', 'bede@gmail.com')->first();

// Vérifier les tokens
echo "ID: " . $user->id . "\n";
echo "KLASSCI ID: " . $user->klassci_id . "\n";
echo "KLASSCI Token: " . substr($user->klassci_token, 0, 20) . "...\n";
echo "Last sync: " . $user->last_klassci_sync . "\n";
```

**Résultat attendu**:
```
ID: 9
KLASSCI ID: 9
KLASSCI Token: 114|6Iov31VXxHYRWc...
Last sync: 2025-10-19 20:45:32
```

### Test 2: Tester le dashboard après reconnexion

1. **Se déconnecter** (ou vider localStorage)
   ```javascript
   localStorage.clear()
   ```

2. **Se reconnecter** avec vos identifiants

3. **Vérifier dans la console**:
   ```javascript
   const token = localStorage.getItem('token')
   console.log('Token Sanctum:', token) // Doit commencer par "1|" ou "2|"
   ```

4. **Aller sur** `/teacher/dashboard`

5. **Vérifier** qu'il n'y a plus d'erreur 500

### Test 3: Vérifier les logs backend

```bash
# Suivre les logs en temps réel
tail -f storage/logs/laravel.log
```

**Logs attendus** (après reconnexion et navigation):
```
Teacher Dashboard request
  user_id: 9
  klassci_id: 9
  has_klassci_token: true
  token_preview: 114|6Iov31...

KLASSCI API Request (User Token)
  method: GET
  url: http://presentation.klassci.com/api/lms/me/teacher-dashboard
  has_user_token: true

KLASSCI API Response (User Token)
  success: true
```

---

## 🔐 Sécurité: Deux Tokens

### Token Sanctum (authentification LMS)

**Utilisation**: Routes LMS `/api/*`
```
POST /api/evaluations
GET /api/quizzes
PUT /api/lessons/{id}
```

**Stockage**: Table `personal_access_tokens`
**Validation**: Middleware `auth:sanctum`

### Token KLASSCI (proxy vers l'API externe)

**Utilisation**: Appels proxy vers KLASSCI
```
Interne uniquement (depuis le backend)
GET presentation.klassci.com/api/lms/me/teacher-dashboard
GET presentation.klassci.com/api/lms/evaluations
```

**Stockage**: `users.klassci_token` (colonne `TEXT`)
**Validation**: Par l'API KLASSCI externe

**Important**: Le token KLASSCI **n'est jamais** envoyé au frontend. Il reste côté backend pour sécurité.

---

## 🚨 Dépannage

### Si l'erreur 500 persiste

**1. Vérifier que l'utilisateur est en BDD**:
```sql
SELECT id, klassci_id, name, email, role,
       LENGTH(klassci_token) as token_length,
       last_klassci_sync
FROM users
WHERE email = 'bede@gmail.com';
```

**Résultat attendu**:
```
id: 9
klassci_id: 9
token_length: 60 (ou plus)
last_klassci_sync: 2025-10-19 20:45:32
```

**Si `token_length` est NULL** → L'utilisateur doit se reconnecter.

**2. Vérifier les routes**:
```bash
php artisan route:list | grep "teacher-dashboard"
```

**Résultat attendu**:
```
GET|HEAD  api/proxy/me/teacher-dashboard  ... auth:sanctum
```

**Si `auth:sanctum` est absent** → Les modifications de routes ne sont pas appliquées.

**3. Nettoyer le cache**:
```bash
php artisan cache:clear
php artisan route:clear
php artisan config:clear
```

### Si le message "Token KLASSCI non trouvé"

Cela signifie que `users.klassci_token` est NULL pour cet utilisateur.

**Solution**:
1. Se déconnecter
2. Supprimer l'utilisateur de la BDD (optionnel):
   ```sql
   DELETE FROM users WHERE email = 'bede@gmail.com';
   ```
3. Se reconnecter
4. Le AuthController va recréer l'utilisateur avec le token KLASSCI

---

## 📝 Récapitulatif des Modifications

### Fichiers Modifiés

1. **`app/Http/Controllers/API/ProxyController.php`**
   - `teacherDashboard()` - Ligne 276-312
   - `studentDashboard()` - Ligne 235-271
   - Récupère maintenant le token KLASSCI depuis `$user->klassci_token`

2. **`routes/api.php`**
   - Ligne 197-203
   - Ajout de `middleware('auth:sanctum')` sur les routes dashboards

### Fichiers Inchangés (mais critiques)

1. **`app/Http/Controllers/API/AuthController.php`**
   - La méthode `syncUserFromKlassci()` stocke déjà le token KLASSCI ✅

2. **`app/Models/User.php`**
   - Le modèle expose déjà `klassci_token` dans `$fillable` ✅

3. **`database/migrations/2025_10_14_000001_add_klassci_fields_to_users_table.php`**
   - La migration a déjà créé la colonne `klassci_token` ✅

---

## ✅ Checklist de Validation

Après reconnexion:

- [ ] `localStorage.getItem('token')` commence par "1|" ou "2|"
- [ ] La BDD contient l'utilisateur avec `klassci_token` non NULL
- [ ] `/teacher/dashboard` charge sans erreur 500
- [ ] `/student/dashboard` charge sans erreur 500 (pour étudiants)
- [ ] Les logs Laravel montrent "Teacher Dashboard request" avec `has_klassci_token: true`
- [ ] Les évaluations s'affichent correctement
- [ ] La classe et matière s'affichent
- [ ] La date s'affiche

---

## 🎯 Résumé

**Problème**: Les dashboards proxy échouaient car le token Sanctum était envoyé à KLASSCI au lieu du token KLASSCI.

**Solution**:
1. Stocker le token KLASSCI en BDD lors de la connexion ✅
2. Récupérer ce token depuis la BDD pour les appels proxy ✅
3. Protéger les routes dashboard avec `auth:sanctum` ✅

**Action requise**: **Se reconnecter** pour obtenir un nouveau token Sanctum ET que le token KLASSCI soit stocké en BDD.

---

**Date des corrections**: 2025-10-19
**Version**: 1.0
**Auteur**: Claude Code

🤖 Généré avec [Claude Code](https://claude.com/claude-code)
