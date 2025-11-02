# Implémentation complète de la Visioconférence

**Date**: 2025-10-20
**Version**: 1.0
**Statut**: ✅ Implémentation complète

---

## Vue d'ensemble

Système complet de gestion de visioconférences Jitsi Meet intégré au LMS, avec:
- Programmation par coordinateur
- Démarrage par enseignant avec fenêtre temporelle (-15min à +30min)
- Participation étudiante
- Tracking automatique des présences
- Synchronisation vers KLASSCI

---

## Architecture

### Frontend (Vue.js)

#### 1. **VisioManager.vue** (Composant principal)
**Chemin**: `lms-frontend/src/components/visio/VisioManager.vue`

**Responsabilités**:
- Afficher les boutons appropriés selon le rôle (coordinateur, enseignant, étudiant)
- Gérer la fenêtre temporelle (-15min à +30min)
- Ouvrir Jitsi dans nouvelle fenêtre
- Tracker join/leave des participants

**Boutons affichés**:
```
COORDINATEUR:
  - "Programmer en visio" (si visio_enabled = false)
  - "Désactiver visio" (si visio_enabled = true)

ENSEIGNANT:
  - Badge: "Visio programmée - Disponible dans X min"
  - "Démarrer la visio" (actif uniquement pendant fenêtre temporelle)

ÉTUDIANT:
  - Badge: "Visio en cours" (animation pulse)
  - "Rejoindre la visio" (actif uniquement si visio_active = true)

TOUS:
  - "Participants (X)" - ouvre ParticipantsModal
```

**Props**:
- `seance` (Object): Objet séance complet avec infos visio

**Events émis**:
- `@visio-updated`: Quand visio programmée/désactivée
- `@visio-started`: Quand enseignant démarre la visio

**Computed properties**:
```javascript
isInTimeWindow() {
  // Vérifie si heure actuelle dans [-15min, +30min] de la séance
  const windowStart = debut - 15 minutes
  const windowEnd = fin + 30 minutes
  return now >= windowStart && now <= windowEnd
}
```

#### 2. **ParticipantsModal.vue**
**Chemin**: `lms-frontend/src/components/visio/ParticipantsModal.vue`

**Responsabilités**:
- Afficher l'enseignant responsable
- Lister tous les étudiants autorisés
- Afficher le statut online/offline (si disponible)

**API utilisée**:
```javascript
GET /api/lms/seances/{seanceId}/participants
→ {
  success: true,
  data: {
    teacher: { id, name, email },
    students: [{ id, name, email, matricule, online }],
    total: 25
  }
}
```

#### 3. **jitsi.js** (Service)
**Chemin**: `lms-frontend/src/services/jitsi.js`

**Méthodes principales**:

```javascript
// Générer lien Jitsi unique
generateRoomLink(seance)
→ "https://meet.jit.si/LMS-Mathematiques-L1InfoA-2025-10-20-abc123?params..."

// Format room name: LMS-{Matiere}-{Classe}-{Date}-{RoomID}
buildRoomName(seance, roomId)

// Tracker join participant
trackParticipantJoin(seanceId, userId)
→ Stocke dans localStorage: visio_participation_{seanceId}_{userId}

// Tracker leave participant
trackParticipantLeave(seanceId, userId)
→ Calcule durée, sync vers backend

// Synchroniser participation vers KLASSCI
syncParticipation(seanceId, participation)
→ POST /api/lms/attendances/from-video-session

// Nettoyer participations expirées (> 7 jours)
cleanupExpiredParticipations()
```

**Tracking localStorage**:
```json
{
  "seance_id": 123,
  "user_id": 456,
  "joined_at": "2025-10-20T10:05:00Z",
  "left_at": "2025-10-20T11:35:00Z",
  "duration_minutes": 90
}
```

#### 4. **lms.js** (Service API)
**Chemin**: `lms-frontend/src/services/lms.js`

**Méthodes ajoutées**:
```javascript
// Récupérer étudiants classe
getClasseEtudiants(classeId)

// Toggle visio pour séance
toggleVisio(seanceId, enabled, visioType)

// Valider participant
validateParticipant(seanceId, userId)

// Détails séance avec infos visio
getSeanceDetails(seanceId)

// Participants autorisés
getSeanceParticipants(seanceId)

// Sync attendances depuis vidéo
syncVideoAttendances(seanceId, date, participants)
```

---

### Backend (Laravel)

#### 1. **Migration BDD**
**Fichier**: `database/migrations/2025_10_20_add_visio_columns_to_seances.php`

**Colonnes ajoutées à `esbtp_seance_cours`**:
```php
visio_enabled         BOOLEAN DEFAULT false   // Programmée par coordinateur
visio_type            ENUM('jitsi', 'zoom', 'teams', 'bbb') NULLABLE
visio_room_id         VARCHAR(255) NULLABLE   // Room ID unique
visio_active          BOOLEAN DEFAULT false   // En cours (démarrée par enseignant)
visio_started_at      TIMESTAMP NULLABLE      // Heure démarrage
visio_ended_at        TIMESTAMP NULLABLE      // Heure fin
```

**Index**:
```sql
INDEX(visio_enabled, visio_active)
```

**Migration**:
```bash
cd lms-backend
php artisan migrate
```

#### 2. **LMSDataController.php**
**Fichier**: `app/Http/Controllers/API/LMSDataController.php`

**Endpoint ajouté**:
```php
/**
 * GET /api/lms/classes/{classeId}/etudiants
 */
public function classeEtudiants(int $classeId, Request $request): JsonResponse
{
    // Récupère étudiants via KLASSCI
    // Filtre uniquement actifs
    // Retourne: { success, data: { etudiants, total } }
}
```

**Endpoints visio existants** (vérifiés):
```php
// Toggle visio
POST /api/lms/seances/{seanceId}/toggle-visio
→ toggleVisioSeance($seanceId, Request)

// Participants
GET /api/lms/seances/{seanceId}/participants
→ seanceParticipants($seanceId, Request)

// Valider participant
POST /api/lms/seances/{seanceId}/validate-participant
→ validateParticipant($seanceId, Request)

// Sync attendances
POST /api/lms/attendances/from-video-session
→ syncAttendancesFromVideoSession(Request)

// Détails séance
GET /api/lms/seances/{seanceId}/details
→ seanceDetails($seanceId, Request)
```

#### 3. **routes/api.php**
**Fichier**: `routes/api.php`

**Route ajoutée**:
```php
Route::middleware(['auth:sanctum', 'klassci.sync'])->prefix('lms')->group(function () {
    // Classes
    Route::get('/classes/{classeId}', [LMSDataController::class, 'classeDetails'])
        ->name('lms.classes.details');

    Route::get('/classes/{classeId}/etudiants', [LMSDataController::class, 'classeEtudiants'])
        ->name('lms.classes.etudiants'); // ← NOUVELLE ROUTE

    // ... autres routes
});
```

---

## Intégration dans les pages

### 1. **ClasseDetails.vue**
**Chemin**: `lms-frontend/src/views/classes/ClasseDetails.vue`

**Modifications**:
```vue
<template>
  <!-- Section "Séances à venir" -->
  <div v-for="seance in seances">
    <!-- Remplacé les boutons coordinateur par: -->
    <VisioManager :seance="seance" @visio-updated="loadSeances" />
  </div>
</template>

<script>
import VisioManager from '@/components/visio/VisioManager.vue'

export default {
  components: { VisioManager }
}
</script>
```

### 2. **MatiereDetails.vue**
**Chemin**: `lms-frontend/src/views/matieres/MatiereDetails.vue`

**Modifications**:
```vue
<template>
  <!-- Onglet Séances -->
  <div v-for="seance in seances">
    <!-- Ajouté VisioManager -->
    <VisioManager :seance="seance" @visio-updated="loadMatiereDetails" />
  </div>
</template>

<script>
import VisioManager from '@/components/visio/VisioManager.vue'

export default {
  components: { VisioManager }
}
</script>
```

---

## Workflow complet

### Étape 1: Programmation (Coordinateur)
```
1. Coordinateur va dans "Classe → Séances à venir"
2. Voit bouton "Programmer en visio"
3. Clique → Confirmation
4. Backend: POST /api/lms/seances/{id}/toggle-visio
   → visio_enabled = true
   → visio_type = 'jitsi'
   → visio_room_id = 'lms_seance_123_1729421000'
5. UI: Badge "Visio jitsi activée" apparaît
```

### Étape 2: Démarrage (Enseignant)
```
1. Enseignant voit:
   - Badge "Visio programmée - Disponible dans X min"
   - Bouton "Démarrer la visio" (grisé si hors fenêtre)

2. À H-15min, bouton devient actif (bleu)

3. Enseignant clique "Démarrer la visio":
   → validateParticipant(seanceId, userId)
   → generateRoomLink(seance)
   → trackParticipantJoin(seanceId, userId)
   → window.open(jitsiUrl)

4. Jitsi s'ouvre: "LMS-Mathematiques-L1InfoA-2025-10-20-abc123"
   → Enseignant avec audio/vidéo activés

5. Fenêtre monitored:
   → Au close: trackParticipantLeave()
   → Calcule durée
   → Sync vers KLASSCI
```

### Étape 3: Participation (Étudiant)
```
1. Étudiant voit:
   - Badge "Visio en cours" (animation pulse verte)
   - Bouton "Rejoindre la visio" (actif)

2. Étudiant clique "Rejoindre la visio":
   → validateParticipant(seanceId, userId)
   → generateRoomLink(seance) (même room que enseignant!)
   → trackParticipantJoin(seanceId, userId)
   → window.open(jitsiUrl)

3. Jitsi s'ouvre dans la même room
   → Audio muted par défaut
   → Rejoint l'enseignant + autres étudiants

4. Fenêtre monitored:
   → Au close: trackParticipantLeave()
   → Calcule durée
   → Sync vers KLASSCI
```

### Étape 4: Synchronisation KLASSCI
```
1. À chaque trackParticipantLeave():
   → POST /api/lms/attendances/from-video-session
   {
     "seance_cours_id": 123,
     "date": "2025-10-20",
     "participants": [
       {
         "user_id": 456,
         "joined_at": "2025-10-20T10:05:00Z",
         "left_at": "2025-10-20T11:35:00Z",
         "duration_minutes": 90
       }
     ]
   }

2. Backend:
   → Récupère seance KLASSCI
   → Envoie présence via KLASSCI API:
     POST /api/attendances
     {
       "seance_id": 123,
       "user_id": 456,
       "date": "2025-10-20",
       "statut": "present",
       "call_type": "merged", // ← Spécial visio
       "duree_minutes": 90
     }

3. KLASSCI enregistre la présence
   → Calcule moyennes de présence
   → Visible dans dashboard enseignant
```

---

## Configuration Jitsi

### Serveur utilisé
```javascript
const JITSI_DOMAIN = 'meet.jit.si' // Serveur public Jitsi
```

**Alternative**: Déployer votre propre serveur Jitsi:
```bash
# Installation Ubuntu 22.04
curl https://download.jitsi.org/jitsi-key.gpg.key | sudo sh -c 'gpg --dearmor > /usr/share/keyrings/jitsi-keyring.gpg'
echo 'deb [signed-by=/usr/share/keyrings/jitsi-keyring.gpg] https://download.jitsi.org stable/' | sudo tee /etc/apt/sources.list.d/jitsi-stable.list > /dev/null
sudo apt update
sudo apt install jitsi-meet
```

Puis modifier dans `jitsi.js`:
```javascript
const JITSI_DOMAIN = 'jitsi.votre-domaine.com'
```

### Paramètres URL Jitsi
```javascript
const params = {
  'userInfo.displayName': 'Nom Utilisateur',
  'config.prejoinPageEnabled': 'false',           // Skip prejoin
  'config.startWithAudioMuted': 'true/false',      // Selon rôle
  'config.startWithVideoMuted': 'false',
  'interfaceConfig.SHOW_JITSI_WATERMARK': 'false',
  'interfaceConfig.DEFAULT_LOGO_URL': ''           // Votre logo
}
```

### Format nom de salle
```
LMS-{Matiere}-{Classe}-{Date}-{RoomID}
Example: LMS-Mathematiques-L1InfoA-2025-10-20-abc12345
```

**Avantages**:
- Unique par séance
- Lisible/identifiable
- RoomID garantit unicité même si 2 séances identiques

---

## Sécurité et Validation

### Validation participant (backend)
```php
public function validateParticipant(int $seanceId, Request $request): JsonResponse
{
    // 1. Vérifier que user est authentifié
    // 2. Récupérer infos séance depuis KLASSCI
    // 3. Vérifier rôle:
    //    - Enseignant: Doit être assigné à la séance
    //    - Étudiant: Doit être inscrit dans la classe
    // 4. Vérifier fenêtre temporelle (optionnel backend)
    // 5. Retourner: { authorized: true/false, reason: "..." }
}
```

### Permissions par rôle
```
coordinateur, superAdmin:
  ✅ Programmer/Désactiver visio (toggle-visio)
  ✅ Voir participants
  ❌ Démarrer/Rejoindre visio

enseignant:
  ❌ Programmer visio
  ✅ Démarrer visio (fenêtre temporelle)
  ✅ Voir participants

etudiant:
  ❌ Programmer visio
  ✅ Rejoindre visio (si visio active)
  ✅ Voir participants
```

### Fenêtre temporelle
```
Enseignant peut démarrer:
  De: seance.heure_debut - 15 minutes
  À:  seance.heure_fin + 30 minutes

Exemple: Séance 10h00-12h00
  → Démarrage possible: 09h45 - 12h30
```

**Raison**:
- -15min: Préparer la salle virtuelle avant arrivée étudiants
- +30min: Débordements, questions post-séance

---

## Testing

### 1. Test coordinateur
```bash
# Compte coordinateur dans KLASSCI
# Naviguer vers: http://localhost:5173/classes/1

1. Voir section "Séances à venir"
2. Cliquer "Programmer en visio"
3. Vérifier: Badge "Visio jitsi activée" apparaît
4. Cliquer "Désactiver visio"
5. Vérifier: Badge disparaît, bouton "Programmer" réapparaît
```

### 2. Test enseignant
```bash
# Compte enseignant assigné à la séance
# Modifier l'heure système pour être dans fenêtre [-15min, +30min]

1. Naviguer vers: http://localhost:5173/matieres/2
2. Onglet "Séances"
3. Voir badge "Visio programmée - Disponible maintenant"
4. Bouton "Démarrer la visio" doit être BLEU (actif)
5. Cliquer → Nouvelle fenêtre Jitsi s'ouvre
6. Vérifier nom salle: LMS-{Matiere}-{Classe}-{Date}-{ID}
7. Fermer fenêtre Jitsi
8. Vérifier console: "Participant quitté" + durée calculée
```

### 3. Test étudiant
```bash
# Compte étudiant inscrit dans la classe
# Enseignant doit avoir démarré la visio

1. Naviguer vers: http://localhost:5173/classes/1
2. Voir badge "Visio en cours" (animation pulse)
3. Bouton "Rejoindre la visio" doit être VIOLET (actif)
4. Cliquer → Nouvelle fenêtre Jitsi s'ouvre
5. Rejoindre la MÊME room que l'enseignant
6. Vérifier: Audio muted par défaut
7. Fermer fenêtre
8. Vérifier sync KLASSCI: présence enregistrée
```

### 4. Test participants modal
```bash
1. Cliquer "Participants (X)"
2. Modal s'ouvre
3. Voir:
   - Statistiques: 1 enseignant, X étudiants
   - Enseignant avec badge "Responsable"
   - Liste des étudiants (matricule, nom, email)
   - Total en bas
4. Fermer modal
```

### 5. Test sync KLASSCI
```bash
1. Enseignant rejoint visio pendant 30 minutes
2. Étudiant rejoint visio pendant 25 minutes
3. Les deux ferment
4. Vérifier backend logs:
   → POST /api/lms/attendances/from-video-session
   → 2 participations envoyées
5. Vérifier KLASSCI:
   → GET /api/attendances?seance_id=123&date=2025-10-20
   → 2 présences avec call_type="merged"
```

---

## Debugging

### Console logs à surveiller

**Frontend**:
```javascript
// VisioManager
[VisioManager] Erreur programmation visio: {...}
[VisioManager] Erreur démarrage visio: {...}

// JitsiService
[JitsiService] Lien généré: https://...
[JitsiService] Room ID: lms_seance_123_...
[JitsiService] User: Jean Dupont, enseignant
[JitsiService] Participant rejoint: {...}
[JitsiService] Participant quitté: {duration: 90}
[JitsiService] Synchronisation participation: {...}
[JitsiService] Sync réussie: {...}

// ParticipantsModal
[ParticipantsModal] Chargement participants pour séance: 123
[ParticipantsModal] Participants chargés: {teacher: {...}, students: [...]}
```

**Backend**:
```php
// LMSDataController
[INFO] Récupération étudiants classe: classe_id=1, user_id=456
[INFO] Toggle visio séance: seance_id=123, enabled=true, type=jitsi
[INFO] Validation participant: seance_id=123, user_id=456, authorized=true
[INFO] Sync attendances depuis vidéo: seance_id=123, participants_count=2

[ERROR] Erreur récupération étudiants classe: {...}
```

### Problèmes courants

**1. Bouton "Démarrer" toujours grisé (enseignant)**
```javascript
// Cause: Heure actuelle hors fenêtre temporelle
// Solution: Vérifier isInTimeWindow computed property
console.log('Current time:', this.currentTime)
console.log('Window start:', windowStart)
console.log('Window end:', windowEnd)
console.log('In window?', this.isInTimeWindow)
```

**2. Fenêtre Jitsi ne s'ouvre pas**
```javascript
// Cause: Popup bloqué par navigateur
// Solution: Autoriser popups pour localhost:5173
// Chrome: icône popup dans barre d'adresse
```

**3. Sync KLASSCI échoue**
```javascript
// Cause: call_type='merged' non accepté par KLASSCI
// Solution: Vérifier API KLASSCI accepte ce call_type
// Logs backend:
[ERROR] KLASSCI API Error: Invalid call_type 'merged'
```

**4. Étudiants pas dans ParticipantsModal**
```javascript
// Cause: Endpoint /classes/{id}/etudiants retourne vide
// Solution: Vérifier route backend + KLASSCI API
php artisan route:list --name=lms.classes.etudiants
```

---

## Prochaines améliorations

### Court terme
- [ ] Ajouter statut "online" en temps réel (WebSocket/Pusher)
- [ ] Enregistrer sessions vidéo (Jitsi Recording)
- [ ] Statistiques visio par enseignant (durée moyenne, taux participation)

### Moyen terme
- [ ] Support Zoom/Teams (alternative à Jitsi)
- [ ] Sondages en direct pendant visio
- [ ] Chat intégré (sidebar)
- [ ] Partage d'écran tracking

### Long terme
- [ ] Serveur Jitsi dédié (self-hosted)
- [ ] Modération automatique (mute tous, kick)
- [ ] Breakout rooms (groupes étudiants)
- [ ] AI transcription des séances

---

## Fichiers créés/modifiés

### Frontend
```
✅ CRÉÉ:
- src/components/visio/VisioManager.vue (280 lignes)
- src/components/visio/ParticipantsModal.vue (240 lignes)
- src/services/jitsi.js (350 lignes)

✅ MODIFIÉ:
- src/services/lms.js (ajouté getClasseEtudiants)
- src/views/classes/ClasseDetails.vue (intégré VisioManager)
- src/views/matieres/MatiereDetails.vue (intégré VisioManager)
```

### Backend
```
✅ CRÉÉ:
- database/migrations/2025_10_20_add_visio_columns_to_seances.php

✅ MODIFIÉ:
- app/Http/Controllers/API/LMSDataController.php (ajouté classeEtudiants)
- routes/api.php (ajouté route lms.classes.etudiants)
```

### Documentation
```
✅ CRÉÉ:
- IMPLEMENTATION_VISIOCONFERENCE_COMPLETE.md (ce fichier)
- IMPLEMENTATION_PAGES_CLASSE_MATIERE.md (déjà existant)
```

---

## Commandes utiles

### Migration BDD
```bash
cd lms-backend
php artisan migrate
```

### Vérifier routes
```bash
php artisan route:list --name=lms
```

### Clear cache
```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
```

### Logs backend
```bash
tail -f storage/logs/laravel.log
```

### Nettoyer localStorage frontend
```javascript
// Console navigateur
localStorage.clear()
// ou
Object.keys(localStorage).forEach(key => {
  if (key.startsWith('visio_participation_')) {
    localStorage.removeItem(key)
  }
})
```

---

## Auteur

Implémentation: Claude (Anthropic)
Date: 2025-10-20
LMS Backend: Laravel 10 + KLASSCI Integration
LMS Frontend: Vue 3 + Vite

---

## Licence

Propriétaire - Tous droits réservés
