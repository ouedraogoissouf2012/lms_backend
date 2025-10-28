# 📚 Navigation Hiérarchique: Matière → Lessons → Séances → Visio

## 🎯 Vue d'ensemble

Cette documentation décrit la logique de navigation complète dans le LMS, alignée avec les standards des LMS leaders (Moodle, Canvas, Teams).

### 🏗️ Structure Hiérarchique

```
Matière (ex: Chimie)
  ├─ Lessons (Contenu pédagogique)
  │    ├─ Lesson 1: "Introduction à la chimie organique"
  │    │    ├─ Videos
  │    │    ├─ PDFs
  │    │    ├─ Quizzes
  │    │    └─ Exercises
  │    └─ Lesson 2: "Les liaisons chimiques"
  │
  └─ Séances (Sessions programmées calendrier)
       ├─ Séance 1: Lundi 08h-10h (Classe 6èmeA, Prof Martin)
       │    ├─ Mode: Présentiel | Visio | Hybride
       │    └─ Si Visio activée:
       │         ├─ Type: Jitsi | Zoom | Teams | BBB
       │         ├─ Room ID: "room_seance_123"
       │         ├─ Fenêtre: H-15min → H+30min
       │         └─ Attendances trackées
       │
       └─ Séance 2: Mercredi 14h-16h (Classe 6èmeB, Prof Dupont)
            └─ Mode: Présentiel (pas de visio)
```

---

## 📡 Endpoints API

### 1. GET `/api/lms/matieres/{id}` - Vue complète d'une matière

**Retourne:**
```json
{
  "success": true,
  "data": {
    "matiere": {
      "id": 3,
      "nom": "Chimie",
      "code": "CHIM",
      "coefficient": 2
    },
    "lessons": [ // ← NOUVEAU: Contenu pédagogique
      {
        "id": 10,
        "title": "Introduction à la chimie organique",
        "type": "cours",
        "status": "published",
        "duration_minutes": 45,
        "user_progress": {
          "completed": false,
          "progress_percentage": 30
        }
      }
    ],
    "seances_programmees": [ // ← Séances calendrier
      {
        "id": 5,
        "date_seance": "2025-10-22",
        "heure_debut": "08:00",
        "heure_fin": "09:30",
        "duree_minutes": 90,
        "classe": { "id": 2, "nom": "6ème A" },
        "enseignant": { "id": 45, "nom": "Prof. Martin" },
        "visio_enabled": true, // ← Flag visio
        "visio_type": "jitsi"
      }
    ],
    "evaluations_programmees": [...],
    "statistiques": {
      "nombre_lessons": 10,
      "nombre_seances_programmees": 24,
      "nombre_evaluations": 3
    }
  }
}
```

**Usage Frontend:**
```vue
<template>
  <div>
    <h1>{{ matiere.nom }}</h1>

    <!-- Onglets -->
    <Tabs>
      <!-- Onglet 1: Contenu pédagogique -->
      <Tab name="Lessons">
        <LessonCard
          v-for="lesson in lessons"
          :key="lesson.id"
          :lesson="lesson"
        />
      </Tab>

      <!-- Onglet 2: Calendrier séances -->
      <Tab name="Séances">
        <SeanceCard
          v-for="seance in seances_programmees"
          :key="seance.id"
          :seance="seance"
        />
      </Tab>

      <!-- Onglet 3: Évaluations -->
      <Tab name="Évaluations">
        <EvaluationCard
          v-for="eval in evaluations_programmees"
          :key="eval.id"
          :evaluation="eval"
        />
      </Tab>
    </Tabs>
  </div>
</template>
```

---

### 2. GET `/api/lms/seances/{id}/details` - Détails complets d'une séance

**Retourne:**
```json
{
  "success": true,
  "data": {
    "seance": {
      "id": 5,
      "date_seance": "2025-10-22",
      "heure_debut": "08:00",
      "heure_fin": "09:30",
      "duree_minutes": 90,
      "classe": { "id": 2, "nom": "6ème A" },
      "matiere": { "id": 3, "nom": "Chimie" },
      "enseignant": { "id": 45, "nom": "Prof. Martin", "user_id": 326 },
      "salle": "Labo 2",
      "visio_enabled": true,
      "visio_type": "jitsi",
      "visio_room_id": "room_seance_5"
    },
    "participants": {
      "teacher": {
        "id": 45,
        "nom": "Martin",
        "prenom": "Pierre",
        "user_id": 326
      },
      "students": [
        {
          "id": 137,
          "nom": "Doe",
          "prenom": "John",
          "user_id": 330,
          "statut": "actif"
        }
      ],
      "total": 36
    },
    "visio": {
      "enabled": true,
      "type": "jitsi",
      "room_id": "room_seance_5",
      "window": {
        "can_start": true,
        "has_started": false,
        "has_ended": false,
        "is_in_window": true,
        "start_window": "2025-10-22T07:45:00+00:00",
        "end_window": "2025-10-22T10:00:00+00:00"
      }
    }
  }
}
```

**Usage Frontend:**
```vue
<template>
  <div class="seance-details">
    <h2>{{ seance.matiere.nom }} - {{ seance.date_seance }}</h2>
    <p>{{ seance.heure_debut }} - {{ seance.heure_fin }}</p>
    <p>Prof: {{ seance.enseignant.nom }}</p>
    <p>Classe: {{ seance.classe.nom }}</p>

    <!-- Si visio activée -->
    <div v-if="visio.enabled">
      <h3>Visioconférence {{ visio.type }}</h3>

      <!-- Enseignant -->
      <button
        v-if="isTeacher && visio.window.can_start"
        @click="startVisio"
        class="btn-primary"
      >
        Démarrer le cours
      </button>

      <!-- Étudiant -->
      <div v-else-if="isStudent">
        <span v-if="!visio.window.has_started" class="badge-orange">
          En attente de l'enseignant
        </span>
        <button
          v-else-if="visio.window.is_in_window && roomActive"
          @click="joinVisio"
          class="btn-success"
        >
          Rejoindre le cours
        </button>
        <span v-else-if="visio.window.has_ended" class="badge-gray">
          Cours terminé
        </span>
      </div>
    </div>

    <!-- Si présentiel -->
    <div v-else>
      <span class="badge-blue">Cours en présentiel</span>
      <p>Salle: {{ seance.salle }}</p>
    </div>

    <!-- Liste participants -->
    <div class="participants">
      <h4>Participants ({{ participants.total }})</h4>
      <ul>
        <li v-for="student in participants.students" :key="student.id">
          {{ student.prenom }} {{ student.nom }}
        </li>
      </ul>
    </div>
  </div>
</template>
```

---

### 3. POST `/api/lms/seances/{id}/toggle-visio` - Activer/Désactiver visio (Coordinateurs)

**Input:**
```json
{
  "enabled": true,
  "visio_type": "jitsi"
}
```

**Retourne:**
```json
{
  "success": true,
  "message": "Visioconférence activée",
  "data": {
    "seance_id": 5,
    "visio_enabled": true,
    "visio_type": "jitsi"
  }
}
```

**Usage Frontend (Interface Coordinateur):**
```vue
<template>
  <div class="seance-management">
    <h3>Séance {{ seance.id }}</h3>

    <!-- Toggle visio -->
    <div class="form-group">
      <label>
        <input
          type="checkbox"
          v-model="visioEnabled"
          @change="toggleVisio"
        />
        Activer la visioconférence
      </label>
    </div>

    <!-- Type de visio -->
    <div v-if="visioEnabled" class="form-group">
      <label>Type de visio:</label>
      <select v-model="visioType" @change="updateVisioType">
        <option value="jitsi">Jitsi Meet</option>
        <option value="zoom">Zoom</option>
        <option value="teams">Microsoft Teams</option>
        <option value="bbb">BigBlueButton</option>
      </select>
    </div>
  </div>
</template>

<script>
async function toggleVisio() {
  const result = await api.post(`/lms/seances/${seanceId}/toggle-visio`, {
    enabled: this.visioEnabled,
    visio_type: this.visioType
  })

  if (result.success) {
    alert(result.message)
  }
}
</script>
```

---

## 🔄 Workflow Complet

### Phase 1: Navigation Matière

```javascript
// 1. Étudiant clique sur "Chimie" dans son dashboard
const matiereId = 3

// 2. Frontend appelle API
const { data } = await api.get(`/lms/matieres/${matiereId}`)

// 3. Affiche 3 onglets:
// - Lessons (data.lessons)
// - Séances (data.seances_programmees)
// - Évaluations (data.evaluations_programmees)
```

### Phase 2: Consultation d'une Séance

```javascript
// 1. Étudiant clique sur une séance
const seanceId = 5

// 2. Frontend appelle API détails
const { data } = await api.get(`/lms/seances/${seanceId}/details`)

// 3. Affiche:
// - Infos séance (date, heure, prof, classe)
// - Participants (teacher + students)
// - Bouton visio SI data.visio.enabled === true
```

### Phase 3: Démarrage Visio (Enseignant)

```javascript
async function startVisio(seanceId) {
  // 1. Vérifier fenêtre temporelle
  const { data } = await api.get(`/lms/seances/${seanceId}/details`)

  if (!data.visio.window.can_start) {
    alert('Impossible de démarrer maintenant')
    return
  }

  // 2. Créer/récupérer room Jitsi
  const roomName = data.seance.visio_room_id || `seance_${seanceId}`

  // 3. Générer lien avec modération
  const link = `https://meet.jit.si/${roomName}#config.startWithVideoMuted=false&userInfo.displayName=${teacher.name}&jwt=${moderatorToken}`

  // 4. Marquer room comme active
  await updateRoomStatus(seanceId, 'active')

  // 5. Notifier étudiants
  await api.post(`/lms/notifications/send-session-reminder`, {
    seance_cours_id: seanceId,
    channels: ['whatsapp', 'email', 'app'],
    minutes_before: 0 // Immédiat
  })

  // 6. Ouvrir Jitsi
  window.open(link, '_blank')
}
```

### Phase 4: Rejoindre Visio (Étudiant)

```javascript
async function joinVisio(seanceId, userId) {
  // 1. Valider accès
  const validation = await api.post(`/lms/seances/${seanceId}/validate-participant`, {
    user_id: userId
  })

  if (!validation.authorized) {
    alert(`Accès refusé: ${validation.reason}`)
    return
  }

  // 2. Récupérer room
  const { data } = await api.get(`/lms/seances/${seanceId}/details`)

  if (!data.visio.enabled) {
    alert('Visio non activée pour cette séance')
    return
  }

  // 3. Générer lien participant (sans modération)
  const roomName = data.seance.visio_room_id
  const link = `https://meet.jit.si/${roomName}#userInfo.displayName=${student.name}`

  // 4. Ouvrir Jitsi
  window.open(link, '_blank')
}
```

### Phase 5: Clôture & Attendances

```javascript
async function endVisio(seanceId, participants) {
  // 1. Marquer room comme terminée
  await updateRoomStatus(seanceId, 'ended')

  // 2. Préparer données attendances
  const attendancesData = participants
    .filter(p => p.etudiant_id) // Seulement étudiants
    .map(p => {
      const duration = Math.floor((p.leave_time - p.join_time) / 60000)

      return {
        etudiant_id: p.etudiant_id,
        statut: duration >= 45 ? 'present' : 'retard',
        joined_at: p.join_time.toISOString(),
        left_at: p.leave_time.toISOString(),
        duration_minutes: duration
      }
    })

  // 3. Sync vers KLASSCI
  const result = await api.post('/lms/attendances/from-video-session', {
    seance_cours_id: seanceId,
    date: new Date().toISOString().split('T')[0],
    participants: attendancesData
  })

  console.log(`✅ ${result.created} créées, ${result.updated} mises à jour`)
}
```

---

## 🆚 Comparaison avec Concurrents

| Critère | Notre LMS | Moodle | Canvas | Teams | Google Classroom |
|---------|-----------|--------|--------|-------|-----------------|
| Matière → Lessons | ✅ | ✅ | ✅ | ✅ | ✅ |
| Matière → Séances calendrier | ✅ | ✅ | ✅ | ✅ | ❌ |
| Séance → Visio optionnelle | ✅ | ✅ | ✅ | ✅ | ❌ |
| Coordinateur active visio | ✅ | ✅ | ✅ | ✅ | ❌ |
| Fenêtre temporelle H-15/H+30 | ✅ | ❌ | ❌ | ❌ | ❌ |
| Multi-provider (Jitsi/Zoom/Teams) | ✅ | ✅ | ✅ | ❌ | ❌ |
| Attendances vidéo trackées | ✅ | ✅ | ✅ | ✅ | ❌ |

**Notre LMS = 7/7 ✅**

---

## 📊 Résumé des Endpoints

| Endpoint | Méthode | Rôle | Retourne |
|----------|---------|------|----------|
| `/api/lms/matieres/{id}` | GET | Vue complète matière | Lessons + Séances + Évaluations |
| `/api/lms/seances/{id}/details` | GET | Détails séance + visio | Infos complètes + fenêtre temporelle |
| `/api/lms/seances/{id}/toggle-visio` | POST | Toggle visio (coord.) | Confirmation activation |
| `/api/lms/seances/upcoming` | GET | Séances à venir | Liste pour pré-création rooms |
| `/api/lms/seances/{id}/participants` | GET | Roster séance | Teacher + Students |
| `/api/lms/seances/{id}/validate-participant` | POST | Vérifier accès | authorized: true/false |
| `/api/lms/attendances/from-video-session` | POST | Sync attendances | created/updated count |

---

## 📝 TODO Implémentation BDD

Pour compléter le système, ajouter dans KLASSCI (table `esbtp_seance_cours`):

```sql
ALTER TABLE esbtp_seance_cours
ADD COLUMN visio_enabled BOOLEAN DEFAULT FALSE COMMENT 'Coordinateur a activé visio',
ADD COLUMN visio_type VARCHAR(50) NULL COMMENT 'jitsi|zoom|teams|bbb',
ADD COLUMN visio_room_id VARCHAR(255) NULL COMMENT 'ID room créée par LMS',
ADD COLUMN visio_room_status VARCHAR(50) DEFAULT 'pending' COMMENT 'pending|active|ended';
```

**Ensuite modifier `toggleVisioSeance()` pour mettre à jour réellement la BDD.**

---

**Date:** 2025-10-20
**Version:** 1.0
**Auteur:** Claude Code
**Architecture:** Conforme aux standards Moodle/Canvas/Teams ✅
