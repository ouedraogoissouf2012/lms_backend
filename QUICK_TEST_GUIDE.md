# 🚀 Guide Rapide de Test - LMS KLASSCI Backend

**Version :** 1.0.0
**Date :** 14 Octobre 2025
**Temps estimé :** 5-10 minutes

---

## Prérequis

1. **Serveur Laravel démarré :**
   ```bash
   php artisan serve
   ```

2. **MySQL/MariaDB actif :**
   - Base de données `lms_klassci` créée
   - Migrations exécutées

3. **Redis actif (optionnel mais recommandé) :**
   ```bash
   redis-server
   ```

4. **Identifiants KLASSCI valides**

---

## Test Rapide (5 minutes)

### Étape 1 : Vérifier que l'API est en ligne ✅

**Terminal / Postman / Browser :**
```bash
curl http://localhost:8000/api/ping
```

**Résultat attendu :**
```json
{
  "success": true,
  "message": "LMS KLASSCI Backend API is running!",
  "timestamp": "2025-10-14T...",
  "version": "1.0.0"
}
```

✅ **Si OK → Continuer | ❌ Si erreur → Vérifier serveur Laravel**

---

### Étape 2 : Login (Authentification) ✅

**Request :**
```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "VOTRE_EMAIL_KLASSCI",
    "password": "VOTRE_MOT_DE_PASSE"
  }'
```

**Résultat attendu :**
```json
{
  "success": true,
  "message": "Connexion réussie",
  "data": {
    "user": {...},
    "token": "1|abcdefgh1234567890...",
    "token_type": "Bearer"
  }
}
```

📝 **COPIER LE TOKEN POUR LES TESTS SUIVANTS !**

✅ **Si OK → Continuer | ❌ Si erreur → Vérifier identifiants KLASSCI**

---

### Étape 3 : Vérifier Profil Utilisateur ✅

**Request (remplacer `YOUR_TOKEN_HERE`) :**
```bash
curl http://localhost:8000/api/auth/me \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

**Résultat attendu :**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "klassci_id": 123,
    "name": "...",
    "email": "...",
    "role": "..."
  }
}
```

✅ **Si OK → Continuer | ❌ Si 401 → Token incorrect**

---

### Étape 4 : Tester Route Protégée (Proxy) ✅

**Request :**
```bash
curl http://localhost:8000/api/proxy/classes \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

**Résultat attendu :**
```json
{
  "success": true,
  "data": {
    "classes": [...]
  }
}
```

✅ **Si OK → Système 100% fonctionnel ! 🎉**
❌ **Si 401 → Token manquant ou invalide**
❌ **Si 500 → Vérifier connexion KLASSCI**

---

### Étape 5 : Tester Route Protégée SANS Token ❌

**Request :**
```bash
curl http://localhost:8000/api/proxy/classes
```

**Résultat attendu (doit échouer) :**
```json
{
  "message": "Unauthenticated."
}
```

✅ **Si 401 → Protection fonctionne correctement !**
❌ **Si 200 → PROBLÈME : Routes non protégées !**

---

## Vérifications Base de Données

### Vérifier Utilisateur Synchronisé

```sql
SELECT id, klassci_id, name, email, role, last_klassci_sync
FROM users;
```

**Résultat attendu :**
- 1 ligne avec vos données
- `klassci_id` rempli
- `last_klassci_sync` récent

---

### Vérifier Token Actif

```sql
SELECT id, tokenable_id, name, LEFT(token, 10) as token_preview, created_at
FROM personal_access_tokens
ORDER BY created_at DESC
LIMIT 5;
```

**Résultat attendu :**
- Au moins 1 token pour votre user
- `created_at` récent

---

## Tests Avancés (Optionnels)

### Test Logout

```bash
curl -X POST http://localhost:8000/api/auth/logout \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

**Vérifier ensuite que le token ne fonctionne plus :**
```bash
curl http://localhost:8000/api/auth/me \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

**Doit retourner 401 Unauthenticated**

---

### Test Refresh Token

1. **Login pour obtenir un token**
2. **Refresh le token :**
```bash
curl -X POST http://localhost:8000/api/auth/refresh \
  -H "Authorization: Bearer YOUR_OLD_TOKEN"
```

3. **Récupérer le nouveau token et l'utiliser**

---

### Test Re-synchronisation Automatique

1. **Forcer obsolescence des données :**
```sql
UPDATE users
SET last_klassci_sync = DATE_SUB(NOW(), INTERVAL 25 HOUR)
WHERE id = 1;
```

2. **Appeler une route protégée :**
```bash
curl http://localhost:8000/api/proxy/classes \
  -H "Authorization: Bearer YOUR_TOKEN"
```

3. **Vérifier les logs Laravel :**
```bash
tail -f storage/logs/laravel.log
```

**Logs attendus :**
```
[info] Re-synchronisation KLASSCI pour user 1
[info] KLASSCI API Request: GET /auth/me
[info] Re-synchronisation KLASSCI réussie pour user 1
```

4. **Vérifier que `last_klassci_sync` est mis à jour :**
```sql
SELECT id, last_klassci_sync FROM users WHERE id = 1;
```

---

## Checklist Complète ✅

### Fonctionnalités de Base
- [ ] API Ping fonctionne
- [ ] Login avec identifiants KLASSCI
- [ ] Token généré et retourné
- [ ] Utilisateur synchronisé en base
- [ ] Profil utilisateur accessible (`/api/auth/me`)

### Sécurité
- [ ] Routes proxy protégées (401 sans token)
- [ ] Routes proxy accessibles avec token
- [ ] Logout révoque le token
- [ ] Token refresh fonctionne

### Middleware de Synchronisation
- [ ] Données utilisateur re-synchronisées si > 24h
- [ ] Logs de re-synchronisation visibles
- [ ] Pas de blocage si KLASSCI indisponible

---

## En Cas de Problème

### Erreur 500 - Internal Server Error

**Vérifier :**
```bash
# Logs Laravel
tail -f storage/logs/laravel.log

# Configuration .env
cat .env | grep KLASSCI

# Permissions
chmod -R 775 storage bootstrap/cache
```

---

### Erreur 401 - Unauthenticated

**Vérifier :**
- Token copié correctement (pas d'espaces)
- Header `Authorization: Bearer {token}` bien formaté
- Token non révoqué (pas de logout entre temps)

---

### Erreur Connexion KLASSCI

**Vérifier :**
```bash
# Tester connexion directe
curl http://presentation.klassci.com/api/lms/structure

# Vérifier configuration
php artisan config:clear
php artisan cache:clear
```

---

## Commandes Utiles

### Redémarrer Tout
```bash
# Caches Laravel
php artisan config:clear
php artisan cache:clear
php artisan route:clear

# Redémarrer serveur
php artisan serve --port=8000
```

---

### Voir les Routes Disponibles
```bash
php artisan route:list --path=api
```

---

### Voir les Middlewares Actifs
```bash
php artisan route:list --path=api/proxy
```

**Résultat attendu :**
```
GET|HEAD   api/proxy/classes ... auth:sanctum,klassci.sync
```

---

## Résultat Final Attendu

Si tous les tests passent :

✅ **Authentification fonctionnelle**
✅ **Routes protégées par tokens**
✅ **Synchronisation automatique active**
✅ **Cache KLASSCI opérationnel**
✅ **Système prêt pour intégration frontend**

---

**Prochaine étape :** Intégrer avec le frontend Angular ou continuer avec JOUR 4 (Tests automatisés)

---

**Bon test ! 🚀**
