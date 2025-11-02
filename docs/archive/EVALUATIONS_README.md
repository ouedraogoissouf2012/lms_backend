# Système d'Évaluations en Ligne - Documentation

## Vue d'ensemble

Le système d'évaluations permet aux enseignants de créer des évaluations QCM en ligne et aux étudiants de les passer directement sur la plateforme LMS. Les notes sont calculées automatiquement et peuvent être synchronisées vers KLASSCI.

## Architecture

### Backend (Laravel)

#### Tables de base de données

1. **evaluations** - Table principale
   - Informations générales (titre, description, type, status)
   - Programmation (date, durée, coefficient, barème)
   - Configuration LMS (mélanger questions, afficher résultats, tentatives multiples)
   - Liaison avec KLASSCI (matière, classe, enseignant, évaluation KLASSCI)

2. **evaluation_questions** - Questions
   - Support de plusieurs types: QCM simple, QCM multiple, Vrai/Faux, Réponse courte, Dissertation
   - Options de réponse (JSON)
   - Réponses correctes (JSON)
   - Points attribués par question

3. **evaluation_submissions** - Soumissions des étudiants
   - Réponses de l'étudiant (JSON)
   - Calcul automatique du score
   - Synchronisation vers KLASSCI

#### Endpoints API

**Gestion des évaluations (Enseignants)**
- `GET /api/evaluations` - Liste des évaluations
- `POST /api/evaluations` - Créer une évaluation
- `GET /api/evaluations/{id}` - Détails d'une évaluation
- `PUT /api/evaluations/{id}` - Modifier une évaluation
- `DELETE /api/evaluations/{id}` - Supprimer une évaluation
- `POST /api/evaluations/{id}/publish` - Publier une évaluation
- `POST /api/evaluations/{id}/sync-to-klassci` - Synchroniser les notes vers KLASSCI

**Passage d'évaluations (Étudiants)**
- `GET /api/evaluations/student/{klassciEtudiantId}` - Évaluations disponibles
- `POST /api/evaluations/{id}/start` - Démarrer une évaluation
- `POST /api/evaluations/{id}/submit` - Soumettre les réponses

#### Modèles Eloquent

1. **Evaluation.php**
   - Relations: `questions()`, `submissions()`
   - Méthodes: `isAvailable()`, `isActive()`

2. **EvaluationQuestion.php**
   - Méthode: `isCorrectAnswer($answer)` - Vérifie si une réponse est correcte

3. **EvaluationSubmission.php**
   - Méthode: `calculateScore()` - Calcule automatiquement le score
   - Méthode: `submit()` - Finalise la soumission et calcule la note

### Frontend (Vue.js)

#### Composants

1. **CreateEvaluation.vue** (`/teacher/evaluations/create`)
   - Formulaire complet de création d'évaluation
   - Gestion dynamique des questions
   - Différents types de questions supportés
   - Enregistrement en brouillon ou publication directe

2. **StudentEvaluations.vue** (`/student/evaluations`)
   - Liste des évaluations disponibles pour l'étudiant
   - Statuts: Disponible, En cours, Terminée
   - Affichage des notes obtenues
   - Boutons pour démarrer/continuer/refaire

3. **TakeEvaluation.vue** (`/student/evaluations/{id}/take`)
   - Interface de passage d'évaluation
   - Timer compte à rebours
   - Barre de progression
   - Support de tous les types de questions
   - Soumission automatique à la fin du temps

#### Service API

**evaluation.js** - Service pour communiquer avec le backend
- Toutes les méthodes nécessaires pour gérer les évaluations

## Workflow complet

### Pour l'enseignant

1. **Créer une évaluation**
   - Aller dans le dashboard enseignant
   - Cliquer sur "Créer une Évaluation"
   - Remplir les informations (matière, classe, titre, durée, etc.)
   - Ajouter des questions (QCM, choix multiples, vrai/faux, réponses courtes)
   - Définir les réponses correctes
   - Publier ou enregistrer en brouillon

2. **Publier une évaluation**
   - Les étudiants peuvent voir uniquement les évaluations publiées
   - Status passe de "brouillon" à "planifiee"

3. **Synchroniser les notes vers KLASSCI**
   - Une fois que les étudiants ont passé l'évaluation
   - Cliquer sur "Synchroniser vers KLASSCI"
   - Les notes sont envoyées automatiquement

### Pour l'étudiant

1. **Voir les évaluations disponibles**
   - Aller dans le dashboard étudiant
   - Cliquer sur "Mes Évaluations"
   - Voir la liste des évaluations publiées pour sa classe

2. **Passer une évaluation**
   - Cliquer sur "Commencer l'évaluation"
   - L'évaluation démarre avec un timer
   - Répondre aux questions
   - Soumettre avant la fin du temps

3. **Voir les résultats**
   - Si l'enseignant a activé l'affichage immédiat: résultats affichés après soumission
   - Sinon: les résultats apparaissent une fois que l'enseignant les a publiés

## Types de questions supportés

### 1. QCM Simple
- Une seule réponse correcte
- Radio buttons
- Format: `{ "type": "qcm", "options": ["A", "B", "C"], "correct_answers": ["B"] }`

### 2. QCM Multiple
- Plusieurs réponses correctes possibles
- Checkboxes
- Format: `{ "type": "qcm_multiple", "options": ["A", "B", "C"], "correct_answers": ["B", "C"] }`

### 3. Vrai/Faux
- Deux options: Vrai ou Faux
- Radio buttons
- Format: `{ "type": "vrai_faux", "options": ["Vrai", "Faux"], "correct_answers": ["Vrai"] }`

### 4. Réponse Courte
- Texte libre court
- Plusieurs réponses acceptées possibles
- Comparaison insensible à la casse
- Format: `{ "type": "reponse_courte", "correct_answers": ["Paris", "paris"] }`

### 5. Dissertation
- Texte libre long
- Correction manuelle par l'enseignant
- Format: `{ "type": "dissertation", "correct_answers": null }`

## Calcul automatique des notes

### Pour les questions à correction automatique (QCM, Vrai/Faux, Réponses courtes)

1. Chaque question a un nombre de points attribués
2. Score total = Somme des points des bonnes réponses
3. Note sur 20 = (Score obtenu / Score total) × Barème

Exemple:
- Évaluation de 10 questions à 1 point chacune
- Barème: 20/20
- Étudiant obtient 8 bonnes réponses
- Score: 8 points
- Note: (8 / 10) × 20 = 16/20

### Pour les dissertations

- Correction manuelle requise
- L'enseignant attribue les points dans l'interface

## Configuration des évaluations

### Options disponibles

- **shuffle_questions**: Mélanger l'ordre des questions
- **show_results**: Afficher les résultats immédiatement après soumission
- **allow_retake**: Autoriser plusieurs tentatives
- **max_attempts**: Nombre maximum de tentatives (si allow_retake = true)

### Statuts

- **brouillon**: Évaluation en cours de création, non visible par les étudiants
- **planifiee**: Évaluation publiée, disponible pour les étudiants
- **en_cours**: Évaluation en cours (peut être démarrée par les étudiants)
- **terminee**: Évaluation terminée
- **annulee**: Évaluation annulée

## Synchronisation avec KLASSCI

### Pré-requis

1. L'évaluation doit avoir un `klassci_evaluation_id` (lien avec une évaluation KLASSCI)
2. Les étudiants doivent avoir soumis leurs réponses

### Processus

1. Les notes sont calculées automatiquement par le LMS
2. L'enseignant clique sur "Synchroniser vers KLASSCI"
3. Le système envoie les notes via l'API KLASSCI: `POST /evaluations/{id}/notes`
4. Format envoyé:
```json
{
  "notes": [
    {
      "etudiant_id": 123,
      "note": 16.50,
      "commentaire": "Bon travail",
      "is_absent": false
    }
  ]
}
```

## Accès aux fonctionnalités

### Dashboard Enseignant
- **URL**: `/teacher/dashboard`
- **Bouton**: "Créer une Évaluation" → `/teacher/evaluations/create`

### Dashboard Étudiant
- **URL**: `/student/dashboard`
- **Bouton**: "Mes Évaluations" → `/student/evaluations`

## Sécurité

### Authentification
- Toutes les routes sont protégées par le middleware `auth:sanctum`
- Vérification du rôle utilisateur (enseignant/étudiant)

### Validation
- Validation côté backend de toutes les données
- Vérification du nombre de tentatives autorisées
- Contrôle du temps imparti

### Protection des données
- Les réponses correctes ne sont pas exposées aux étudiants avant soumission
- Les scores sont calculés côté serveur (pas de manipulation côté client)

## Extensions futures possibles

1. **Import de questions depuis un fichier** (CSV, Excel)
2. **Banque de questions** réutilisables
3. **Analyse statistiques** par question (taux de réussite, etc.)
4. **Support d'images** dans les questions
5. **Questions à appariement** (matching)
6. **Pondération différente** par question
7. **Feedback personnalisé** par réponse
8. **Historique des tentatives** détaillé
9. **Export des résultats** (PDF, Excel)
10. **Correction collaborative** pour les dissertations

## Support

Pour toute question ou problème, consultez:
- Documentation KLASSCI API
- Logs Laravel: `storage/logs/laravel.log`
- Console navigateur pour les erreurs frontend
