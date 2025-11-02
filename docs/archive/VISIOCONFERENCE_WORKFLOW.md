# 📹 Documentation API Visioconférence LMS

## ✅ Vue d'ensemble

Le LMS gère ses propres rooms vidéo (Jitsi, Zoom, etc.) et utilise les APIs KLASSCI pour:
- Récupérer les séances programmées
- Valider les participants autorisés
- Synchroniser les attendances après la session

**Architecture:**
```
LMS (gère les rooms) ←→ KLASSCI (fournit données séances/participants)
                      ↓
                  Sync attendances merged
```

---

## 🎯 Les 6 Endpoints Implémentés

### 1. GET `/api/lms/seances/upcoming`
**Récupérer les séances à venir pour pré-créer les rooms vidéo**

#### Paramètres Query
- `days` (optionnel): Nombre de jours à regarder (défaut: 30)
- `teacher_id` (optionnel): Filtrer par enseignant
- `classe_id` (optionnel): Filtrer par classe

#### Exemple de requête
```bash
curl -X GET "http://localhost:8000/api/lms/seances/upcoming?days=7&teacher_id=326" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

#### Réponse
```json
{
  "success": true,
  "data": [
    {
      "id": 5,
      "date_seance": "2025-10-22",
      "heure_debut": "08:00",
      "heure_fin": "09:30",
      "duree_minutes": 90,
      "matiere": { "id": 3, "nom": "Chimie" },
      "classe": { "id": 2, "nom": "6ème A" },
      "enseignant": { "id": 45, "nom": "Prof. Martin", "user_id": 326 },
      "salle": "Labo 2",
      "statut": "planifie"
    }
  ],
  "meta": {
    "total_seances": 1,
    "date_debut": "2025-10-20",
    "date_fin": "2025-10-27",
    "filtres": {
      "teacher_id": 326,
      "classe_id": null
    }
  }
}
```

#### Cas d'usage LMS
```javascript
// J-7: Pré-créer toutes les rooms pour la semaine
const response = await api.get('/lms/seances/upcoming?days=7&teacher_id=326')

response.data.forEach(seance => {
  const roomName = `seance_${seance.id}_${seance.classe.nom}`
  createJitsiRoom(roomName, seance.duree_minutes)

  // Stocker roomName, seance_id, date pour usage ultérieur
  saveRoomToDatabase({
    room_name: roomName,
    seance_id: seance.id,
    scheduled_at: `${seance.date_seance} ${seance.heure_debut}`,
    duration_minutes: seance.duree_minutes,
    status: 'pending'
  })
})
```

---

### 2. GET `/api/lms/seances/{id}/participants`
**Récupérer la liste des participants autorisés**

#### Exemple de requête
```bash
curl -X GET "http://localhost:8000/api/lms/seances/5/participants" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

#### Réponse
```json
{
  "success": true,
  "data": {
    "seance": {
      "id": 5,
      "date_seance": "2025-10-22",
      "heure_debut": "08:00",
      "heure_fin": "09:30",
      "matiere": { "id": 3, "nom": "Chimie" },
      "classe": { "id": 2, "nom": "6ème A" }
    },
    "teacher": {
      "id": 45,
      "nom": "Martin",
      "prenom": "Pierre",
      "email": "p.martin@example.com",
      "user_id": 326
    },
    "students": [
      {
        "id": 137,
        "nom": "Doe",
        "prenom": "John",
        "email": "john@example.com",
        "matricule": "2024001",
        "user_id": 330,
        "statut": "actif"
      }
    ],
    "total_participants": 2
  }
}
```

#### Cas d'usage LMS
```javascript
// Afficher le roster de la séance
const { data } = await api.get(`/lms/seances/${seanceId}/participants`)

console.log(`Enseignant: ${data.teacher.prenom} ${data.teacher.nom}`)
console.log(`Étudiants inscrits: ${data.students.length}`)

// Générer liste d'attente
data.students.forEach(student => {
  addToWaitingList(student.user_id, student.nom, student.prenom)
})
```

---

### 3. POST `/api/lms/seances/{id}/validate-participant`
**Valider si un utilisateur peut rejoindre la visio**

#### Body
```json
{
  "user_id": 330
}
```

#### Exemple de requête
```bash
curl -X POST "http://localhost:8000/api/lms/seances/5/validate-participant" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"user_id": 330}'
```

#### Réponse (Autorisé - Étudiant)
```json
{
  "success": true,
  "authorized": true,
  "role": "student",
  "message": "Étudiant inscrit dans la classe"
}
```

#### Réponse (Autorisé - Enseignant)
```json
{
  "success": true,
  "authorized": true,
  "role": "teacher",
  "message": "Enseignant de la séance"
}
```

#### Réponse (Refusé)
```json
{
  "success": true,
  "authorized": false,
  "reason": "not_enrolled_in_class"
}
```

#### Raisons de refus possibles
- `user_not_found` - Utilisateur n'existe pas
- `seance_not_found` - Séance n'existe pas
- `not_enrolled_in_class` - Étudiant pas inscrit dans la classe
- `no_class_for_seance` - Séance sans classe assignée
- `invalid_role` - Rôle utilisateur non autorisé

#### Cas d'usage LMS
```javascript
// Avant de générer le lien Jitsi
async function allowUserToJoin(userId, seanceId) {
  const result = await api.post(`/lms/seances/${seanceId}/validate-participant`, {
    user_id: userId
  })

  if (result.authorized) {
    // Générer lien Jitsi avec modération selon rôle
    const moderator = result.role === 'teacher' || result.role === 'moderator'
    return generateJitsiLink(roomName, moderator)
  } else {
    alert(`Accès refusé: ${result.reason}`)
    return null
  }
}
```

---

### 4. POST `/api/lms/attendances/from-video-session`
**Synchroniser les attendances après une session vidéo**

#### Body
```json
{
  "seance_cours_id": 11,
  "date": "2025-10-05",
  "participants": [
    {
      "etudiant_id": 137,
      "statut": "present",
      "joined_at": "2025-10-05 10:00:00",
      "left_at": "2025-10-05 11:30:00",
      "duration_minutes": 90
    },
    {
      "etudiant_id": 138,
      "statut": "retard",
      "joined_at": "2025-10-05 10:15:00",
      "left_at": "2025-10-05 11:30:00",
      "duration_minutes": 75
    }
  ]
}
```

#### Exemple de requête
```bash
curl -X POST "http://localhost:8000/api/lms/attendances/from-video-session" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d @attendance_data.json
```

#### Réponse
```json
{
  "success": true,
  "message": "Attendances synchronisées avec succès",
  "data": {
    "created": 1,
    "updated": 1,
    "errors": []
  }
}
```

#### Important
- **`call_type: 'merged'`** est automatiquement appliqué
- Les colonnes `video_joined_at`, `video_left_at`, `video_duration_minutes` sont remplies
- Le `commentaire` est historisé (concaténé) à chaque sync
- Si l'attendance existe déjà, elle est mise à jour (pas de doublon)

#### Cas d'usage LMS
```javascript
// À la fin de la visio Jitsi
async function syncVideoAttendances(seanceId, participants) {
  const participantsData = participants.map(p => ({
    etudiant_id: p.etudiant_id,
    statut: p.duration_minutes >= 45 ? 'present' : 'retard', // Règle métier
    joined_at: p.join_time,
    left_at: p.leave_time,
    duration_minutes: Math.floor((p.leave_time - p.join_time) / 60000) // ms → min
  }))

  const result = await api.post('/lms/attendances/from-video-session', {
    seance_cours_id: seanceId,
    date: new Date().toISOString().split('T')[0],
    participants: participantsData
  })

  console.log(`Créées: ${result.data.created}, Mises à jour: ${result.data.updated}`)
}
```

---

### 5. GET `/api/lms/notifications/preferences/{userId}`
**Récupérer les préférences de notification d'un utilisateur**

#### Exemple de requête
```bash
curl -X GET "http://localhost:8000/api/lms/notifications/preferences/239" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

#### Réponse
```json
{
  "success": true,
  "data": {
    "user_id": 239,
    "channels": {
      "whatsapp": true,
      "email": true,
      "sms": false,
      "app": true
    },
    "preferences": {
      "session_reminder_minutes": 15,
      "evaluation_reminder_hours": 24,
      "absence_notification": true
    }
  }
}
```

#### Sécurité
- L'utilisateur ne peut récupérer que ses propres préférences
- Les coordinateurs/superAdmin peuvent voir toutes les préférences

#### Cas d'usage LMS
```javascript
// Avant d'envoyer une notification
const prefs = await api.get(`/lms/notifications/preferences/${userId}`)

const activeChannels = Object.keys(prefs.data.channels)
  .filter(channel => prefs.data.channels[channel])

// Envoyer seulement via les canaux actifs
sendNotification(userId, message, activeChannels)
```

---

### 6. POST `/api/lms/notifications/send-session-reminder`
**Envoyer un rappel de séance**

#### Body
```json
{
  "seance_cours_id": 5,
  "channels": ["whatsapp", "email"],
  "minutes_before": 15
}
```

#### Exemple de requête
```bash
curl -X POST "http://localhost:8000/api/lms/notifications/send-session-reminder" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"seance_cours_id": 5, "channels": ["whatsapp","email"], "minutes_before": 15}'
```

#### Réponse
```json
{
  "success": true,
  "message": "Rappels envoyés avec succès",
  "data": {
    "seance_cours_id": 5,
    "channels": ["whatsapp", "email"],
    "sent_count": 0,
    "note": "TODO: Intégration NotificationService à compléter"
  }
}
```

#### TODO
L'intégration avec le `NotificationService` existant doit être complétée pour envoyer réellement les notifications.

#### Cas d'usage LMS
```javascript
// H-15min: Cron job envoie rappels
const upcomingSeances = await api.get('/lms/seances/upcoming?days=1')

upcomingSeances.data.forEach(async seance => {
  const now = new Date()
  const seanceStart = new Date(`${seance.date_seance} ${seance.heure_debut}`)
  const minutesUntil = (seanceStart - now) / 60000

  if (minutesUntil <= 15 && minutesUntil > 0) {
    await api.post('/lms/notifications/send-session-reminder', {
      seance_cours_id: seance.id,
      channels: ['whatsapp', 'email'],
      minutes_before: 15
    })
  }
})
```

---

## 🔄 Workflow Complet Recommandé

### Phase 1: Préparation (J-7 → H-15min)

```javascript
// 1. Récupérer séances à venir (quotidien)
const seances = await api.get('/lms/seances/upcoming?days=30')

// 2. Pré-créer les rooms Jitsi
seances.data.forEach(seance => {
  const roomName = `seance_${seance.id}`
  createJitsiRoom(roomName, seance.duree_minutes)
})

// 3. Planifier rappels (H-15min)
scheduleReminders(seances.data)
```

### Phase 2: Démarrage (H-15min → H+30min)

```javascript
// 1. Afficher bouton "Démarrer" au prof
const canStartVideo = () => {
  const now = new Date()
  const start = new Date(`${seance.date_seance} ${seance.heure_debut}`)
  const end = new Date(`${seance.date_seance} ${seance.heure_fin}`)

  const canStart = now >= (start.getTime() - 15*60*1000) // H-15min
  const canStillStart = now <= (end.getTime() + 30*60*1000) // H+30min

  return canStart && canStillStart
}

// 2. Prof clique "Démarrer"
if (user.role === 'enseignant') {
  updateRoomStatus(seance.id, 'active')
  notifyStudents(seance.id, 'Le cours a commencé')
}
```

### Phase 3: Validation Participants

```javascript
// Avant chaque connexion à la room
async function handleJoinRequest(userId, seanceId) {
  const validation = await api.post(
    `/lms/seances/${seanceId}/validate-participant`,
    { user_id: userId }
  )

  if (!validation.authorized) {
    return { allowed: false, reason: validation.reason }
  }

  const moderator = validation.role === 'teacher' || validation.role === 'moderator'
  const link = generateJitsiLink(roomName, { moderator })

  return { allowed: true, link, role: validation.role }
}
```

### Phase 4: Pendant la Visio

```javascript
// Tracking automatique Jitsi
const participants = []

jitsiAPI.on('participantJoined', (participant) => {
  participants.push({
    user_id: participant.id,
    join_time: new Date(),
    etudiant_id: getUserEtudiantId(participant.id)
  })
})

jitsiAPI.on('participantLeft', (participant) => {
  const p = participants.find(p => p.user_id === participant.id)
  if (p) {
    p.leave_time = new Date()
  }
})
```

### Phase 5: Clôture et Sync

```javascript
// Enseignant clique "Terminer"
async function endVideoSession(seanceId) {
  updateRoomStatus(seanceId, 'ended')

  // Préparer données attendance
  const participantsData = participants
    .filter(p => p.etudiant_id) // Seulement les étudiants
    .map(p => {
      const duration = Math.floor((p.leave_time - p.join_time) / 60000)

      return {
        etudiant_id: p.etudiant_id,
        statut: duration >= 45 ? 'present' : (duration > 0 ? 'retard' : 'absent'),
        joined_at: p.join_time.toISOString(),
        left_at: p.leave_time.toISOString(),
        duration_minutes: duration
      }
    })

  // Synchroniser vers KLASSCI
  const result = await api.post('/lms/attendances/from-video-session', {
    seance_cours_id: seanceId,
    date: new Date().toISOString().split('T')[0],
    participants: participantsData
  })

  console.log(`✅ ${result.data.created} créées, ${result.data.updated} mises à jour`)
}
```

---

## 🗄️ Structure BDD

### Table: `esbtp_attendances`

Colonnes ajoutées par la migration:

```sql
ALTER TABLE esbtp_attendances ADD COLUMN video_joined_at DATETIME NULL COMMENT 'Date/heure connexion visio';
ALTER TABLE esbtp_attendances ADD COLUMN video_left_at DATETIME NULL COMMENT 'Date/heure déconnexion visio';
ALTER TABLE esbtp_attendances ADD COLUMN video_duration_minutes INT NULL COMMENT 'Durée participation en minutes';
```

### Modèle: `ESBTPAttendance`

```php
protected $fillable = [
    // ... autres champs
    'call_type',
    'video_joined_at',
    'video_left_at',
    'video_duration_minutes',
];

protected $casts = [
    'date' => 'date',
    'video_joined_at' => 'datetime',
    'video_left_at' => 'datetime',
    'video_duration_minutes' => 'integer',
];

// Scopes
public function scopeFinalOnly($query) {
    return $query->where('call_type', 'merged');
}

public function scopeVideoOnly($query) {
    return $query->where('call_type', 'merged')
                 ->whereNotNull('video_joined_at');
}
```

---

## ⚙️ Configuration

### Fenêtre de démarrage

```javascript
// Configurable selon besoins établissement
const VIDEO_CONFIG = {
  START_TOLERANCE_BEFORE_MINUTES: 15, // H-15min
  START_TOLERANCE_AFTER_MINUTES: 30,  // H+30min
  REMINDER_MINUTES: 15,                // Rappel à H-15min
  MIN_DURATION_FOR_PRESENT: 45         // 45min = présent
}
```

### call_type = 'merged'

Les attendances vidéo utilisent `call_type='merged'` pour:
- ✅ Être incluses dans `finalOnly()` scope (stats/rapports)
- ✅ Rester distinguables via colonnes `video_*`
- ✅ Historique dans `commentaire`

---

## 📊 Exemples de Données

### Attendance Vidéo (créée)
```sql
INSERT INTO esbtp_attendances (
    seance_cours_id, etudiant_id, date, statut,
    call_type, video_joined_at, video_left_at, video_duration_minutes,
    commentaire
) VALUES (
    11, 137, '2025-10-05', 'present',
    'merged', '2025-10-05 10:00:00', '2025-10-05 11:30:00', 90,
    '[2025-10-05 12:00:00] Sync vidéo: rejoint 2025-10-05 10:00:00, quitté 2025-10-05 11:30:00, durée 90min'
);
```

### Attendance Vidéo (mise à jour)
```sql
-- Si l'étudiant rejoint à nouveau (sync multiple)
UPDATE esbtp_attendances SET
    video_joined_at = '2025-10-05 10:05:00',
    video_left_at = '2025-10-05 12:00:00',
    video_duration_minutes = 115,
    commentaire = CONCAT(commentaire, '\n[2025-10-05 12:30:00] Sync vidéo: rejoint 2025-10-05 10:05:00, quitté 2025-10-05 12:00:00, durée 115min')
WHERE seance_cours_id = 11 AND etudiant_id = 137 AND date = '2025-10-05';
```

---

## 🧪 Tests Validés

### ✅ Endpoint 1: GET /lms/seances/upcoming
- Statut: 200 OK
- Séances filtrées par date correctement
- Calcul `duree_minutes` OK

### ✅ Endpoint 2: GET /lms/seances/{id}/participants
- Statut: 200 OK
- Teacher retourné
- Students actifs uniquement

### ✅ Endpoint 3: POST /lms/seances/{id}/validate-participant
- Enseignant: authorized=true, role='teacher' ✅
- Étudiant inscrit: authorized=true, role='student' ✅
- Étudiant non-inscrit: authorized=false, reason='not_enrolled_in_class' ✅
- Coordinateur: authorized=true, role='moderator' ✅

### ✅ Endpoint 4: POST /lms/attendances/from-video-session
- Création: call_type='merged', colonnes video_* remplies ✅
- Mise à jour: commentaire historisé ✅
- Validation table: `esbtp_seance_cours` ✅

### ✅ Endpoint 5: GET /lms/notifications/preferences/{userId}
- Propres prefs: 200 OK ✅
- Autres prefs (non-admin): 403 Forbidden ✅

### ⏳ Endpoint 6: POST /lms/notifications/send-session-reminder
- Validation OK ✅
- Intégration NotificationService: TODO

---

## 🚀 Déploiement

### 1. Exécuter la migration
```bash
php artisan migrate
```

### 2. Vérifier les routes
```bash
php artisan route:list --path=lms
```

Résultat attendu:
```
GET|HEAD  api/lms/seances/upcoming
GET|HEAD  api/lms/seances/{seanceId}/participants
POST      api/lms/seances/{seanceId}/validate-participant
POST      api/lms/attendances/from-video-session
GET|HEAD  api/lms/notifications/preferences/{userId}
POST      api/lms/notifications/send-session-reminder
```

### 3. Tester avec Postman/Insomnia
Importer collection d'exemples (voir section Tests ci-dessus)

---

## 📝 TODO

- [ ] Compléter intégration `NotificationService` dans `sendSessionReminder()`
- [ ] Ajouter support enregistrements vidéo (URL stockage)
- [ ] Implémenter webhook Jitsi pour tracking automatique
- [ ] Dashboard analytics vidéo (taux participation, durées moyennes)
- [ ] Export CSV attendances vidéo
- [ ] Modifier `attendances.index` pour afficher colonnes `video_*`

---

**Date de création:** 2025-10-20
**Version:** 1.0
**Auteur:** Claude Code
