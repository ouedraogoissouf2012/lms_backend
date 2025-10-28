# Guide de Déploiement et Tests - Système Visioconférence

## Statut d'Implémentation

### ✅ Complété (100%)

#### Backend
- [x] API endpoint `/api/lms/matieres/{id}` enrichi avec lessons
- [x] API endpoint `/api/lms/seances/{seanceId}/details`
- [x] API endpoint `/api/lms/seances/{seanceId}/toggle-visio`
- [x] API endpoint `/api/lms/seances/{seanceId}/validate-participant`
- [x] Calcul fenêtre temporelle (H-15min à H+30min)
- [x] Middleware rôle coordinateur
- [x] Model `ESBTPAttendance` avec scopes `finalOnly()` et `videoOnly()`

#### Frontend
- [x] Service `src/services/seance.js` avec toutes les méthodes
- [x] Composant `src/views/matieres/MatiereDetails.vue` (3 onglets)
- [x] Composant `src/views/seances/SeanceDetails.vue` (boutons visio)
- [x] Composant `src/views/coordinateur/SeanceManagement.vue` (toggle visio)
- [x] Routes configurées dans `src/router/index.js`
- [x] Génération liens Jitsi Meet
- [x] Validation temporelle côté client

### ⚠️ À Faire (TODO)

#### Base de Données
- [ ] Ajouter colonnes à `esbtp_seance_cours`:
  ```sql
  ALTER TABLE esbtp_seance_cours
  ADD COLUMN visio_enabled BOOLEAN DEFAULT FALSE,
  ADD COLUMN visio_type VARCHAR(50) NULL,
  ADD COLUMN visio_room_id VARCHAR(255) NULL,
  ADD COLUMN visio_room_status VARCHAR(50) DEFAULT 'pending';
  ```

#### Backend
- [ ] Modifier `toggleVisioSeance()` pour update BDD au lieu de retourner TODO
- [ ] Implémenter `NotificationService::sendSessionReminder()`
- [ ] Créer vue Blade pour email de rappel
- [ ] Ajouter webhook Jitsi (optionnel)

#### Tests
- [ ] Tests unitaires backend
- [ ] Tests end-to-end frontend
- [ ] Tests d'intégration avec KLASSCI API
- [ ] Tests de charge (plusieurs sessions simultanées)

---

## 1. Déploiement Backend

### Étape 1: Migration Base de Données

Créer migration:
```bash
cd lms-backend
php artisan make:migration add_visio_columns_to_seance_cours_table
```

Contenu de la migration:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('esbtp_seance_cours', function (Blueprint $table) {
            $table->boolean('visio_enabled')->default(false);
            $table->string('visio_type', 50)->nullable();
            $table->string('visio_room_id', 255)->nullable();
            $table->string('visio_room_status', 50)->default('pending');
        });
    }

    public function down()
    {
        Schema::table('esbtp_seance_cours', function (Blueprint $table) {
            $table->dropColumn(['visio_enabled', 'visio_type', 'visio_room_id', 'visio_room_status']);
        });
    }
};
```

Exécuter:
```bash
php artisan migrate
```

### Étape 2: Modifier toggleVisioSeance()

**Fichier**: `app/Http/Controllers/API/LMSDataController.php:1041-1046`

Remplacer:
```php
// TODO: Mettre à jour dans la BDD KLASSCI ou locale
return response()->json([
    'success' => true,
    'message' => $enabled ? 'Visioconférence activée avec succès' : 'Visioconférence désactivée',
    'data' => [
        'seance_id' => $seanceId,
        'visio_enabled' => $enabled,
        'visio_type' => $visioType,
        'visio_room_id' => $roomId
    ]
]);
```

Par:
```php
// Mettre à jour dans la BDD locale
try {
    $updated = \DB::table('esbtp_seance_cours')
        ->where('id', $seanceId)
        ->update([
            'visio_enabled' => $enabled,
            'visio_type' => $enabled ? $visioType : null,
            'visio_room_id' => $enabled ? $roomId : null,
            'visio_room_status' => $enabled ? 'pending' : null,
            'updated_at' => now()
        ]);

    if ($updated === 0) {
        return response()->json([
            'success' => false,
            'message' => 'Séance non trouvée'
        ], 404);
    }

    return response()->json([
        'success' => true,
        'message' => $enabled ? 'Visioconférence activée avec succès' : 'Visioconférence désactivée',
        'data' => [
            'seance_id' => $seanceId,
            'visio_enabled' => $enabled,
            'visio_type' => $visioType,
            'visio_room_id' => $roomId
        ]
    ]);
} catch (\Exception $e) {
    \Log::error('Erreur toggle visio:', ['error' => $e->getMessage()]);
    return response()->json([
        'success' => false,
        'message' => 'Erreur lors de la mise à jour'
    ], 500);
}
```

### Étape 3: Vérifier Permissions

Dans `app/Http/Middleware/CheckRole.php`, vérifier que le middleware `role` existe et fonctionne.

---

## 2. Déploiement Frontend

### Étape 1: Installer Dépendances

```bash
cd lms-frontend
npm install
```

### Étape 2: Vérifier Configuration API

**Fichier**: `src/services/api.js`

Vérifier que le baseURL pointe vers le backend:
```javascript
const api = axios.create({
  baseURL: 'http://localhost:8000/api', // Adapter selon environnement
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json'
  }
})
```

### Étape 3: Build & Run

```bash
# Développement
npm run dev

# Production
npm run build
```

---

## 3. Tests Backend

### Test 1: Détails Matière avec Lessons

```bash
# Variables
TOKEN="your_sanctum_token"
MATIERE_ID=123

# Request
curl -X GET \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  http://localhost:8000/api/lms/matieres/$MATIERE_ID

# Vérifications
# ✅ success: true
# ✅ data.matiere existe
# ✅ data.lessons est un array
# ✅ data.seances_programmees est un array
# ✅ data.statistiques.nombre_lessons > 0
```

### Test 2: Détails Séance avec Visio

```bash
# Variables
SEANCE_ID=456

# Request
curl -X GET \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  http://localhost:8000/api/lms/seances/$SEANCE_ID/details

# Vérifications
# ✅ success: true
# ✅ data.seance.duree_minutes calculé
# ✅ data.visio.window existe
# ✅ data.visio.window.can_start est boolean
# ✅ data.participants.total > 0
```

### Test 3: Toggle Visio (Coordinateur)

```bash
# Activer visio
curl -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "enabled": true,
    "visio_type": "jitsi"
  }' \
  http://localhost:8000/api/lms/seances/$SEANCE_ID/toggle-visio

# Vérifications
# ✅ success: true
# ✅ data.visio_enabled: true
# ✅ data.visio_type: "jitsi"
# ✅ data.visio_room_id: "seance_456"

# Désactiver visio
curl -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "enabled": false,
    "visio_type": "jitsi"
  }' \
  http://localhost:8000/api/lms/seances/$SEANCE_ID/toggle-visio

# Vérifications
# ✅ success: true
# ✅ data.visio_enabled: false
```

### Test 4: Validation Participant

```bash
# Variables
USER_ID=789

# Request enseignant autorisé
curl -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": '$USER_ID'
  }' \
  http://localhost:8000/api/lms/seances/$SEANCE_ID/validate-participant

# Vérifications
# ✅ authorized: true
# ✅ user_role: "teacher" ou "student"

# Request utilisateur non autorisé
curl -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": 999999
  }' \
  http://localhost:8000/api/lms/seances/$SEANCE_ID/validate-participant

# Vérifications
# ✅ authorized: false
# ✅ reason existe
```

### Test 5: Sync Attendances Vidéo

```bash
curl -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "seance_cours_id": '$SEANCE_ID',
    "date": "2025-10-25",
    "participants": [
      {
        "user_id": 101,
        "joined_at": "2025-10-25 14:05:00",
        "left_at": "2025-10-25 15:55:00",
        "duration_minutes": 110
      }
    ]
  }' \
  http://localhost:8000/api/lms/attendances/from-video-session

# Vérifications
# ✅ success: true
# ✅ attendances_created > 0
# ✅ Vérifier en BDD: call_type='merged', video_joined_at NOT NULL
```

---

## 4. Tests Frontend

### Test 1: Navigation Matière

**URL**: `http://localhost:5173/matieres/123` (adapter le port)

**Scénario**:
1. Se connecter avec un compte étudiant ou enseignant
2. Naviguer vers `/matieres/123`
3. Vérifier affichage du header avec nom matière
4. Vérifier affichage des 3 onglets: Lessons, Séances, Évaluations
5. Vérifier stats en haut de page

**Vérifications**:
- ✅ Onglet "Lessons" affiche la liste des lessons
- ✅ Barre de progression visible (si étudiant)
- ✅ Onglet "Séances" affiche les séances
- ✅ Badge "📹 Visio jitsi" visible si visio activée
- ✅ Onglet "Évaluations" affiche les évaluations
- ✅ Clic sur une séance → redirection vers `/seances/{id}`

### Test 2: Détails Séance - Vue Enseignant

**Prérequis**:
- Se connecter avec un compte enseignant
- Avoir une séance dans la fenêtre temporelle (H-15min à H+30min)

**URL**: `http://localhost:5173/seances/456`

**Scénario**:
1. Naviguer vers une séance avec visio activée
2. Vérifier affichage section visioconférence
3. Vérifier statut fenêtre temporelle
4. Cliquer sur "Démarrer le cours"
5. Vérifier ouverture Jitsi dans nouvel onglet

**Vérifications**:
- ✅ Section visio visible avec indicateur fenêtre (vert si actif)
- ✅ Bouton "🎥 Démarrer le cours" visible et cliquable
- ✅ Validation participant réussie
- ✅ Lien Jitsi contient le room_id correct
- ✅ Nouvel onglet Jitsi s'ouvre
- ✅ Nom d'affichage correct dans Jitsi
- ✅ Droits modérateur dans Jitsi

### Test 3: Détails Séance - Vue Étudiant

**Prérequis**:
- Se connecter avec un compte étudiant
- Séance démarrée par l'enseignant (roomActive=true)

**URL**: `http://localhost:5173/seances/456`

**Scénario**:
1. Avant H-15min: vérifier message "En attente de l'enseignant"
2. Après démarrage par enseignant: vérifier bouton "Rejoindre le cours"
3. Cliquer sur "Rejoindre le cours"
4. Vérifier ouverture Jitsi

**Vérifications**:
- ✅ Badge orange "⏳ En attente" affiché avant démarrage
- ✅ Bouton "🎥 Rejoindre le cours" visible après démarrage
- ✅ Validation participant réussie
- ✅ Lien Jitsi sans droits modérateur
- ✅ Nouvel onglet Jitsi s'ouvre
- ✅ Nom d'affichage correct

### Test 4: Gestion Séances - Vue Coordinateur

**Prérequis**: Se connecter avec un compte coordinateur

**URL**: `http://localhost:5173/coordinateur/seances`

**Scénario**:
1. Naviguer vers la page de gestion
2. Vérifier affichage de la liste des séances
3. Filtrer par période (changer de 30 à 7 jours)
4. Activer la visio pour une séance
5. Changer le type de visio (Jitsi → Zoom)
6. Désactiver la visio

**Vérifications**:
- ✅ Liste des séances s'affiche
- ✅ Filtres fonctionnent (période, enseignant, classe)
- ✅ Toggle visio fonctionne (changement visuel immédiat)
- ✅ Sélecteur type de visio s'affiche quand activé
- ✅ Room ID s'affiche avec format `seance_{id}`
- ✅ Stats en bas de page se mettent à jour
- ✅ Toast de succès s'affiche

---

## 5. Tests d'Intégration

### Scénario Complet: Cours en Visio

**Acteurs**:
- Coordinateur: Alice
- Enseignant: Bob
- Étudiants: Charlie, Diana

**Timeline**:

#### J-1 (Veille du cours)
1. **Alice (Coordinateur)**: Active la visio pour la séance du lendemain 14h-16h
   - Route: `/coordinateur/seances`
   - Action: Toggle visio ON, type=jitsi
   - ✅ Backend: `visio_enabled=true`, `visio_room_id=seance_456`

#### J à 13:30 (H-30min)
2. **Bob (Enseignant)**: Consulte la séance
   - Route: `/seances/456`
   - ✅ Message: "Vous pourrez démarrer le cours 15 minutes avant l'heure prévue"
   - ✅ Bouton désactivé

3. **Charlie (Étudiant)**: Consulte la séance
   - Route: `/seances/456`
   - ✅ Badge: "⏳ En attente de l'enseignant"
   - ✅ Texte: "Le cours commencera à 14:00"

#### J à 13:45 (H-15min) - Fenêtre s'ouvre
4. **Bob**: Rafraîchit la page
   - ✅ Bouton "🎥 Démarrer le cours" actif (vert)
   - ✅ Indicateur fenêtre: "✅ Fenêtre visio active"

5. **Bob**: Clique sur "Démarrer le cours"
   - ✅ Validation API réussie
   - ✅ Jitsi s'ouvre: `https://meet.jit.si/seance_456#userInfo.displayName=Bob`
   - ✅ Bob est modérateur

#### J à 13:50 (H-10min)
6. **Charlie**: Rafraîchit la page
   - ✅ Bouton "🎥 Rejoindre le cours" actif (vert)
   - ✅ Indicateur: "✅ Fenêtre visio active"

7. **Charlie**: Clique sur "Rejoindre le cours"
   - ✅ Validation API réussie
   - ✅ Jitsi s'ouvre: `https://meet.jit.si/seance_456#userInfo.displayName=Charlie`
   - ✅ Charlie est participant (pas modérateur)

8. **Diana**: Fait de même
   - ✅ Diana rejoint la room

#### J à 14:00-16:00 (Cours en cours)
9. **Tous**: Cours se déroule normalement dans Jitsi
   - ✅ Bob peut mute/unmute tous
   - ✅ Charlie et Diana peuvent seulement se mute eux-mêmes

#### J à 16:00 (Fin du cours)
10. **Bob**: Termine la session Jitsi
    - Jitsi enregistre: `joined_at`, `left_at`, `duration_minutes`

#### J à 16:30 (H+30min) - Fenêtre se ferme
11. **Tout le monde**: Boutons deviennent inactifs
    - ✅ Message: "⏹️ Fenêtre visio fermée" ou "✅ Cours terminé"

#### J+1 (Lendemain)
12. **Admin**: Synchronise les attendances
    - API: `POST /api/lms/attendances/from-video-session`
    - ✅ Création attendances avec `call_type='merged'`
    - ✅ Colonnes `video_*` remplies

---

## 6. Tests de Charge (Optionnel)

### Scénario: 10 Séances Simultanées

**Configuration**:
- 10 séances différentes
- Chacune avec 30 étudiants + 1 enseignant
- Total: 310 utilisateurs simultanés

**Outils**:
- Apache JMeter ou k6
- Monitoring: Laravel Telescope + New Relic

**Métriques à surveiller**:
- Temps de réponse API < 200ms
- CPU backend < 70%
- Mémoire backend < 1GB
- Aucune erreur 500
- Toutes les validations réussissent

---

## 7. Checklist de Déploiement en Production

### Backend
- [ ] Migration BDD exécutée
- [ ] `toggleVisioSeance()` modifié pour update BDD
- [ ] Variables d'environnement configurées:
  ```env
  KLASSCI_API_URL=https://api.klassci.com
  KLASSCI_API_KEY=xxx
  JITSI_DOMAIN=meet.jit.si
  ```
- [ ] Cache configuré (Redis)
- [ ] Queue configuré pour notifications
- [ ] Logs configurés (Sentry, Papertrail)

### Frontend
- [ ] Build production créé (`npm run build`)
- [ ] Variables d'environnement configurées:
  ```env
  VITE_API_URL=https://api.votredomaine.com/api
  ```
- [ ] Assets déployés sur CDN (optionnel)
- [ ] HTTPS activé
- [ ] Service Worker pour PWA (optionnel)

### Infrastructure
- [ ] Serveur web configuré (Nginx/Apache)
- [ ] SSL/TLS certificat installé
- [ ] Firewall configuré (ports 80, 443, 8000 si nécessaire)
- [ ] Backups automatiques activés
- [ ] Monitoring activé (Uptime Robot, Pingdom)

### Sécurité
- [ ] CORS configuré correctement
- [ ] Rate limiting activé
- [ ] CSRF protection activée
- [ ] XSS protection activée
- [ ] SQL injection protection (Eloquent ORM)

---

## 8. Troubleshooting

### Problème 1: Bouton "Démarrer le cours" désactivé

**Symptômes**: Enseignant ne peut pas cliquer sur le bouton

**Causes possibles**:
1. Pas dans la fenêtre temporelle (H-15min à H+30min)
2. `visio.window.can_start = false`
3. Date/heure serveur incorrecte

**Solutions**:
```javascript
// Dans console navigateur
console.log('Now:', new Date())
console.log('Séance début:', new Date(`${seance.date_seance} ${seance.heure_debut}`))
console.log('Window:', visio.window)

// Vérifier fuseau horaire serveur
// Backend: php artisan tinker
>> now()
```

### Problème 2: Validation participant échoue

**Symptômes**: Message "Accès refusé"

**Causes possibles**:
1. Utilisateur pas dans la classe
2. Enseignant pas assigné à la séance
3. Séance inexistante

**Solutions**:
```bash
# Vérifier dans KLASSCI ou BDD locale
SELECT * FROM esbtp_seance_cours WHERE id = 456;
SELECT * FROM esbtp_inscription_seances WHERE seance_id = 456 AND etudiant_id = 101;
```

### Problème 3: Jitsi ne s'ouvre pas

**Symptômes**: Clic sur bouton ne fait rien

**Causes possibles**:
1. Popup bloqué par navigateur
2. Lien malformé
3. Erreur JavaScript

**Solutions**:
```javascript
// Console navigateur
console.log('Lien généré:', link)

// Vérifier popup blocker
window.open('https://meet.jit.si/test', '_blank')

// Autoriser popups pour le domaine
// Chrome: Paramètres > Confidentialité > Popups > Autoriser [votredomaine.com]
```

### Problème 4: Attendances pas créées

**Symptômes**: Après sync, attendances manquantes

**Causes possibles**:
1. Payload incorrect
2. Étudiant pas inscrit à la séance
3. Erreur BDD

**Solutions**:
```bash
# Tester manuellement
curl -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "seance_cours_id": 456,
    "date": "2025-10-25",
    "participants": [...]
  }' \
  http://localhost:8000/api/lms/attendances/from-video-session

# Vérifier logs Laravel
tail -f storage/logs/laravel.log

# Vérifier BDD
SELECT * FROM esbtp_attendances
WHERE seance_cours_id = 456
  AND call_type = 'merged'
  AND video_joined_at IS NOT NULL;
```

---

## 9. Support et Documentation

### Ressources
- Documentation Jitsi Meet: https://jitsi.github.io/handbook/
- Documentation Vue Router: https://router.vuejs.org/
- Documentation Laravel Sanctum: https://laravel.com/docs/sanctum
- Documentation KLASSCI API: [Lien interne]

### Contacts
- Support technique LMS: support@votredomaine.com
- Support KLASSCI: support@klassci.com

---

## Conclusion

Ce guide couvre:
✅ Déploiement backend (migrations, code)
✅ Déploiement frontend (build, config)
✅ Tests unitaires backend (5 tests)
✅ Tests fonctionnels frontend (4 tests)
✅ Test d'intégration complet (scénario réel)
✅ Tests de charge (optionnel)
✅ Checklist déploiement production
✅ Guide troubleshooting

**Prochaine étape**: Exécuter les tests et déployer en staging avant production.
