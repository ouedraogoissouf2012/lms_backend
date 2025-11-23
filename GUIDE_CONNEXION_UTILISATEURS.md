# GUIDE: Comment se connecter au LMS

**Format d'identifiants**: `prenom.nom` (PAS l'email complet)

---

## 📋 FORMAT DES IDENTIFIANTS

### ✅ FORMAT CORRECT (Klassci)

Le système Klassci utilise le format **`prenom.nom`** comme identifiant:

```
Username: prenom.nom
Password: [mot de passe Klassci]
```

### ❌ FORMAT INCORRECT

Ne PAS utiliser l'email complet:
```
❌ prenom.nom@esbtp.edu
❌ prenom.nom@test.com
```

---

## 👥 EXEMPLES PAR UTILISATEUR

### 1. Issouf Ouedraogo (Étudiant)

**Identifiants**:
```
Username: issouf.ouedraogo
Password: [mot de passe Klassci]
```

**Email enregistré**: `issouf.ouedraogo@esbtp.edu` (pour info seulement)

---

### 2. MARCEL OUEDRAOGO (Étudiant)

**Identifiants**:
```
Username: marcel.ouedraogo
Password: [mot de passe Klassci]
```

**Email enregistré**: `marcel.ouedraogo@esbtp.edu` (pour info seulement)

---

### 3. Enseignants

**Format général**:
```
Username: prenom.nom
Password: [mot de passe Klassci]
```

**Exemples**:
- `bede.abel` (si le nom complet est BEDE ABEL)
- `jean.dupont` (si le nom est Jean Dupont)

---

## 🔍 VÉRIFICATION DE VOTRE USERNAME

Si vous ne connaissez pas votre username, regardez votre email:

| Email | Username à utiliser |
|-------|---------------------|
| issouf.ouedraogo@esbtp.edu | `issouf.ouedraogo` |
| marcel.ouedraogo@esbtp.edu | `marcel.ouedraogo` |
| jean.dupont@esbtp.edu | `jean.dupont` |
| prenom.nom@domain.com | `prenom.nom` |

**Règle simple**: Prenez la partie AVANT le `@`

---

## 🌐 PROCESSUS DE CONNEXION

### Frontend LMS

1. Allez sur la page de connexion du LMS
2. Entrez votre username: `prenom.nom`
3. Entrez votre mot de passe Klassci
4. Cliquez sur "Se connecter"

### API (pour tests)

**Endpoint**: `POST /api/auth/login`

**Payload JSON**:
```json
{
  "username": "issouf.ouedraogo",
  "password": "votre_mot_de_passe"
}
```

**Exemple avec cURL**:
```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{
    "username": "issouf.ouedraogo",
    "password": "MOT_DE_PASSE"
  }'
```

---

## 🔐 QUE SE PASSE-T-IL LORS DE LA CONNEXION?

### Étape 1: Tentative locale
Le système essaie d'abord de vous trouver localement (rapide).

### Étape 2: Authentification Klassci
Si non trouvé localement, le système envoie vos identifiants à Klassci:
```
POST https://api.klassci.com/auth/login
{
  "username": "issouf.ouedraogo",
  "password": "***"
}
```

### Étape 3: Validation Klassci
Klassci vérifie vos identifiants et renvoie:
```json
{
  "success": true,
  "data": {
    "user": {
      "id": 2,
      "nom": "Issouf Ouedraogo",
      "email": "issouf.ouedraogo@esbtp.edu",
      "role": "etudiant"
    },
    "token": "..."
  }
}
```

### Étape 4: Création/Mise à jour locale
Le LMS crée ou met à jour votre compte local automatiquement.

### Étape 5: Génération du token
Le LMS génère un token Sanctum pour vous.

### Étape 6: ✅ Connexion réussie
Vous êtes connecté et redirigé vers votre dashboard.

---

## ⚠️ PROBLÈMES COURANTS

### 1. "Identifiants incorrects"

**Causes possibles**:
- ❌ Vous avez utilisé l'email complet au lieu de `prenom.nom`
- ❌ Mauvais mot de passe
- ❌ Compte inexistant dans Klassci

**Solutions**:
- ✅ Utilisez `prenom.nom` (sans @domain.com)
- ✅ Vérifiez votre mot de passe dans Klassci
- ✅ Demandez à l'admin de créer votre compte Klassci

---

### 2. "Éjecté après connexion"

**Cause**: Conflit de `klassci_id` (résolu dans la dernière mise à jour)

**Solution**: Ce problème est maintenant corrigé. Réessayez de vous connecter.

---

### 3. "Token expiré"

**Cause**: Votre session a expiré.

**Solution**: Reconnectez-vous normalement.

---

## 📝 RÉSUMÉ RAPIDE

### Pour se connecter au LMS:

1. **Username**: `prenom.nom` (PAS l'email)
2. **Password**: Votre mot de passe Klassci
3. **Format**: Minuscules avec un point entre prénom et nom

### Exemples:
- ✅ `issouf.ouedraogo`
- ✅ `marcel.ouedraogo`
- ✅ `jean.dupont`
- ❌ `issouf.ouedraogo@esbtp.edu`
- ❌ `Issouf.Ouedraogo` (majuscules)

---

## 🧪 TEST DE CONNEXION

### Pour Issouf:

**Username à utiliser**: `issouf.ouedraogo`

**Test cURL**:
```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{
    "username": "issouf.ouedraogo",
    "password": "VOTRE_MOT_DE_PASSE_KLASSCI"
  }'
```

**Résultat attendu**:
```json
{
  "success": true,
  "message": "Connexion réussie (KLASSCI)",
  "data": {
    "user": {
      "id": 5,
      "klassci_id": 2,
      "name": "Issouf Ouedraogo",
      "email": "issouf.ouedraogo@esbtp.edu",
      "role": "etudiant"
    },
    "token": "1|xxxxxxxxxxxxxxxx",
    "token_type": "Bearer"
  }
}
```

---

## 📞 SUPPORT

Si vous avez toujours des problèmes:

1. Vérifiez les logs: `storage/logs/laravel.log`
2. Cherchez votre username dans les logs
3. Regardez les erreurs dans la console du navigateur (F12)
4. Contactez l'administrateur avec ces informations

---

**Dernière mise à jour**: 2025-11-18
**Problème de conflit klassci_id**: ✅ RÉSOLU
