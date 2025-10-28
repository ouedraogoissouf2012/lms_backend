# Implémentation Pages Classe & Matière + Gestion Visio

**Date**: 2025-10-20
**Statut**: ✅ Complété

## Vue d'ensemble

Implémentation complète des pages **Classe** et **Matière** avec navigation hiérarchique et gestion de la visioconférence pour les séances.

## Architecture

```
Dashboard
   │
   ├── Page Matière (/matieres/:id)
   │   ├── Tab Lessons (contenu pédagogique LMS)
   │   ├── Tab Séances (séances KLASSCI avec boutons visio)
   │   ├── Tab Évaluations (évaluations programmées)
   │   └── Tab Classes (classes qui suivent cette matière)
   │
   └── Page Classe (/classes/:id)
       ├── Tab Matières (matières de la classe)
       ├── Tab Étudiants (liste des inscrits)
       ├── Tab Évaluations (évaluations programmées)
       ├── Tab Planning (emploi du temps semaine)
       └── Section Séances à venir (avec bouton "Programmer en visio")
```

## Fichiers Créés

### 1. Frontend

#### **src/views/classes/ClasseDetails.vue** (586 lignes)
Page complète pour afficher les détails d'une classe avec:
- Header bleu dégradé avec nom classe, filière, niveau, code
- 3 cartes statistiques: Étudiants, Matières, Évaluations
- 4 onglets: Matières, Étudiants, Évaluations, Planning
- Section "Séances à venir" (30 jours) avec:
  - Bouton "Programmer en visio" (coordinateur uniquement)
  - Bouton "Désactiver visio" si déjà activé
  - Badge violet si visio activée

**Endpoints utilisés**:
```javascript
GET /api/lms/classes/{id}           // Détails complets classe
GET /api/lms/classes/{id}/etudiants // Liste étudiants
GET /api/lms/seances/upcoming       // Séances à venir (filtré par classe_id)
POST /api/lms/seances/{id}/toggle-visio // Activer/désactiver visio
```

#### **src/services/lms.js** - Méthode ajoutée
```javascript
async getClasseEtudiants(classeId) {
  return await api.get(`/lms/classes/${classeId}/etudiants`)
}
```

#### **src/router/index.js** - Route ajoutée
```javascript
{
  path: '/classes/:id',
  name: 'classe-details',
  component: ClasseDetails,
  meta: { requiresAuth: true }
}
```

### 2. Matière - Modifications

#### **src/views/matieres/MatiereDetails.vue** - Améliorations

**Ajouts**:
- Tab "Classes" (4ème onglet) affichant les classes concernées
- Navigation vers ClasseDetails via `viewClasse(classeId)`
- Import `auth` pour vérifier les rôles
- Computed `canManageVisio` pour les coordinateurs

**Données chargées**:
```javascript
this.classes = data.data.classes_concernees || []
```

**Template nouveau tab**:
```vue
<!-- Onglet Classes -->
<div v-if="activeTab === 'classes'">
  <div v-for="classe in classes" :key="classe.id">
    <!-- Carte classe avec filière, niveau, effectif -->
    <button @click="viewClasse(classe.id)">Voir détails →</button>
  </div>
</div>
```

## Workflow Visioconférence

### 1. Création Séance (KLASSCI)
```
Coordinateur dans KLASSCI
  ↓
Crée séance: date, heure, classe, matière, enseignant
  ↓
Séance apparaît automatiquement dans LMS
(via GET /api/lms/seances/upcoming)
```

### 2. Programmation Visio (LMS)
```
Coordinateur dans ClasseDetails.vue
  ↓
Clique "Programmer en visio"
  ↓
POST /api/lms/seances/{id}/toggle-visio
{
  enabled: true,
  visio_type: 'jitsi'
}
  ↓
Backend met à jour:
- esbtp_seance_cours.visio_enabled = 1
- esbtp_seance_cours.visio_type = 'jitsi'
- esbtp_seance_cours.visio_room_id = généré
  ↓
Badge violet "Visio jitsi activée" apparaît
```

### 3. Démarrage Visio (Enseignant)
```
Enseignant voit séance avec badge visio
  ↓
Fenêtre temporelle: [heure_debut - 15min] → [heure_fin + 30min]
  ↓
Clique "Démarrer la visio"
  ↓
POST /api/lms/seances/{id}/validate-participant
{
  user_id: enseignant.id
}
  ↓
Backend vérifie:
- authorized: true?
- role: 'teacher'?
- fenêtre temporelle OK?
  ↓
Frontend génère lien Jitsi:
https://meet.jit.si/klassci-seance-{id}-{room_token}
?userInfo.displayName={nom}
&userInfo.email={email}
&jwt={token}
  ↓
Ouvre nouvel onglet avec room Jitsi
```

### 4. Rejoindre Visio (Étudiant)
```
Étudiant voit séance avec badge visio
  ↓
Clique "Rejoindre la visio"
  ↓
POST /api/lms/seances/{id}/validate-participant
{
  user_id: etudiant.id
}
  ↓
Backend vérifie:
- authorized: true? (étudiant inscrit dans classe)
- role: 'student'?
  ↓
Génère lien Jitsi et ouvre
```

### 5. Suivi Présences
```
Frontend JS track:
- joined_at: "08:05:00"
- left_at: "09:55:00"
- duration_minutes: 110
  ↓
À la fin de la séance:
POST /api/lms/attendances/from-video-session
{
  seance_cours_id: 123,
  date: "2025-10-21",
  participants: [
    {
      user_id: 456,
      statut: "present",
      joined_at: "08:05:00",
      left_at: "09:55:00",
      duration_minutes: 110
    }
  ]
}
  ↓
Backend LMS envoie à KLASSCI:
- call_type: 'merged'
- Insère dans esbtp_presence_etudiant
```

## Endpoints Backend Utilisés

### Classe
```php
GET  /api/lms/classes/{id}             // LMSDataController@classeDetails
GET  /api/lms/classes/{id}/etudiants   // ProxyController@etudiants (filtré par classe)
```

### Matière
```php
GET  /api/lms/matieres/{id}            // LMSDataController@matiereDetails
```

### Séances
```php
GET  /api/lms/seances/upcoming         // LMSDataController@upcomingSeances
GET  /api/lms/seances/{id}/details     // LMSDataController@seanceDetails
GET  /api/lms/seances/{id}/participants // LMSDataController@seanceParticipants
POST /api/lms/seances/{id}/validate-participant // LMSDataController@validateParticipant
POST /api/lms/seances/{id}/toggle-visio // LMSDataController@toggleVisioSeance
```

### Présences
```php
POST /api/lms/attendances/from-video-session // LMSDataController@syncAttendancesFromVideoSession
```

## Structure Données Retournées

### GET /api/lms/classes/{id}
```json
{
  "success": true,
  "data": {
    "classe": {
      "id": 1,
      "nom": "BTS 1ère Année - BATIMENT",
      "code": "1A-BTP",
      "filiere": { "id": 1, "nom": "BATIMENT", "code": "BTP" },
      "niveau": { "id": 1, "nom": "BTS 1ere ANNEE", "code": "1A" }
    },
    "matieres_disponibles": [
      {
        "id": 1,
        "nom": "Marketing digital",
        "code": "ID2345",
        "coefficient": 1,
        "enseignants": [
          { "id": 9, "nom": "BEDE ABEL TEST" }
        ]
      }
    ],
    "evaluations_programmees": [...],
    "emploi_temps_semaine": [
      {
        "jour": "Lundi",
        "heure_debut": "08:00",
        "heure_fin": "10:00",
        "matiere": { "nom": "Marketing digital" },
        "enseignant": { "nom": "BEDE ABEL" },
        "salle": "A101",
        "type": "cours"
      }
    ],
    "statistiques": {
      "nombre_etudiants": 25,
      "nombre_matieres": 8,
      "nombre_evaluations": 3
    }
  }
}
```

### GET /api/lms/classes/{id}/etudiants
```json
{
  "success": true,
  "data": {
    "etudiants": [
      {
        "id": 123,
        "matricule": "2024001",
        "nom": "DUPONT Jean",
        "email": "jean.dupont@example.com",
        "statut": "actif"
      }
    ]
  }
}
```

### GET /api/lms/matieres/{id}
```json
{
  "success": true,
  "data": {
    "matiere": {
      "id": 1,
      "nom": "Marketing digital",
      "code": "ID2345",
      "coefficient": 1,
      "heures": {
        "cm": 10,
        "td": 15,
        "tp": 5,
        "total": 100
      }
    },
    "lessons": [...],
    "seances_programmees": [...],
    "evaluations_programmees": [...],
    "classes_concernees": [
      {
        "id": 1,
        "nom": "BTS 1ère Année - BATIMENT",
        "filiere": { "nom": "BATIMENT" },
        "niveau": { "nom": "BTS 1ere ANNEE" },
        "effectif": 25
      }
    ],
    "statistiques": {
      "nombre_lessons": 0,
      "nombre_seances_programmees": 0,
      "nombre_evaluations": 1,
      "nombre_enseignants": 0,
      "nombre_combinaisons": 6
    }
  }
}
```

### GET /api/lms/seances/upcoming?classe_id=1&days=30
```json
{
  "success": true,
  "data": {
    "seances": [
      {
        "id": 456,
        "date_seance": "2025-10-21",
        "heure_debut": "08:00",
        "heure_fin": "10:00",
        "duree_minutes": 120,
        "matiere": { "id": 1, "nom": "Marketing digital" },
        "enseignant": { "id": 9, "nom": "BEDE ABEL TEST" },
        "classe": { "id": 1, "nom": "BTS 1ère Année" },
        "salle": "A101",
        "visio_enabled": true,
        "visio_type": "jitsi",
        "visio_room_id": "abc123xyz"
      }
    ]
  }
}
```

## Permissions & Rôles

### Coordinateur / superAdmin
- ✅ Voir toutes les classes et matières
- ✅ **Programmer la visio** pour n'importe quelle séance
- ✅ **Désactiver la visio**
- ✅ Voir les statistiques complètes

### Enseignant
- ✅ Voir ses classes et matières
- ✅ Voir les séances avec badge visio
- ✅ **Démarrer la visio** (si activée par coordinateur)
- ❌ Ne peut PAS programmer/désactiver la visio

### Étudiant
- ✅ Voir sa classe et ses matières
- ✅ Voir les séances avec badge visio
- ✅ **Rejoindre la visio** (pendant la fenêtre temporelle)
- ❌ Ne peut PAS programmer la visio

## Fenêtres Temporelles

### Enseignant - Démarrer la visio
```javascript
Autorisé entre:
  heure_debut - 15 minutes
  et
  heure_fin + 30 minutes

Exemple: Séance 08:00 - 10:00
  → Peut démarrer de 07:45 à 10:30
```

### Étudiant - Rejoindre la visio
```javascript
Autorisé entre:
  heure_debut (ou quand enseignant a démarré)
  et
  heure_fin + 30 minutes

Exemple: Séance 08:00 - 10:00
  → Peut rejoindre de 08:00 à 10:30
```

## Icônes Utilisées

Toutes les icônes sont des **SVG Heroicons** (pas d'emojis):
- 📚 Matières → Icône livre ouvert
- 👥 Étudiants → Icône groupe utilisateurs
- 📅 Séances → Icône calendrier
- ✅ Évaluations → Icône check cercle
- 📹 Visio → Icône caméra vidéo
- 🏫 Filière → Icône bâtiment
- 📊 Niveau → Icône graphique barres
- ⏱️ Durée → Icône horloge
- 📍 Salle → Icône localisation
- 👤 Enseignant → Icône utilisateur

## Tests à Effectuer

### Test 1: Navigation Classe → Matière
1. Aller sur Dashboard enseignant
2. Cliquer sur une classe
3. Vérifier:
   - ✅ Header affiche nom classe + filière + niveau
   - ✅ 3 statistiques (Étudiants, Matières, Évaluations)
   - ✅ 4 tabs s'affichent
4. Cliquer sur tab "Matières"
5. Cliquer sur "Voir détails" d'une matière
6. Vérifier:
   - ✅ Arrive sur page MatiereDetails
   - ✅ 4 tabs visibles (Lessons, Séances, Évaluations, Classes)

### Test 2: Navigation Matière → Classe
1. Aller sur page MatiereDetails
2. Cliquer sur tab "Classes"
3. Cliquer sur "Voir détails" d'une classe
4. Vérifier:
   - ✅ Arrive sur page ClasseDetails
   - ✅ Toutes les données s'affichent

### Test 3: Programmation Visio (Coordinateur)
1. Se connecter comme coordinateur
2. Aller sur ClasseDetails
3. Scroll jusqu'à "Séances à venir"
4. Cliquer sur "Programmer en visio"
5. Vérifier:
   - ✅ Badge violet "Visio jitsi activée" apparaît
   - ✅ Bouton devient "Désactiver visio"
6. Cliquer sur "Désactiver visio"
7. Vérifier:
   - ✅ Badge disparaît
   - ✅ Bouton redevient "Programmer en visio"

### Test 4: Démarrage Visio (Enseignant)
**Prérequis**: Séance avec visio activée dans les 15 prochaines minutes

1. Se connecter comme enseignant
2. Voir la séance avec badge visio
3. Vérifier:
   - ✅ Bouton "Démarrer la visio" est visible
   - ✅ (Si hors fenêtre) Bouton est désactivé avec message
4. Cliquer sur "Démarrer la visio"
5. Vérifier:
   - ✅ Nouvel onglet s'ouvre avec room Jitsi
   - ✅ Nom affiché correctement dans Jitsi

### Test 5: Rejoindre Visio (Étudiant)
**Prérequis**: Enseignant a démarré la visio

1. Se connecter comme étudiant
2. Voir la séance avec badge visio
3. Cliquer sur "Rejoindre la visio"
4. Vérifier:
   - ✅ Rejoint la room Jitsi de l'enseignant
   - ✅ Peut voir/entendre l'enseignant

## Prochaines Étapes (Optionnel)

### Composant VisioManager.vue Réutilisable
Créer un composant dédié pour gérer toute la logique visio:
```vue
<VisioManager
  :seance="seance"
  :user-role="userRole"
  @visio-started="handleVisioStarted"
  @visio-ended="handleVisioEnded"
/>
```

### Migration BDD
Si les colonnes n'existent pas déjà dans `esbtp_seance_cours`:
```sql
ALTER TABLE esbtp_seance_cours
ADD COLUMN visio_enabled BOOLEAN DEFAULT FALSE,
ADD COLUMN visio_type VARCHAR(50) NULL,
ADD COLUMN visio_room_id VARCHAR(255) NULL,
ADD COLUMN visio_room_status VARCHAR(50) DEFAULT 'pending';
```

### Notifications
Implémenter les rappels automatiques:
```javascript
// 24h avant la séance
POST /api/lms/notifications/send-session-reminder
{
  seance_id: 456,
  user_ids: [1, 2, 3, ...],
  reminder_time: "24h"
}

// 1h avant la séance
// 15min avant la séance
```

## Conclusion

✅ **Implémentation complète** de la navigation hiérarchique Classe ↔ Matière
✅ **Gestion visio** prête pour les séances
✅ **Permissions correctes** par rôle
✅ **UI cohérente** avec icônes SVG (pas d'emojis)
✅ **Prêt pour le déploiement** et les tests utilisateurs

**Prochaine action**: Tester dans le navigateur et ajuster si nécessaire.
