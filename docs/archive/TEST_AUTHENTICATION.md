# Tests d'Authentification - LMS KLASSCI Backend

## Prérequis

1. Démarrer le serveur Laravel:
```bash
php artisan serve
```

2. S'assurer que le serveur KLASSCI est accessible: `http://presentation.klassci.com/api/lms`

---

## Test 1: Ping API (Test de base)

### Request
```http
GET http://localhost:8000/api/ping
```

### Réponse Attendue (200 OK)
```json
{
  "success": true,
  "message": "LMS KLASSCI Backend API is running!",
  "timestamp": "2025-10-14T14:30:00.000000Z",
  "version": "1.0.0"
}
```

---

## Test 2: Login (Authentification)

### Request
```http
POST http://localhost:8000/api/auth/login
Content-Type: application/json

{
  "email": "votre-email@example.com",
  "password": "votre-mot-de-passe"
}
```

**Note**: Utiliser les identifiants valides de l'API KLASSCI

### Réponse Attendue (200 OK)
```json
{
  "success": true,
  "message": "Connexion réussie",
  "data": {
    "user": {
      "id": 1,
      "klassci_id": 123,
      "name": "Nom Utilisateur",
      "email": "votre-email@example.com",
      "role": "enseignant"
    },
    "token": "1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
    "token_type": "Bearer"
  },
  "meta": {
    "klassci_synced": true,
    "annee_universitaire_courante": {
      "id": 1,
      "libelle": "2024-2025"
    }
  }
}
```

**IMPORTANT**: Copier le token retourné pour les tests suivants!

---

## Test 3: Me (Profil utilisateur) - PROTÉGÉ

### Request
```http
GET http://localhost:8000/api/auth/me
Authorization: Bearer {TOKEN_DU_LOGIN}
```

### Réponse Attendue (200 OK)
```json
{
  "success": true,
  "data": {
    "id": 1,
    "klassci_id": 123,
    "name": "Nom Utilisateur",
    "email": "votre-email@example.com",
    "role": "enseignant",
    "klassci_data": {
      "id": 123,
      "nom": "Nom",
      "prenom": "Prénom",
      "email": "votre-email@example.com",
      "role": "enseignant"
    }
  }
}
```

---

## Test 4: Check (Vérifier validité du token) - PROTÉGÉ

### Request
```http
GET http://localhost:8000/api/auth/check
Authorization: Bearer {TOKEN_DU_LOGIN}
```

### Réponse Attendue (200 OK)
```json
{
  "success": true,
  "authenticated": true,
  "user": {
    "id": 1,
    "name": "Nom Utilisateur",
    "email": "votre-email@example.com",
    "role": "enseignant"
  }
}
```

---

## Test 5: Refresh Token - PROTÉGÉ

### Request
```http
POST http://localhost:8000/api/auth/refresh
Authorization: Bearer {TOKEN_DU_LOGIN}
```

### Réponse Attendue (200 OK)
```json
{
  "success": true,
  "message": "Token rafraîchi",
  "data": {
    "token": "2|yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy",
    "token_type": "Bearer"
  }
}
```

**Note**: L'ancien token est révoqué, utiliser le nouveau token pour les appels suivants

---

## Test 6: Logout (Déconnexion) - PROTÉGÉ

### Request
```http
POST http://localhost:8000/api/auth/logout
Authorization: Bearer {TOKEN_DU_LOGIN}
```

### Réponse Attendue (200 OK)
```json
{
  "success": true,
  "message": "Déconnexion réussie"
}
```

**Note**: Après logout, le token est révoqué et ne peut plus être utilisé

---

## Test 7: Test Connection Proxy

### Request
```http
GET http://localhost:8000/api/proxy/test-connection
```

### Réponse Attendue (200 OK)
```json
{
  "success": true,
  "message": "Connexion à KLASSCI API réussie",
  "data": {
    "klassci_api_url": "http://presentation.klassci.com/api/lms",
    "status": "connected",
    "timestamp": "2025-10-14T14:30:00.000000Z"
  }
}
```

---

## Erreurs Possibles

### 401 Unauthorized
```json
{
  "success": false,
  "message": "Non authentifié"
}
```
**Cause**: Token invalide, expiré ou non fourni

### 422 Validation Error
```json
{
  "success": false,
  "message": "Données invalides",
  "errors": {
    "email": ["Le champ email est obligatoire"],
    "password": ["Le champ password est obligatoire"]
  }
}
```
**Cause**: Données de requête invalides

### 500 Internal Server Error
```json
{
  "success": false,
  "message": "Erreur lors de la connexion: ..."
}
```
**Cause**: Erreur serveur ou connexion KLASSCI impossible

---

## Test avec Postman

1. Importer la collection `KLASSCI_API_Tests.postman_collection.json` (si elle existe)
2. Créer une variable d'environnement `base_url` = `http://localhost:8000`
3. Créer une variable d'environnement `token` = (sera remplie automatiquement après login)
4. Exécuter les tests dans l'ordre

---

## Test avec cURL

### Login
```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "votre-email@example.com",
    "password": "votre-mot-de-passe"
  }'
```

### Me (avec token)
```bash
curl -X GET http://localhost:8000/api/auth/me \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

### Logout
```bash
curl -X POST http://localhost:8000/api/auth/logout \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

---

## Vérification Base de Données

Après un login réussi, vérifier dans la base de données `lms_klassci`:

```sql
-- Vérifier les utilisateurs synchronisés
SELECT id, klassci_id, name, email, role, last_klassci_sync
FROM users;

-- Vérifier les tokens actifs
SELECT id, tokenable_id, name, abilities, created_at, last_used_at
FROM personal_access_tokens;
```

---

## Test 8: Routes Protégées Sans Token ❌

### Request
```http
GET http://localhost:8000/api/proxy/classes
```

### Réponse Attendue (401 Unauthorized)
```json
{
  "message": "Unauthenticated."
}
```

**Cause**: Tentative d'accès à une route protégée sans token

---

## Test 9: Routes Protégées Avec Token ✅

### Request
```http
GET http://localhost:8000/api/proxy/classes
Authorization: Bearer {TOKEN_DU_LOGIN}
```

### Réponse Attendue (200 OK)
```json
{
  "success": true,
  "data": {
    "classes": [...]
  }
}
```

**Note**: Les routes proxy nécessitent maintenant authentification + synchronisation automatique

---

## Prochaines Étapes

Une fois les tests d'authentification validés:

1. ✅ JOUR 2.1 - Authentification (TERMINÉ)
2. ✅ JOUR 2.2 - Middleware de synchronisation (TERMINÉ)
3. ✅ JOUR 2.3 - Protection des routes proxy (TERMINÉ)
4. 🔄 JOUR 2.4 - Tests API complets

---

**Date**: 2025-10-14
**Version**: 1.0.0
**Backend**: LMS KLASSCI - Sprint 1
