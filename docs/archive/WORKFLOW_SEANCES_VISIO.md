# Workflow Complet: Séances & Visioconférences

**Date**: 2025-10-20
**Architecture**: KLASSCI (source) → LMS (visio) → KLASSCI (présences)

---

## 🎯 Principes Fondamentaux

### Séparation des Responsabilités

| Élément | Responsable | Stockage |
|---------|-------------|----------|
| **Séances** (date, heure, matière, classe, enseignant, salle) | KLASSCI | KLASSCI uniquement |
| **Visio** (enabled, type, room_id, active, timestamps) | LMS | Table `seances` locale |
| **Inscriptions** (étudiants, enseignants) | KLASSCI | KLASSCI uniquement |
| **Présences** (joined_at, left_at, duration) | LMS tracking | KLASSCI (sync final) |

### Table LMS: `seances`

```sql
seances:
  id (PK local)
  klassci_seance_id (unique) ← Référence KLASSCI!
  klassci_matiere_id (copie pour joins)
  klassci_classe_id (copie pour joins)
  klassci_enseignant_id (copie pour joins)

  -- Visio uniquement
  visio_enabled (boolean)
  visio_type (enum: jitsi, zoom, teams, bbb)
  visio_room_id (varchar)
  visio_active (boolean)
  visio_started_at (timestamp)
  visio_ended_at (timestamp)

  created_by, updated_by
  timestamps, soft_deletes
```

**Important**: Pas de colonnes `date_seance`, `heure_debut`, `heure_fin` car ces données viennent toujours de KLASSCI!

---

## 📋 Workflow Étape par Étape

### Étape 1: Création Séance dans KLASSCI

**Acteur**: Coordinateur
**Plateforme**: KLASSCI

```
Coordinateur KLASSCI crée séance:
  - Matière: Mathématiques
  - Classe: L1 Info A
  - Enseignant: Prof. Dupont
  - Date: 2025-10-21
  - Heure: 10:00 - 12:00
  - Salle: A101

KLASSCI génère: seance_id = 123
```

---

### Étape 2: LMS Récupère Séances

**Endpoint**: `GET /api/lms/seances/upcoming?days=30`

**Flux**:
```php
1. LMS appelle KLASSCI:
   GET /emploi-temps?date_debut=2025-10-20&date_fin=2025-11-20

2. KLASSCI retourne:
   [
     {
       id: 123,
       matiere: { id: 2, nom: "Mathématiques" },
       classe: { id: 1, nom: "L1 Info A" },
       enseignant: { id: 326, nom: "Dupont" },
       date_seance: "2025-10-21",
       heure_debut: "10:00:00",
       heure_fin: "12:00:00",
       salle: "A101"
     }
   ]

3. LMS enrichit avec données visio locales:
   $visio = Seance::byKlassciId(123)->first();

   Si trouvé:
     seance['visio_enabled'] = $visio->visio_enabled
     seance['visio_room_id'] = $visio->visio_room_id
     ...
   Sinon:
     seance['visio_enabled'] = false
     seance['visio_type'] = null
     ...

4. LMS retourne séances enrichies au frontend
```

**Résultat Frontend**:
```javascript
{
  success: true,
  data: [
    {
      id: 123, // ID KLASSCI
      matiere: { id: 2, nom: "Mathématiques" },
      date_seance: "2025-10-21",
      heure_debut: "10:00:00",
      heure_fin: "12:00:00",
      visio_enabled: false, // ← Pas encore programmée
      visio_room_id: null
    }
  ]
}
```

---

### Étape 3: Coordinateur Active Visio

**Acteur**: Coordinateur
**Plateforme**: LMS
**Endpoint**: `POST /api/lms/seances/{seanceId}/toggle-visio`

**Flux**:
```php
1. Frontend envoie:
   POST /api/lms/seances/123/toggle-visio
   {
     enabled: true,
     visio_type: "jitsi"
   }

2. Backend vérifie:
   - User est coordinateur? ✅
   - Token KLASSCI présent? ✅

3. Backend récupère séance KLASSCI:
   GET /seances/123
   → { matiere_id: 2, classe_id: 1, enseignant_id: 326 }

4. Backend crée/met à jour table locale:
   Seance::updateOrCreate(
     ['klassci_seance_id' => 123],
     [
       'klassci_matiere_id' => 2,
       'klassci_classe_id' => 1,
       'klassci_enseignant_id' => 326,
       'visio_enabled' => true,
       'visio_type' => 'jitsi',
       'visio_room_id' => 'lms_seance_123_1729421000',
       'visio_active' => false,
       'created_by' => coordinateur_id
     ]
   )

5. Retourne:
   {
     success: true,
     message: "Visioconférence activée avec succès",
     data: {
       seance_id: 123,
       visio_enabled: true,
       visio_type: "jitsi",
       visio_room_id: "lms_seance_123_1729421000"
     }
   }
```

**Résultat**: Badge "Visio jitsi activée" apparaît dans le frontend

---

### Étape 4: Enseignant Démarre Visio

**Acteur**: Enseignant
**Plateforme**: LMS Frontend
**Timing**: H-15min à H+30min

**Frontend vérifie fenêtre**:
```javascript
const canStartVisio = (seance) => {
  const now = new Date();
  const debut = new Date(seance.date_seance + ' ' + seance.heure_debut);
  const fin = new Date(seance.date_seance + ' ' + seance.heure_fin);

  const windowStart = debut.getTime() - (15 * 60 * 1000); // -15min
  const windowEnd = fin.getTime() + (30 * 60 * 1000);     // +30min

  return now >= windowStart && now <= windowEnd;
};

// Bouton "Démarrer" actif uniquement si dans fenêtre
```

**Flux**:
```javascript
1. Enseignant clique "Démarrer la visio"

2. Frontend valide participant:
   POST /api/lms/seances/123/validate-participant
   { user_id: 326 }

3. Backend vérifie (KLASSCI):
   - Récupère séance 123 depuis KLASSCI
   - Vérifie enseignant_id == 326
   → { authorized: true, role: "teacher" }

4. Frontend génère lien Jitsi:
   jitsiService.generateRoomLink(seance)
   → https://meet.jit.si/LMS-Math-L1InfoA-2025-10-21-abc12345

5. Frontend track participation:
   jitsiService.trackParticipantJoin(123, 326)
   → localStorage: video_joined_at = "2025-10-21T10:00:00Z"

6. Frontend ouvre Jitsi:
   window.open(jitsiUrl)

7. Frontend met à jour séance locale:
   visio_active = true
   visio_started_at = now
```

---

### Étape 5: Étudiant Rejoint Visio

**Acteur**: Étudiant
**Condition**: Visio doit être `active = true`

**Frontend vérifie**:
```javascript
// Badge "En cours" visible uniquement si visio_active = true
<div v-if="seance.visio_active">
  <span class="badge-green">En cours</span>
  <button @click="rejoindreVisio">Rejoindre la visio</button>
</div>

// Sinon
<div v-else-if="seance.visio_enabled">
  <span class="badge-gray">En attente de l'enseignant</span>
  <button disabled>Rejoindre la visio</button>
</div>
```

**Flux**:
```javascript
1. Étudiant clique "Rejoindre la visio"

2. Frontend valide participant:
   POST /api/lms/seances/123/validate-participant
   { user_id: 2740 }

3. Backend vérifie (KLASSCI):
   - Récupère séance 123 depuis KLASSCI
   - Extrait classe_id = 1
   - Cherche étudiant dans classe 1:
     GET /classes/1/etudiants
     → Filtre: user_id == 2740 && statut == 'actif'

   Si trouvé:
     → { authorized: true, role: "student" }
   Sinon:
     → { authorized: false, reason: "not_enrolled_in_class" }

4. Si authorized, frontend:
   - Génère même lien Jitsi (même room!)
   - Track participation
   - Ouvre Jitsi
```

---

### Étape 6: Synchronisation Présences

**Timing**: À la fermeture de la fenêtre Jitsi
**Endpoint**: `POST /api/lms/attendances/from-video-session`

**Flux**:
```javascript
1. Fenêtre Jitsi fermée → Frontend détecte

2. Frontend track sortie:
   jitsiService.trackParticipantLeave(123, 2740)
   → localStorage:
     {
       seance_id: 123,
       user_id: 2740,
       joined_at: "2025-10-21T10:05:00Z",
       left_at: "2025-10-21T11:30:00Z",
       duration_minutes: 85
     }

3. Frontend sync vers backend:
   POST /api/lms/attendances/from-video-session
   {
     seance_cours_id: 123,
     date: "2025-10-21",
     participants: [
       {
         etudiant_id: 2740,
         statut: "present",
         video_joined_at: "2025-10-21 10:05:00",
         video_left_at: "2025-10-21 11:30:00",
         video_duration_minutes: 85
       }
     ]
   }

4. Backend crée/met à jour attendance:
   ESBTPAttendance::updateOrCreate(
     [
       'seance_cours_id' => 123,
       'etudiant_id' => 2740,
       'date' => '2025-10-21'
     ],
     [
       'statut' => 'present',
       'call_type' => 'merged', // ← ESSENTIEL!
       'video_joined_at' => '2025-10-21 10:05:00',
       'video_left_at' => '2025-10-21 11:30:00',
       'video_duration_minutes' => 85,
       'commentaire' => '[2025-10-21 11:30:15] Visio: ...'
     ]
   )

5. KLASSCI reçoit la présence:
   - Visible dans finalOnly() scope (grâce à call_type='merged')
   - Colonnes video_* permettent de distinguer visio vs présentiel
```

---

## 🔍 Points Clés à Retenir

### 1. Séances = KLASSCI
- Les données séance (date, heure, matière, classe) viennent **toujours** de KLASSCI
- Le LMS ne duplique **jamais** ces données
- Le LMS stocke uniquement les références (`klassci_seance_id`, etc.)

### 2. Visio = LMS
- Le LMS gère **uniquement** la partie visioconférence
- KLASSCI ne sait rien de la visio
- Les données visio sont dans la table `seances` locale

### 3. Validation = KLASSCI
- Toutes les validations (enseignant, étudiant) se font contre KLASSCI
- Pas de cache local des inscriptions
- Requêtes live à chaque validation

### 4. Présences = LMS → KLASSCI
- Le LMS track les participations vidéo (frontend)
- Le LMS sync les présences vers KLASSCI (backend)
- `call_type='merged'` pour inclure dans les stats

### 5. Fenêtre Temporelle = Frontend
- La logique H-15min / H+30min est côté frontend
- Le backend ne fait que valider les autorisations
- Pas de gestion automatique du statut `visio_active`

---

## 🧪 Tests

### Test 1: Récupération Séances
```bash
# Frontend
GET /api/lms/seances/upcoming?days=30

# Résultat attendu:
# - Séances de KLASSCI avec visio_enabled = false (si pas activé)
# - Ou visio_enabled = true (si coordinateur a activé)
```

### Test 2: Activation Visio
```bash
# Coordinateur
POST /api/lms/seances/123/toggle-visio
{
  "enabled": true,
  "visio_type": "jitsi"
}

# Vérifier BDD:
SELECT * FROM seances WHERE klassci_seance_id = 123;
# → visio_enabled = 1, visio_room_id généré
```

### Test 3: Validation Enseignant
```bash
POST /api/lms/seances/123/validate-participant
{ "user_id": 326 }

# Résultat:
{ "authorized": true, "role": "teacher" }
```

### Test 4: Validation Étudiant
```bash
POST /api/lms/seances/123/validate-participant
{ "user_id": 2740 }

# Résultat:
{ "authorized": true, "role": "student" }
# OU
{ "authorized": false, "reason": "not_enrolled_in_class" }
```

### Test 5: Sync Présences
```bash
POST /api/lms/attendances/from-video-session
{
  "seance_cours_id": 123,
  "date": "2025-10-21",
  "participants": [...]
}

# Vérifier BDD:
SELECT * FROM esbtp_attendances WHERE seance_cours_id = 123;
# → call_type = 'merged', video_* colonnes remplies
```

---

## 📊 Schéma de Données

```
┌─────────────────────────────────────────────────────────┐
│ KLASSCI (PostgreSQL)                                    │
├─────────────────────────────────────────────────────────┤
│ esbtp_seance_cours:                                     │
│   - id (123)                                            │
│   - matiere_id (2)                                      │
│   - classe_id (1)                                       │
│   - enseignant_id (326)                                 │
│   - date_seance (2025-10-21)                            │
│   - heure_debut (10:00:00)                              │
│   - heure_fin (12:00:00)                                │
│   - salle (A101)                                        │
└─────────────────────────────────────────────────────────┘
                        ↕
        GET /emploi-temps, GET /seances/{id}
                        ↕
┌─────────────────────────────────────────────────────────┐
│ LMS (SQLite/MySQL)                                      │
├─────────────────────────────────────────────────────────┤
│ seances:                                                │
│   - id (1) ← Local                                      │
│   - klassci_seance_id (123) ← Référence!                │
│   - klassci_matiere_id (2)                              │
│   - klassci_classe_id (1)                               │
│   - klassci_enseignant_id (326)                         │
│   - visio_enabled (true)                                │
│   - visio_type (jitsi)                                  │
│   - visio_room_id (lms_seance_123_...)                  │
│   - visio_active (true)                                 │
│   - visio_started_at (2025-10-21 10:00:00)              │
└─────────────────────────────────────────────────────────┘
                        ↕
     POST /attendances/from-video-session
                        ↕
┌─────────────────────────────────────────────────────────┐
│ KLASSCI (PostgreSQL)                                    │
├─────────────────────────────────────────────────────────┤
│ esbtp_attendances:                                      │
│   - seance_cours_id (123)                               │
│   - etudiant_id (2740)                                  │
│   - date (2025-10-21)                                   │
│   - statut (present)                                    │
│   - call_type (merged) ← Important!                     │
│   - video_joined_at (2025-10-21 10:05:00)               │
│   - video_left_at (2025-10-21 11:30:00)                 │
│   - video_duration_minutes (85)                         │
└─────────────────────────────────────────────────────────┘
```

---

## ✅ Checklist Implémentation

- [x] Migration table `seances` (références KLASSCI + colonnes visio)
- [x] Modèle `Seance` avec scopes et méthodes
- [x] Endpoint `upcomingSeances` enrichi avec visio
- [x] Endpoint `toggleVisioSeance` avec validation KLASSCI
- [x] Endpoint `validateParticipant` utilise KLASSCI direct
- [x] Frontend VisioManager avec gestion fenêtre temporelle
- [x] Frontend jitsi.js avec tracking et sync
- [ ] Tests E2E complets
- [ ] Documentation utilisateur

---

## 🚀 Prochaines Étapes

1. Tester le workflow complet avec des vraies données KLASSCI
2. Vérifier que KLASSCI a bien des séances planifiées
3. Créer des séances de test si besoin
4. Valider l'intégration frontend ↔ backend
5. Documenter pour les utilisateurs

**Auteur**: Claude
**Date**: 2025-10-20
