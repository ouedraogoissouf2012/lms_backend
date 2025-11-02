# Schéma de Base de Données - LMS KLASSCI Backend

**Version :** 1.0.0
**Date :** 14 Octobre 2025
**Type :** Hybrid (KLASSCI Sync + LMS Local)

---

## 🎯 Architecture Générale

Le système utilise une approche **hybride** :
- **Entités KLASSCI** : Synchronisées depuis l'API KLASSCI (Matières, Classes, Utilisateurs)
- **Entités LMS** : Créées localement (Lessons, Forum, Quiz, Fichiers)
- **Relations** : Foreign keys entre les deux systèmes

---

## 📊 Diagramme Entité-Relation

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         SYSTÈME KLASSCI (Externe)                        │
│                                                                          │
│  ┌──────────┐    ┌──────────┐    ┌──────────┐    ┌───────────────┐   │
│  │ Filières │────│ Niveaux  │────│  Années   │────│   Semestres   │   │
│  └──────────┘    └──────────┘    └──────────┘    └───────────────┘   │
│       │                                                    │            │
│       │                                                    │            │
└───────┼────────────────────────────────────────────────────┼────────────┘
        │                                                    │
        ↓ Sync                                               ↓ Sync
┌─────────────────────────────────────────────────────────────────────────┐
│                      BASE DE DONNÉES LOCALE (MySQL)                      │
│                                                                          │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │                  ENTITÉS SYNCHRONISÉES                          │   │
│  │                                                                 │   │
│  │  ┌──────────┐         ┌──────────┐                            │   │
│  │  │ users    │         │ matieres │                            │   │
│  │  │          │         │          │                            │   │
│  │  │ id       │         │ id       │                            │   │
│  │  │klassci_id│         │klassci_id│                            │   │
│  │  │name      │         │libelle   │                            │   │
│  │  │email     │         │code      │                            │   │
│  │  │role      │         │coefficient                            │   │
│  │  └────┬─────┘         └────┬─────┘                            │   │
│  │       │                    │                                  │   │
│  └───────┼────────────────────┼──────────────────────────────────┘   │
│          │                    │                                       │
│          │  ┌──────────┐      │                                       │
│          │  │ classes  │      │                                       │
│          │  │          │      │                                       │
│          │  │ id       │      │                                       │
│          │  │klassci_id│      │                                       │
│          │  │libelle   │      │                                       │
│          │  │effectif  │      │                                       │
│          │  └────┬─────┘      │                                       │
│          │       │            │                                       │
│          │       │            │                                       │
│  ┌───────┴───────┴────────────┴──────────────────────────────────┐   │
│  │                  TABLES PIVOT (Relations N-N)                 │   │
│  │                                                               │   │
│  │  ┌─────────────────┐       ┌─────────────────┐              │   │
│  │  │classe_etudiant  │       │classe_matiere   │              │   │
│  │  │                 │       │                 │              │   │
│  │  │classe_id   ────────┐    │classe_id   ─────────┐         │   │
│  │  │user_id     ────────┼────│matiere_id  ─────────┼─────┐   │   │
│  │  │statut           │  │    │enseignant_id    │   │     │   │   │
│  │  │date_inscription │  │    │coefficient      │   │     │   │   │
│  │  └─────────────────┘  │    └─────────────────┘   │     │   │   │
│  └───────────────────────┼──────────────────────────┼─────┼───┘   │
│                          │                          │     │       │
│                          ↓                          ↓     ↓       │
│  ┌─────────────────────────────────────────────────────────────┐ │
│  │                 ENTITÉS LMS LOCALES                         │ │
│  │                                                             │ │
│  │  ┌──────────┐         ┌──────────────┐                    │ │
│  │  │ lessons  │         │lesson_progress                    │ │
│  │  │          │         │              │                    │ │
│  │  │ id       │──────┐  │ id           │                    │ │
│  │  │matiere_id│←─────┼──│ lesson_id    │                    │ │
│  │  │classe_id │←─────┘  │ user_id      │                    │ │
│  │  │enseign_id│←────────│ status       │                    │ │
│  │  │title     │         │ progress_%   │                    │ │
│  │  │content   │         │ time_spent   │                    │ │
│  │  │status    │         │ rating       │                    │ │
│  │  └──────────┘         └──────────────┘                    │ │
│  │                                                            │ │
│  │  ┌─────────────┐      ┌─────────────┐                    │ │
│  │  │forum_topics │      │ forum_posts │                    │ │
│  │  │             │      │             │                    │ │
│  │  │ id          │──────│ id          │                    │ │
│  │  │ user_id     │←─────│ topic_id    │                    │ │
│  │  │ lesson_id   │←─────│ user_id     │                    │ │
│  │  │ matiere_id  │←─────│ parent_id   │                    │ │
│  │  │ classe_id   │←─┐   │ content     │                    │ │
│  │  │ title       │  │   │ is_solution │                    │ │
│  │  │ status      │  │   └─────────────┘                    │ │
│  │  └─────────────┘  │                                      │ │
│  └───────────────────┼──────────────────────────────────────┘ │
└───────────────────────┼──────────────────────────────────────────┘
                        ↓
              (Autres entités LMS à venir:
               quizzes, files, notifications...)
```

---

## 📋 Tables Détaillées

### 1. ENTITÉS SYNCHRONISÉES (KLASSCI)

#### Table `users`

| Colonne | Type | Contrainte | Description |
|---------|------|------------|-------------|
| `id` | bigint | PK, AI | ID local |
| `klassci_id` | bigint | UNIQUE, INDEX | ID utilisateur KLASSCI |
| `name` | varchar(191) | NOT NULL | Nom complet |
| `email` | varchar(191) | UNIQUE | Email |
| `password` | varchar(191) | HASHED | Password (hash local) |
| `role` | varchar(191) | INDEX, DEFAULT 'student' | etudiant, enseignant, coordinateur, admin |
| `klassci_token` | text | NULLABLE | Token KLASSCI (caché) |
| `klassci_data` | json | NULLABLE | Données complètes KLASSCI |
| `last_klassci_sync` | timestamp | NULLABLE | Dernière synchronisation |
| `created_at` | timestamp | | |
| `updated_at` | timestamp | | |

**Relations :**
- `hasMany` → `lessons` (en tant qu'enseignant)
- `hasMany` → `lesson_progress`
- `belongsToMany` → `classes` (via `classe_etudiant`)
- `hasMany` → `forum_topics`
- `hasMany` → `forum_posts`

---

#### Table `matieres`

| Colonne | Type | Contrainte | Description |
|---------|------|------------|-------------|
| `id` | bigint | PK, AI | ID local |
| `klassci_id` | bigint | UNIQUE, INDEX | ID matière KLASSCI |
| `code` | varchar(191) | NULLABLE | Code matière (ex: INF101) |
| `libelle` | varchar(191) | NOT NULL | Nom de la matière |
| `description` | text | NULLABLE | Description |
| `coefficient` | integer | DEFAULT 1 | Coefficient |
| `credit` | integer | DEFAULT 1 | Nombre de crédits |
| `filiere_id` | bigint | INDEX, NULLABLE | ID filière KLASSCI |
| `niveau_id` | bigint | INDEX, NULLABLE | ID niveau KLASSCI |
| `semestre_id` | bigint | NULLABLE | ID semestre KLASSCI |
| `klassci_data` | json | NULLABLE | Données complètes KLASSCI |
| `last_klassci_sync` | timestamp | NULLABLE | Dernière synchronisation |
| `created_at` | timestamp | | |
| `updated_at` | timestamp | | |

**Relations :**
- `belongsToMany` → `classes` (via `classe_matiere`)
- `hasMany` → `lessons`
- `hasMany` → `forum_topics`

---

#### Table `classes`

| Colonne | Type | Contrainte | Description |
|---------|------|------------|-------------|
| `id` | bigint | PK, AI | ID local |
| `klassci_id` | bigint | UNIQUE, INDEX | ID classe KLASSCI |
| `code` | varchar(191) | NULLABLE | Code classe (ex: L3-INFO-A) |
| `libelle` | varchar(191) | NOT NULL | Nom de la classe |
| `description` | text | NULLABLE | Description |
| `effectif` | integer | DEFAULT 0 | Effectif maximum |
| `filiere_id` | bigint | INDEX, NULLABLE | ID filière KLASSCI |
| `niveau_id` | bigint | INDEX, NULLABLE | ID niveau KLASSCI |
| `annee_universitaire_id` | bigint | INDEX, NULLABLE | ID année universitaire KLASSCI |
| `klassci_data` | json | NULLABLE | Données complètes KLASSCI |
| `last_klassci_sync` | timestamp | NULLABLE | Dernière synchronisation |
| `created_at` | timestamp | | |
| `updated_at` | timestamp | | |

**Relations :**
- `belongsToMany` → `matieres` (via `classe_matiere`)
- `belongsToMany` → `users` (via `classe_etudiant`)
- `hasMany` → `lessons`
- `hasMany` → `forum_topics`

---

### 2. TABLES PIVOT (Relations N-N)

#### Table `classe_matiere`

**Relation :** Une classe enseigne plusieurs matières, une matière est enseignée dans plusieurs classes

| Colonne | Type | Contrainte | Description |
|---------|------|------------|-------------|
| `id` | bigint | PK, AI | |
| `classe_id` | bigint | FK → classes, ON DELETE CASCADE | |
| `matiere_id` | bigint | FK → matieres, ON DELETE CASCADE | |
| `enseignant_id` | bigint | FK → users, ON DELETE SET NULL, NULLABLE | Enseignant responsable |
| `coefficient` | integer | NULLABLE | Coefficient spécifique à cette classe |
| `heures_cours` | integer | NULLABLE | Heures de cours magistral |
| `heures_td` | integer | NULLABLE | Heures de TD |
| `heures_tp` | integer | NULLABLE | Heures de TP |
| `created_at` | timestamp | | |
| `updated_at` | timestamp | | |

**Contraintes :**
- `UNIQUE(classe_id, matiere_id)` - Une matière apparaît une seule fois par classe

---

#### Table `classe_etudiant`

**Relation :** Un étudiant est inscrit dans plusieurs classes, une classe contient plusieurs étudiants

| Colonne | Type | Contrainte | Description |
|---------|------|------------|-------------|
| `id` | bigint | PK, AI | |
| `classe_id` | bigint | FK → classes, ON DELETE CASCADE | |
| `user_id` | bigint | FK → users, ON DELETE CASCADE | |
| `date_inscription` | date | NULLABLE | Date d'inscription |
| `statut` | enum | DEFAULT 'actif' | actif, inactif, abandonne |
| `annee_universitaire_id` | bigint | NULLABLE | ID année KLASSCI |
| `created_at` | timestamp | | |
| `updated_at` | timestamp | | |

**Contraintes :**
- `UNIQUE(classe_id, user_id, annee_universitaire_id)` - Un étudiant par classe par année

---

### 3. ENTITÉS LMS LOCALES

#### Table `lessons` (Cours/Leçons)

| Colonne | Type | Contrainte | Description |
|---------|------|------------|-------------|
| `id` | bigint | PK, AI | |
| `matiere_id` | bigint | FK → matieres, ON DELETE SET NULL, NULLABLE, INDEX | |
| `classe_id` | bigint | FK → classes, ON DELETE SET NULL, NULLABLE, INDEX | |
| `enseignant_id` | bigint | FK → users, ON DELETE CASCADE, INDEX | |
| `title` | varchar(191) | NOT NULL | Titre du cours |
| `description` | text | NULLABLE | Description courte |
| `content` | longtext | NULLABLE | Contenu HTML/Markdown |
| `type` | enum | DEFAULT 'cours', INDEX | cours, tp, td, projet, autre |
| `status` | enum | DEFAULT 'draft', INDEX | draft, published, archived |
| `order` | integer | DEFAULT 0 | Ordre d'affichage |
| `duration_minutes` | integer | NULLABLE | Durée estimée |
| `published_at` | timestamp | NULLABLE, INDEX | Date de publication |
| `archived_at` | timestamp | NULLABLE | Date d'archivage |
| `attachments` | json | NULLABLE | Liste fichiers attachés |
| `created_at` | timestamp | | |
| `updated_at` | timestamp | | |
| `deleted_at` | timestamp | NULLABLE | Soft delete |

**Relations :**
- `belongsTo` → `matiere`
- `belongsTo` → `classe`
- `belongsTo` → `user` (enseignant)
- `hasMany` → `lesson_progress`

**Index composites :**
- `(status, published_at)` - Pour filtrage cours publiés

---

#### Table `lesson_progress` (Suivi Progression)

| Colonne | Type | Contrainte | Description |
|---------|------|------------|-------------|
| `id` | bigint | PK, AI | |
| `user_id` | bigint | FK → users, ON DELETE CASCADE, INDEX | |
| `lesson_id` | bigint | FK → lessons, ON DELETE CASCADE, INDEX | |
| `status` | enum | DEFAULT 'not_started', INDEX | not_started, in_progress, completed |
| `progress_percentage` | integer | DEFAULT 0 | Pourcentage 0-100 |
| `time_spent_minutes` | integer | DEFAULT 0 | Temps passé |
| `started_at` | timestamp | NULLABLE | Date de début |
| `completed_at` | timestamp | NULLABLE | Date de complétion |
| `last_accessed_at` | timestamp | NULLABLE | Dernier accès |
| `notes` | text | NULLABLE | Notes personnelles |
| `rating` | integer | NULLABLE | Note 1-5 |
| `feedback` | text | NULLABLE | Feedback étudiant |
| `created_at` | timestamp | | |
| `updated_at` | timestamp | | |

**Contraintes :**
- `UNIQUE(user_id, lesson_id)` - Un seul enregistrement par utilisateur/cours

**Relations :**
- `belongsTo` → `user`
- `belongsTo` → `lesson`

**Index composites :**
- `(user_id, status)` - Progression par utilisateur
- `(lesson_id, status)` - Statistiques par cours

---

#### Table `forum_topics` (Topics de Discussion)

| Colonne | Type | Contrainte | Description |
|---------|------|------------|-------------|
| `id` | bigint | PK, AI | |
| `user_id` | bigint | FK → users, ON DELETE CASCADE, INDEX | Auteur |
| `lesson_id` | bigint | FK → lessons, ON DELETE CASCADE, NULLABLE, INDEX | Cours lié |
| `matiere_id` | bigint | NULLABLE, INDEX | ID matière KLASSCI |
| `classe_id` | bigint | NULLABLE, INDEX | ID classe KLASSCI |
| `title` | varchar(191) | NOT NULL | Titre du topic |
| `content` | text | NOT NULL | Contenu |
| `status` | enum | DEFAULT 'open', INDEX | open, closed, pinned |
| `is_resolved` | boolean | DEFAULT false | Question résolue ? |
| `views_count` | integer | DEFAULT 0 | Nombre de vues |
| `posts_count` | integer | DEFAULT 0 | Nombre de réponses |
| `last_activity_at` | timestamp | NULLABLE, INDEX | Dernière activité |
| `created_at` | timestamp | | |
| `updated_at` | timestamp | | |
| `deleted_at` | timestamp | NULLABLE | Soft delete |

**Relations :**
- `belongsTo` → `user` (auteur)
- `belongsTo` → `lesson` (optionnel)
- `hasMany` → `forum_posts`

**Index composites :**
- `(status, last_activity_at)` - Liste topics actifs

---

#### Table `forum_posts` (Réponses/Posts)

| Colonne | Type | Contrainte | Description |
|---------|------|------------|-------------|
| `id` | bigint | PK, AI | |
| `topic_id` | bigint | FK → forum_topics, ON DELETE CASCADE, INDEX | |
| `user_id` | bigint | FK → users, ON DELETE CASCADE, INDEX | |
| `parent_id` | bigint | FK → forum_posts, ON DELETE CASCADE, NULLABLE, INDEX | Post parent (réponse) |
| `content` | text | NOT NULL | Contenu |
| `is_solution` | boolean | DEFAULT false | Marque comme solution |
| `is_edited` | boolean | DEFAULT false | Post modifié |
| `edited_at` | timestamp | NULLABLE | Date modification |
| `likes_count` | integer | DEFAULT 0 | Nombre de likes |
| `created_at` | timestamp | | |
| `updated_at` | timestamp | | |
| `deleted_at` | timestamp | NULLABLE | Soft delete |

**Relations :**
- `belongsTo` → `forum_topic`
- `belongsTo` → `user`
- `belongsTo` → `forum_post` (parent, optionnel)
- `hasMany` → `forum_posts` (réponses)

**Index composites :**
- `(topic_id, created_at)` - Ordre chronologique des posts

---

## 🔗 Cardinalités des Relations

### Relations KLASSCI ↔ LMS

| Entité Source | Relation | Entité Cible | Cardinalité | Type |
|---------------|----------|--------------|-------------|------|
| **User** | créé des | **Lesson** | 1:N | hasMany |
| **User** | progresse dans | **Lesson** | N:N (via lesson_progress) | belongsToMany |
| **User** | inscrit dans | **Classe** | N:N (via classe_etudiant) | belongsToMany |
| **User** | crée | **ForumTopic** | 1:N | hasMany |
| **User** | poste | **ForumPost** | 1:N | hasMany |
| **Matiere** | enseignée dans | **Classe** | N:N (via classe_matiere) | belongsToMany |
| **Matiere** | contient | **Lesson** | 1:N | hasMany |
| **Matiere** | a des discussions | **ForumTopic** | 1:N | hasMany |
| **Classe** | enseigne | **Matiere** | N:N (via classe_matiere) | belongsToMany |
| **Classe** | contient étudiants | **User** | N:N (via classe_etudiant) | belongsToMany |
| **Classe** | a des | **Lesson** | 1:N | hasMany |
| **Classe** | a des discussions | **ForumTopic** | 1:N | hasMany |
| **Lesson** | appartient à | **Matiere** | N:1 | belongsTo |
| **Lesson** | destiné à | **Classe** | N:1 | belongsTo |
| **Lesson** | créé par | **User** (enseignant) | N:1 | belongsTo |
| **Lesson** | suivi par | **LessonProgress** | 1:N | hasMany |
| **LessonProgress** | concerne | **User** | N:1 | belongsTo |
| **LessonProgress** | concerne | **Lesson** | N:1 | belongsTo |
| **ForumTopic** | créé par | **User** | N:1 | belongsTo |
| **ForumTopic** | lié à | **Lesson** | N:1 (optionnel) | belongsTo |
| **ForumTopic** | contient | **ForumPost** | 1:N | hasMany |
| **ForumPost** | appartient à | **ForumTopic** | N:1 | belongsTo |
| **ForumPost** | écrit par | **User** | N:1 | belongsTo |
| **ForumPost** | répond à | **ForumPost** (parent) | N:1 (optionnel) | belongsTo |

---

## 🔐 Contraintes d'Intégrité

### Foreign Keys

Toutes les foreign keys sont configurées avec :
- `ON DELETE CASCADE` - Pour les dépendances strictes (posts → topic, progress → lesson)
- `ON DELETE SET NULL` - Pour les références optionnelles (lesson → matiere)

### Unique Constraints

| Table | Colonnes | Raison |
|-------|----------|--------|
| `users` | `klassci_id` | Un user KLASSCI = un user local |
| `users` | `email` | Email unique |
| `matieres` | `klassci_id` | Une matière KLASSCI = une matière locale |
| `classes` | `klassci_id` | Une classe KLASSCI = une classe locale |
| `classe_matiere` | `(classe_id, matiere_id)` | Une matière/classe |
| `classe_etudiant` | `(classe_id, user_id, annee_universitaire_id)` | Un étudiant/classe/année |
| `lesson_progress` | `(user_id, lesson_id)` | Une progression/user/lesson |

---

## 📈 Stratégie de Synchronisation

### Synchronisation Automatique

**Users :**
- Sync lors du login
- Re-sync si `last_klassci_sync` > 24h (via middleware)

**Matieres / Classes :**
- Sync manuelle via commande Artisan (à créer)
- Ou sync à la demande via API
- Cache recommandé : 1 heure

**Données en temps réel (non synchronisées) :**
- Notes, présences, évaluations → Direct via proxy KLASSCI
- Emploi du temps → Direct via proxy KLASSCI

---

## 🚀 Commandes Utiles

### Migrations
```bash
# Exécuter toutes les migrations
php artisan migrate

# Voir le statut
php artisan migrate:status

# Rollback dernières migrations
php artisan migrate:rollback --step=1

# Fresh (attention : perte de données!)
php artisan migrate:fresh
```

### Vérifier Relations
```sql
-- Voir toutes les foreign keys
SELECT
    TABLE_NAME,
    COLUMN_NAME,
    CONSTRAINT_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'lms_klassci'
  AND REFERENCED_TABLE_NAME IS NOT NULL;
```

---

## ✅ Checklist Intégrité

- ✅ Toutes les tables ont des timestamps (`created_at`, `updated_at`)
- ✅ Tables importantes ont soft deletes (`deleted_at`)
- ✅ Foreign keys bien définies avec CASCADE/SET NULL
- ✅ Index sur les colonnes fréquemment filtrées
- ✅ Index composites pour les requêtes complexes
- ✅ Contraintes UNIQUE pour éviter doublons
- ✅ Relations bidirectionnelles dans les models
- ✅ Synchronisation KLASSCI tracée (`klassci_data`, `last_klassci_sync`)

---

**Date :** 14 Octobre 2025
**Version :** 1.0.0
**Auteur :** Claude Code + Utilisateur
