# 🔧 SOLUTION - Classes et Matières vides

## ❌ Problème Identifié

Les classes et matières sont vides dans le dashboard car :
1. ✅ Vous êtes maintenant connecté (problème d'auto-déconnexion résolu)
2. ❌ Le token Sanctum ne fonctionne PAS avec les routes `/api/proxy/*`
3. ❌ Les appels `/api/proxy/classes` et `/api/proxy/matieres` retournent "Unauthenticated"

## 🎯 Causes

1. **Token non reconnu par Sanctum** : Le middleware `auth:sanctum` bloque les requêtes
2. **Base de données SQLite** : Peut avoir des problèmes avec Sanctum
3. **Configuration Sanctum** : Peut nécessiter des ajustements

## ✅ SOLUTIONS (par ordre de priorité)

### Solution 1️⃣ : Démarrer MySQL (RECOMMANDÉ)

**MySQL est plus fiable avec Sanctum que SQLite.**

1. **Démarrez XAMPP/Laragon/WAMP**
2. **Créez la base de données** :
   ```sql
   CREATE DATABASE lms_klassci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

3. **Dans `.env`, remplacez** :
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=lms_klassci
   DB_USERNAME=root
   DB_PASSWORD=
   ```

4. **Recréez la base** :
   ```bash
   php artisan config:clear
   php artisan migrate:fresh --seed
   ```

5. **Testez** :
   ```bash
   # Login
   curl -X POST http://127.0.0.1:8000/api/auth/login \
     -H "Content-Type: application/json" \
     -d '{"username":"superadmin","password":"password123"}'

   # Récupérez le token et testez
   curl -X GET http://127.0.0.1:8000/api/proxy/classes \
     -H "Authorization: Bearer VOTRE_TOKEN"
   ```

---

### Solution 2️⃣ : Retirer temporairement `auth:sanctum` des routes proxy

**Si MySQL n'est pas disponible, simplifiez temporairement.**

**Fichier : `routes/api.php`**

**AVANT** :
```php
Route::prefix('proxy')
    ->middleware(['auth:sanctum', 'klassci.sync'])
    ->group(function () {
        Route::get('/classes', [ProxyController::class, 'classes']);
        Route::get('/matieres', [ProxyController::class, 'matieres']);
        // ...
    });
```

**APRÈS** (temporaire) :
```php
Route::prefix('proxy')
    // ->middleware(['auth:sanctum', 'klassci.sync'])  // DÉSACTIVÉ
    ->group(function () {
        Route::get('/classes', [ProxyController::class, 'classes']);
        Route::get('/matieres', [ProxyController::class, 'matieres']);
        // ...
    });
```

⚠️ **Attention** : Cela retire la sécurité ! À utiliser **uniquement en développement local**.

---

### Solution 3️⃣ : Vérifier la configuration Sanctum

**Fichier : `config/sanctum.php`**

Assurez-vous que :
```php
'guard' => ['web'],

'middleware' => [
    'verify_csrf_token' => App\Http\Middleware\VerifyCsrfToken::class,
    'encrypt_cookies' => App\Http\Middleware\EncryptCookies::class,
],
```

Et dans `.env` :
```env
SANCTUM_STATEFUL_DOMAINS=localhost,localhost:5174,127.0.0.1,127.0.0.1:8000
SESSION_DRIVER=database
SESSION_DOMAIN=localhost
```

Puis :
```bash
php artisan config:clear
php artisan cache:clear
```

---

## 🧪 TEST RAPIDE

Une fois MySQL démarré ou le middleware retiré, testez :

```bash
# 1. Login
curl -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"superadmin","password":"password123"}' | jq '.data.token'

# 2. Test classes (copiez le token ci-dessus)
curl -X GET http://127.0.0.1:8000/api/proxy/classes \
  -H "Authorization: Bearer VOTRE_TOKEN" \
  -H "Accept: application/json"
```

Si ça fonctionne, vous verrez :
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "B2 COM",
      ...
    }
  ]
}
```

---

## 📝 Résumé

| Problème | Solution | Priorité |
|----------|----------|----------|
| Token non reconnu | Utiliser MySQL au lieu de SQLite | ⭐⭐⭐ |
| Middleware bloque | Retirer temporairement `auth:sanctum` | ⭐⭐ |
| Config Sanctum | Vérifier `config/sanctum.php` | ⭐ |

**Prochaine étape** : Démarrez MySQL et recréez la base de données !
