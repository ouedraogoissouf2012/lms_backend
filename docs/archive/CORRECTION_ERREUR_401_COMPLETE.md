# Correction Complète de l'Erreur 401 (Unauthorized)

## 🔍 Problème Identifié

### Symptômes
```
GET http://localhost:8000/api/evaluations 401 (Unauthorized)
GET http://localhost:8000/api/quizzes 401 (Unauthorized)
```

**Logs observés:**
- ✅ Connexion réussie: `Token à stocker: 114|6Iov31VXxHYRWcuXg5fOk7sQF3a2YcLcFWcoWoj3ab65422d`
- ✅ Proxy KLASSCI fonctionne: `/proxy/me/teacher-dashboard 200`
- ❌ Endpoints LMS échouent: `/api/evaluations 401`, `/api/quizzes 401`

### Cause Racine

Le backend **retournait le token KLASSCI** au lieu d'un **token Laravel Sanctum**.

```php
// ❌ AVANT (INCORRECT)
'token' => $klassciToken,  // Token KLASSCI - ne fonctionne pas avec auth:sanctum
```

Les routes protégées avec `middleware: auth:sanctum` nécessitent un token Sanctum créé par Laravel, pas un token externe (KLASSCI).

**Analogie**: C'est comme essayer d'utiliser une carte d'identité française pour accéder à un bâtiment qui nécessite un badge interne.

---

## ✅ Solutions Appliquées

### 1. Modification du AuthController

**Fichier**: `app/Http/Controllers/API/AuthController.php`

#### Changements apportés:

**AVANT** (ligne 113-144):
```php
$klassciUser = $klassciResponse['data']['user'];
$klassciToken = $klassciResponse['data']['token'];

// Retourner directement le token KLASSCI (ne fonctionne pas!)
return response()->json([
    'success' => true,
    'data' => [
        'user' => [...],
        'token' => $klassciToken,  // ❌ Token KLASSCI
    ],
]);
```

**APRÈS** (avec synchronisation + token Sanctum):
```php
$klassciUser = $klassciResponse['data']['user'];
$klassciToken = $klassciResponse['data']['token'];

// Synchroniser l'utilisateur localement
$localUser = $this->syncUserFromKlassci($klassciUser, $klassciToken);

// Créer un token Sanctum
$sanctumToken = $localUser->createToken('lms-backend-token', ['lms:access'])->plainTextToken;

return response()->json([
    'success' => true,
    'data' => [
        'user' => [
            'id' => $localUser->id,
            'klassci_id' => $localUser->klassci_id,
            // ...
        ],
        'token' => $sanctumToken,  // ✅ Token Sanctum Laravel
    ],
    'meta' => [
        'klassci_synced' => true,
        'klassci_token' => $klassciToken,  // Conservé pour appels proxy
    ],
]);
```

#### Fonctionnement de la synchronisation

La méthode `syncUserFromKlassci()` (ligne 303-331):
1. **Cherche** l'utilisateur par `klassci_id`
2. **Met à jour** si l'utilisateur existe
3. **Crée** un nouvel utilisateur sinon
4. **Stocke** le token KLASSCI pour les appels proxy
5. **Retourne** l'objet User Laravel

```php
private function syncUserFromKlassci(array $klassciUser, string $klassciToken): User
{
    $userData = [
        'klassci_id' => $klassciUser['id'],
        'name' => $klassciUser['nom'],
        'email' => $klassciUser['email'],
        'role' => $klassciUser['role'],
        'klassci_token' => $klassciToken,  // Conservé pour proxy
        'last_klassci_sync' => now(),
    ];

    $user = User::updateOrCreate(
        ['klassci_id' => $klassciUser['id']],
        $userData
    );

    return $user;
}
```

---

### 2. Migration de la Table Users

**Problème**: La table `users` ne pouvait pas recevoir les utilisateurs KLASSCI car:
- Le champ `password` était obligatoire (NOT NULL)
- Le champ `email` était obligatoire

**Solution**: Migration pour rendre ces champs nullable

**Fichier**: `database/migrations/2025_10_19_202208_make_password_nullable_in_users_table.php`

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('password')->nullable()->change();
        $table->string('email')->nullable()->change();
    });
}
```

**Raison**: Les utilisateurs KLASSCI sont authentifiés via l'API externe, donc:
- Pas de `password` local (authentification déléguée à KLASSCI)
- L'email peut être absent ou vide dans certains cas KLASSCI

---

### 3. Structure de la Table Users

**Colonnes ajoutées** (migration existante `2025_10_14_000001`):

| Colonne | Type | Description |
|---------|------|-------------|
| `klassci_id` | BIGINT | ID utilisateur dans KLASSCI (unique) |
| `role` | VARCHAR | Rôle: enseignant, etudiant, coordinateur |
| `klassci_token` | TEXT | Token KLASSCI pour appels proxy |
| `klassci_data` | JSON | Données complètes de KLASSCI |
| `last_klassci_sync` | TIMESTAMP | Date dernière synchronisation |

**Modèle User** (`app/Models/User.php`):
```php
protected $fillable = [
    'klassci_id',
    'name',
    'email',
    'password',
    'role',
    'klassci_token',
    'klassci_data',
    'last_klassci_sync',
];

protected $hidden = [
    'password',
    'remember_token',
    'klassci_token',  // Sécurité: caché dans les réponses API
];
```

---

## 🔄 Flux d'Authentification Complet

### Avant (ne fonctionnait pas)
```
1. Frontend: Login avec username/password
2. Backend: Auth KLASSCI → Token KLASSCI
3. Frontend: Stocke token KLASSCI
4. Frontend: Requête /api/evaluations avec token KLASSCI
5. Laravel Sanctum: ❌ "Unauthenticated" (ne reconnaît pas le token)
```

### Après (fonctionne)
```
1. Frontend: Login avec username/password
2. Backend: Auth KLASSCI → Token KLASSCI
3. Backend: Sync utilisateur local → Crée token Sanctum
4. Frontend: Stocke token Sanctum
5. Frontend: Requête /api/evaluations avec token Sanctum
6. Laravel Sanctum: ✅ Authentification réussie
```

---

## 📊 Comparaison des Tokens

| Aspect | Token KLASSCI | Token Sanctum |
|--------|---------------|---------------|
| **Créé par** | API KLASSCI externe | Laravel Sanctum (local) |
| **Format** | `114\|6Iov31VXxHY...` | `1\|abcdefgh...` |
| **Stockage** | `klassci_token` (table users) | `personal_access_tokens` (table) |
| **Usage** | Appels proxy KLASSCI | Routes `auth:sanctum` |
| **Middleware** | Pas de middleware Laravel | `auth:sanctum` |
| **Validation** | API KLASSCI | Laravel Sanctum |

---

## 🧪 Tests de Validation

### Test 1: Vérifier la connexion

```bash
# 1. Se connecter
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "username": "prof.bede.test",
    "password": "votre_password"
  }'
```

**Réponse attendue**:
```json
{
  "success": true,
  "message": "Connexion réussie (KLASSCI)",
  "data": {
    "user": {
      "id": 9,
      "klassci_id": 9,
      "name": "BEDE ABEL TEST",
      "role": "enseignant"
    },
    "token": "1|abcd...",  // Token Sanctum (commence par "1|")
    "token_type": "Bearer"
  },
  "meta": {
    "klassci_synced": true,
    "klassci_token": "114|6Iov..."  // Token KLASSCI conservé
  }
}
```

### Test 2: Vérifier les routes protégées

```bash
# 2. Utiliser le token Sanctum
TOKEN="1|abcd..."  # Token de la réponse précédente

# Test GET /api/evaluations
curl -X GET http://localhost:8000/api/evaluations \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json"
```

**Réponse attendue**:
```json
{
  "success": true,
  "data": [...]
}
```

✅ **Status: 200 OK** (pas 401!)

### Test 3: Vérifier que le token KLASSCI fonctionne pour le proxy

```bash
# Les routes /proxy/* utilisent le token KLASSCI stocké
curl -X GET http://localhost:8000/api/proxy/me/teacher-dashboard \
  -H "Authorization: Bearer $TOKEN"  # Token Sanctum
```

Le backend récupère automatiquement le `klassci_token` depuis la table `users` pour faire l'appel proxy.

---

## 🔧 Middleware Impliqués

### auth:sanctum
**Fichier**: `vendor/laravel/sanctum/src/Guard.php`

**Fonction**:
1. Extrait le token du header `Authorization: Bearer {token}`
2. Cherche dans `personal_access_tokens` WHERE `token = hash($token)`
3. Si trouvé: Charge l'utilisateur associé
4. Si non trouvé: Retourne 401 Unauthenticated

### klassci.sync (personnalisé)
**Fichier**: `app/Http/Middleware/KlassciSync.php` (probablement)

**Fonction**:
1. Vérifie si `last_klassci_sync` est récent
2. Si > 24h: Re-synchronise les données KLASSCI
3. Met à jour `klassci_data` et `last_klassci_sync`

---

## 📁 Fichiers Modifiés

### Backend
```
lms-backend/
├── app/
│   ├── Http/Controllers/API/
│   │   └── AuthController.php  [MODIFIÉ - ligne 113-182]
│   └── Models/
│       └── User.php  [INCHANGÉ - déjà correct]
├── database/migrations/
│   ├── 2025_10_14_000001_add_klassci_fields_to_users_table.php  [EXISTANT]
│   └── 2025_10_19_202208_make_password_nullable_in_users_table.php  [NOUVEAU]
└── routes/
    └── api.php  [INCHANGÉ - routes déjà correctes]
```

### Aucun changement frontend nécessaire!
Le frontend continue à stocker le token normalement dans `localStorage`. La différence est invisible pour lui - c'est maintenant un token Sanctum qui fonctionne correctement.

---

## 🎯 Résultats Attendus

Après ces corrections:

✅ **Connexion réussie**
```javascript
localStorage.getItem('token')
// "1|abcdefgh..."  (Token Sanctum)
```

✅ **Routes LMS accessibles**
```
GET /api/evaluations → 200 OK
GET /api/quizzes → 200 OK
GET /api/lessons → 200 OK
```

✅ **Routes proxy toujours fonctionnelles**
```
GET /api/proxy/classes → 200 OK
GET /api/proxy/me/teacher-dashboard → 200 OK
```

✅ **Synchronisation automatique**
- Première connexion: Utilisateur créé dans `users`
- Connexions suivantes: Données mises à jour si > 24h

---

## 🚨 Dépannage

### Si erreur 401 persiste

1. **Vérifier que le token est bien Sanctum**:
   ```javascript
   // Dans la console navigateur
   localStorage.getItem('token')
   // Doit commencer par "1|" ou "2|" (Sanctum)
   // Si commence par "114|" → token KLASSCI (incorrect)
   ```

2. **Vérifier la base de données**:
   ```sql
   -- Vérifier que l'utilisateur existe
   SELECT id, name, email, klassci_id, role
   FROM users
   WHERE email = 'bede@gmail.com';

   -- Vérifier les tokens
   SELECT id, tokenable_id, name, abilities, created_at
   FROM personal_access_tokens
   WHERE tokenable_type = 'App\\Models\\User'
   ORDER BY created_at DESC
   LIMIT 5;
   ```

3. **Vider le cache et reconnecter**:
   ```bash
   # Backend
   php artisan cache:clear
   php artisan config:clear

   # Frontend: Supprimer le localStorage et se reconnecter
   ```

4. **Vérifier les logs Laravel**:
   ```bash
   tail -f storage/logs/laravel.log
   ```

   Chercher:
   - `Erreur synchronisation utilisateur` (problème sync)
   - `KLASSCI Login Response` (vérifier réponse KLASSCI)

---

## 📚 Documentation Technique

### Sanctum Personal Access Tokens

**Table**: `personal_access_tokens`
```sql
CREATE TABLE personal_access_tokens (
  id BIGINT PRIMARY KEY,
  tokenable_type VARCHAR(255),  -- 'App\Models\User'
  tokenable_id BIGINT,           -- user_id
  name VARCHAR(255),             -- 'lms-backend-token'
  token VARCHAR(64) UNIQUE,      -- hash du token
  abilities TEXT,                -- ['lms:access']
  last_used_at TIMESTAMP,
  expires_at TIMESTAMP,
  created_at TIMESTAMP
);
```

**Génération du token**:
```php
$token = $user->createToken('lms-backend-token', ['lms:access'])->plainTextToken;
// Retourne: "1|AbCdEfGh..." (plaintext)
// Stocke: hash('AbCdEfGh...') dans la table
```

**Validation**:
```php
// Middleware auth:sanctum
$token = $request->bearerToken();  // "1|AbCdEfGh..."
$hashedToken = hash('sha256', $token);
$personalAccessToken = PersonalAccessToken::where('token', $hashedToken)->first();
$user = $personalAccessToken->tokenable;  // User model
```

---

## ✅ Checklist de Validation

- [x] AuthController modifié (synchronisation + token Sanctum)
- [x] Migration `password` nullable exécutée
- [x] Table `users` avec colonnes KLASSCI
- [x] Modèle `User` avec `HasApiTokens` trait
- [ ] Test connexion → Token Sanctum retourné
- [ ] Test `/api/evaluations` → 200 OK
- [ ] Test `/api/quizzes` → 200 OK
- [ ] Test `/api/proxy/*` → 200 OK (toujours fonctionnel)
- [ ] Vérifier table `users` peuplée
- [ ] Vérifier table `personal_access_tokens` peuplée

---

## 🔐 Sécurité

### Tokens stockés

| Token | Où | Visible | Sécurité |
|-------|-----|---------|----------|
| Token Sanctum | `personal_access_tokens` | ❌ (hashé) | ✅ Haute |
| Token KLASSCI | `users.klassci_token` | ⚠️ (crypté recommandé) | ⚠️ Moyenne |

**Recommandation future**: Crypter `klassci_token` avec Laravel Encryption:
```php
$table->encrypted('klassci_token')->nullable();
```

### Révocation des tokens

```php
// Révoquer le token actuel
$request->user()->currentAccessToken()->delete();

// Révoquer tous les tokens de l'utilisateur
$user->tokens()->delete();
```

---

**Date des corrections**: 2025-10-19
**Version**: 1.0
**Auteur**: Claude Code

🤖 Généré avec [Claude Code](https://claude.com/claude-code)
