# Correction des Problèmes d'Évaluations

## 🔍 Problèmes Identifiés

### Problème 1: Les étudiants ne peuvent pas voir les évaluations créées par les enseignants
**Symptôme**: Après qu'un enseignant crée une évaluation, elle n'apparaît pas dans la liste des évaluations de l'étudiant.

**Cause**: La méthode `studentEvaluations()` dans `EvaluationController.php` (ligne 282-370) utilisait le **token Sanctum** au lieu du **token KLASSCI** pour appeler l'API KLASSCI et récupérer la classe de l'étudiant.

```php
// AVANT (incorrect)
$authHeader = $request->header('Authorization');
$userToken = substr($authHeader, 7); // ❌ Token Sanctum
```

### Problème 2: L'édition d'une évaluation réinitialise toutes les données
**Symptôme**: Quand on clique sur "Modifier les questions", le formulaire s'affiche vide au lieu de charger les questions existantes.

**Causes**:
1. `CreateQuestions.vue` n'avait pas de logique pour détecter le mode édition
2. Il ne chargeait pas l'évaluation existante depuis la base LMS
3. Le backend n'avait pas de logique pour mettre à jour les questions

---

## ✅ Solutions Appliquées

### 1. Backend - EvaluationController.php

#### Fix 1: Méthode `studentEvaluations()` (Lignes 282-370)

**Fichier**: `app/Http/Controllers/API/EvaluationController.php`

**Avant**:
```php
public function studentEvaluations(int $klassciEtudiantId, Request $request): JsonResponse
{
    try {
        // ❌ Récupère le token Sanctum au lieu du token KLASSCI
        $authHeader = $request->header('Authorization');
        $userToken = substr($authHeader, 7);

        $dashboard = $this->klassciService->requestWithUserToken(
            $userToken, // ❌ Envoie Sanctum à KLASSCI → 401
            'me/dashboard',
            'GET'
        );
```

**Après**:
```php
public function studentEvaluations(int $klassciEtudiantId, Request $request): JsonResponse
{
    try {
        // ✅ Récupérer l'utilisateur authentifié (via Sanctum)
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié'
            ], 401);
        }

        // ✅ Récupérer le token KLASSCI depuis la base de données
        $klassciToken = $user->klassci_token;

        if (!$klassciToken) {
            return response()->json([
                'success' => false,
                'message' => 'Token KLASSCI non trouvé. Veuillez vous reconnecter.'
            ], 401);
        }

        \Log::info('Student Evaluations request', [
            'user_id' => $user->id,
            'klassci_id' => $user->klassci_id,
            'klassci_etudiant_id' => $klassciEtudiantId,
            'has_klassci_token' => !empty($klassciToken),
        ]);

        // ✅ Utiliser le token KLASSCI pour récupérer le dashboard
        $dashboard = $this->klassciService->requestWithUserToken(
            $klassciToken, // ✅ Token KLASSCI correct
            'me/dashboard',
            'GET'
        );

        $classeId = $dashboard['data']['classe']['id'] ?? null;

        if (!$classeId) {
            return response()->json([
                'success' => false,
                'message' => 'Classe non trouvée'
            ], 404);
        }

        \Log::info('Student classe found', ['classe_id' => $classeId]);

        // Récupérer les évaluations publiées pour cette classe
        $evaluations = Evaluation::with('questions', 'submissions')
            ->where('klassci_classe_id', $classeId)
            ->where('is_published', true)
            ->whereIn('status', ['planifiee', 'en_cours'])
            ->orderBy('date_evaluation', 'desc')
            ->get();

        \Log::info('Evaluations found for student', ['count' => $evaluations->count()]);

        // Ajouter les informations de soumission pour cet étudiant
        $evaluations->each(function ($evaluation) use ($klassciEtudiantId) {
            $submission = $evaluation->submissions()
                ->where('klassci_etudiant_id', $klassciEtudiantId)
                ->latest()
                ->first();

            $evaluation->student_submission = $submission;
        });

        // Enrichir avec les données KLASSCI (classe, matière)
        $enrichedEvaluations = $this->enrichEvaluationsWithKlassciData($evaluations);

        return response()->json([
            'success' => true,
            'data' => $enrichedEvaluations
        ]);

    } catch (\Exception $e) {
        \Log::error('Erreur récupération évaluations étudiant', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la récupération des évaluations'
        ], 500);
    }
}
```

**Changements clés**:
- ✅ Utilise `$request->user()` pour obtenir l'utilisateur authentifié via Sanctum
- ✅ Récupère `klassci_token` depuis `$user->klassci_token`
- ✅ Utilise le token KLASSCI pour appeler l'API KLASSCI
- ✅ Ajoute des logs pour faciliter le débogage

#### Fix 2: Méthode `update()` - Support des questions (Lignes 178-265)

**Avant**:
```php
public function update(Request $request, int $id): JsonResponse
{
    // Validation basique uniquement
    $validator = Validator::make($request->all(), [
        'titre' => 'sometimes|string|max:255',
        'description' => 'nullable|string',
        'status' => 'sometimes|in:brouillon,planifiee,en_cours,terminee,annulee',
        'date_evaluation' => 'nullable|date',
        'duree_minutes' => 'sometimes|integer|min:1',
        'is_published' => 'sometimes|boolean',
    ]);

    try {
        $evaluation->update($request->all()); // ❌ Ne gère pas les questions

        return response()->json([
            'success' => true,
            'message' => 'Évaluation mise à jour',
            'data' => $evaluation
        ]);
    }
}
```

**Après**:
```php
public function update(Request $request, int $id): JsonResponse
{
    // ✅ Validation incluant les questions
    $validator = Validator::make($request->all(), [
        'titre' => 'sometimes|string|max:255',
        'description' => 'nullable|string',
        'status' => 'sometimes|in:brouillon,planifiee,en_cours,terminee,annulee',
        'date_evaluation' => 'nullable|date',
        'duree_minutes' => 'sometimes|integer|min:1',
        'is_published' => 'sometimes|boolean',
        'max_attempts' => 'sometimes|integer|min:1',
        'shuffle_questions' => 'sometimes|boolean',
        'show_results' => 'sometimes|boolean',
        'questions' => 'sometimes|array',
        'questions.*.question' => 'required|string',
        'questions.*.type' => 'required|in:qcm,qcm_multiple,vrai_faux,reponse_courte,dissertation',
        'questions.*.points' => 'nullable|numeric|min:0',
        'questions.*.options' => 'nullable|array',
        'questions.*.correct_answers' => 'nullable|array',
    ]);

    try {
        DB::beginTransaction();

        // ✅ Mettre à jour les champs de base
        $evaluation->update($request->except(['questions']));

        // ✅ Si des questions sont fournies, les remplacer
        if ($request->has('questions')) {
            // Supprimer les anciennes questions
            $evaluation->questions()->delete();

            // Créer les nouvelles questions
            foreach ($request->questions as $index => $questionData) {
                EvaluationQuestion::create([
                    'evaluation_id' => $evaluation->id,
                    'question' => $questionData['question'],
                    'type' => $questionData['type'],
                    'ordre' => $index + 1,
                    'points' => $questionData['points'] ?? 1,
                    'options' => $questionData['options'] ?? null,
                    'correct_answers' => $questionData['correct_answers'] ?? null,
                    'explanation' => $questionData['explanation'] ?? null,
                ]);
            }
        }

        DB::commit();

        $evaluation->load('questions');

        return response()->json([
            'success' => true,
            'message' => 'Évaluation mise à jour',
            'data' => $evaluation
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        \Log::error('Erreur mise à jour évaluation', ['error' => $e->getMessage()]);

        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la mise à jour',
            'error' => $e->getMessage()
        ], 500);
    }
}
```

**Changements clés**:
- ✅ Validation des questions dans la requête
- ✅ Mise à jour transactionnelle (DB::beginTransaction/commit)
- ✅ Suppression des anciennes questions
- ✅ Création des nouvelles questions
- ✅ Rollback en cas d'erreur

---

### 2. Frontend - CreateQuestions.vue

#### Ajout du mode édition

**Fichier**: `lms-frontend/src/views/evaluations/CreateQuestions.vue`

**Nouvelles propriétés data()**:
```javascript
data() {
  return {
    evaluationKlassci: null,
    evaluationLMS: null, // ✅ NOUVEAU: Évaluation LMS existante (pour édition)
    configuration: {
      duree_minutes: 60,
      max_attempts: 1,
      shuffle_questions: false,
      show_results: false
    },
    questions: [],
    loading: false,
    isEditMode: false // ✅ NOUVEAU: Détecte le mode édition
  }
}
```

**Détection du mode édition dans mounted()**:
```javascript
async mounted() {
  const klassciId = this.$route.query.klassci_id
  if (!klassciId) {
    alert('ID évaluation KLASSCI manquant')
    this.$router.back()
    return
  }

  // ✅ NOUVEAU: Vérifier si mode édition (route avec paramètre :id)
  const lmsEvaluationId = this.$route.params.id
  this.isEditMode = !!lmsEvaluationId

  await this.loadEvaluationKlassci(klassciId)

  // ✅ NOUVEAU: Si mode édition, charger l'évaluation LMS existante
  if (this.isEditMode) {
    await this.loadExistingEvaluation(lmsEvaluationId)
  }
}
```

**Nouvelle méthode: `loadExistingEvaluation()`**:
```javascript
async loadExistingEvaluation(lmsEvaluationId) {
  try {
    console.log('📖 Chargement évaluation LMS existante:', lmsEvaluationId)
    const result = await evaluationService.getEvaluation(lmsEvaluationId)

    if (result.success && result.data) {
      this.evaluationLMS = result.data
      console.log('✅ Évaluation LMS chargée:', this.evaluationLMS)

      // ✅ Charger la configuration
      this.configuration = {
        duree_minutes: this.evaluationLMS.duree_minutes || 60,
        max_attempts: this.evaluationLMS.max_attempts || 1,
        shuffle_questions: this.evaluationLMS.shuffle_questions || false,
        show_results: this.evaluationLMS.show_results || false
      }

      // ✅ Charger les questions existantes
      if (this.evaluationLMS.questions && this.evaluationLMS.questions.length > 0) {
        this.questions = this.evaluationLMS.questions.map(q => ({
          question: q.question,
          type: q.type,
          points: q.points || 1,
          options: q.options || [],
          correct_answers: q.correct_answers || [],
          correct_answers_text: q.type === 'reponse_courte' && q.correct_answers
            ? q.correct_answers.join(', ')
            : ''
        }))
        console.log('✅ Questions chargées:', this.questions.length)
      }
    } else {
      console.error('❌ Erreur chargement évaluation LMS')
      alert('Impossible de charger l\'évaluation existante')
    }
  } catch (error) {
    console.error('❌ Erreur loadExistingEvaluation:', error)
    alert('Erreur lors du chargement de l\'évaluation')
  }
}
```

**Méthode `saveQuestions()` refactorisée**:
```javascript
async saveQuestions() {
  if (!this.isValid) {
    alert('Veuillez ajouter au moins une question')
    return
  }

  this.loading = true
  try {
    if (this.isEditMode && this.evaluationLMS) {
      // ✅ Mode édition : mettre à jour l'évaluation existante
      await this.updateEvaluation()
    } else {
      // ✅ Mode création : créer une nouvelle évaluation
      await this.createEvaluation()
    }
  } catch (error) {
    console.error('Erreur saveQuestions:', error)
    alert('Erreur lors de l\'enregistrement des questions')
  } finally {
    this.loading = false
  }
}
```

**Nouvelle méthode: `createEvaluation()`**:
```javascript
async createEvaluation() {
  const data = {
    klassci_evaluation_id: this.evaluationKlassci.id,
    klassci_matiere_id: this.evaluationKlassci.matiere?.id || this.evaluationKlassci.matiere_id,
    klassci_classe_id: this.evaluationKlassci.classe?.id || this.evaluationKlassci.classe_id,
    titre: this.evaluationKlassci.titre,
    description: this.evaluationKlassci.description || '',
    type: 'qcm',
    date_evaluation: this.evaluationKlassci.date_evaluation || this.evaluationKlassci.programmation?.date_evaluation,
    duree_minutes: this.configuration.duree_minutes,
    coefficient: this.evaluationKlassci.coefficient || this.evaluationKlassci.programmation?.coefficient || 1,
    bareme: this.evaluationKlassci.bareme || this.evaluationKlassci.programmation?.bareme || 20,
    shuffle_questions: this.configuration.shuffle_questions,
    show_results: this.configuration.show_results,
    max_attempts: this.configuration.max_attempts,
    status: 'planifiee',
    questions: this.prepareQuestionsForSubmit()
  }

  console.log('📤 Création nouvelle évaluation:', data)

  const result = await evaluationService.createEvaluation(data)

  if (result.success) {
    await evaluationService.publishEvaluation(result.data.id)
    alert('Questions enregistrées et évaluation activée avec succès !')
    this.$router.push('/teacher/evaluations')
  }
}
```

**Nouvelle méthode: `updateEvaluation()`**:
```javascript
async updateEvaluation() {
  const data = {
    duree_minutes: this.configuration.duree_minutes,
    max_attempts: this.configuration.max_attempts,
    shuffle_questions: this.configuration.shuffle_questions,
    show_results: this.configuration.show_results,
    questions: this.prepareQuestionsForSubmit()
  }

  console.log('📤 Mise à jour évaluation:', this.evaluationLMS.id, data)

  const result = await evaluationService.updateEvaluation(this.evaluationLMS.id, data)

  if (result.success) {
    alert('Évaluation mise à jour avec succès !')
    this.$router.push('/teacher/evaluations')
  }
}
```

**Interface utilisateur mise à jour**:
```vue
<h1 class="text-3xl font-bold text-gray-900">
  {{ isEditMode ? 'Modifier les questions QCM' : 'Créer les questions QCM' }}
</h1>
<p class="text-gray-600 mt-2">
  {{ isEditMode ? 'Modifiez les questions de l\'évaluation en ligne' : 'Ajoutez les questions pour l\'évaluation en ligne' }}
</p>

<!-- ... -->

<button>
  {{ loading ? 'Enregistrement...' : (isEditMode ? 'Enregistrer les modifications' : 'Enregistrer et activer') }}
</button>
```

---

## 🧪 Tests de Validation

### Test 1: Vérifier que les étudiants voient les évaluations

#### Étape 1: Créer une évaluation en tant qu'enseignant

1. Se connecter en tant qu'**enseignant** (ex: `bede@gmail.com`)
2. Aller sur `/teacher/evaluations`
3. Cliquer sur **"Créer version en ligne"** pour une évaluation KLASSCI
4. Remplir le formulaire:
   - Durée: 30 minutes
   - Ajouter 2-3 questions QCM
5. Cliquer sur **"Enregistrer et activer"**

✅ **Résultat attendu**: Message "Questions enregistrées et évaluation activée avec succès !"

#### Étape 2: Vérifier côté étudiant

1. Se connecter en tant qu'**étudiant** de la même classe
2. Aller sur `/student/evaluations`

✅ **Résultat attendu**:
- L'évaluation créée par l'enseignant apparaît dans la liste
- Les informations s'affichent correctement:
  - Titre de l'évaluation
  - Matière
  - Classe
  - Date
  - Durée
  - Nombre de questions
- Le bouton **"Commencer l'évaluation"** est visible

#### Étape 3: Passer l'évaluation

1. Cliquer sur **"Commencer l'évaluation"**
2. Répondre aux questions
3. Cliquer sur **"Soumettre"**

✅ **Résultat attendu**:
- L'évaluation se déroule normalement
- La note s'affiche à la fin
- L'évaluation passe au statut "Terminée"

---

### Test 2: Vérifier l'édition d'une évaluation

#### Étape 1: Créer une évaluation

1. Se connecter en tant qu'**enseignant**
2. Aller sur `/teacher/evaluations`
3. Créer une évaluation avec 3 questions:
   - Question 1: "Quelle est la capitale de la France ?" (QCM)
   - Question 2: "2 + 2 = ?" (Réponse courte)
   - Question 3: "La Terre est plate" (Vrai/Faux)
4. Enregistrer

#### Étape 2: Éditer l'évaluation

1. Retourner sur `/teacher/evaluations`
2. Cliquer sur **"Modifier les questions"** pour l'évaluation créée

✅ **Résultat attendu**:
- La page s'ouvre avec le titre **"Modifier les questions QCM"**
- La configuration s'affiche correctement (durée, tentatives, etc.)
- Les 3 questions créées s'affichent avec leurs options et bonnes réponses
- Les champs sont pré-remplis, pas vides!

#### Étape 3: Modifier les questions

1. Modifier la Question 1: Changer "France" en "Allemagne"
2. Ajouter une Question 4: "Combien font 5 × 5 ?" (QCM)
3. Supprimer la Question 2
4. Cliquer sur **"Enregistrer les modifications"**

✅ **Résultat attendu**:
- Message "Évaluation mise à jour avec succès !"
- Retour à `/teacher/evaluations`

#### Étape 4: Vérifier les modifications

1. Cliquer à nouveau sur **"Modifier les questions"**

✅ **Résultat attendu**:
- La Question 1 affiche "Allemagne" (pas "France")
- La Question 2 n'existe plus
- La Question 3 est inchangée
- La Question 4 est présente avec "5 × 5"

---

### Test 3: Vérifier les logs backend

```bash
# Suivre les logs Laravel
tail -f storage/logs/laravel.log
```

#### Logs attendus pour les étudiants:

```
Student Evaluations request
  user_id: 10
  klassci_id: 999001
  klassci_etudiant_id: 999001
  has_klassci_token: true

Student classe found
  classe_id: 36

Evaluations found for student
  count: 1
```

#### Logs attendus pour l'édition:

```
📖 Chargement évaluation LMS existante: 5
✅ Évaluation LMS chargée: {...}
✅ Questions chargées: 3

📤 Mise à jour évaluation: 5
  duree_minutes: 30
  questions: [...]
```

---

## 🔄 Flux Complet Enseignant → Étudiant

### 1. Création d'évaluation (Enseignant)

```
1. Enseignant se connecte
   → AuthController authentifie via KLASSCI
   → Stocke klassci_token dans users.klassci_token
   → Crée token Sanctum
   → Retourne token Sanctum au frontend

2. Enseignant navigue vers /teacher/evaluations
   → Frontend envoie token Sanctum
   → Middleware auth:sanctum valide
   → ProxyController récupère klassci_token depuis BDD
   → ProxyController appelle KLASSCI avec klassci_token ✅
   → Liste des évaluations KLASSCI s'affiche

3. Enseignant clique "Créer version en ligne"
   → Redirigé vers /teacher/evaluations/create-questions
   → CreateQuestions.vue se charge (isEditMode = false)
   → Charge métadonnées depuis KLASSCI
   → Affiche formulaire vide

4. Enseignant remplit et enregistre
   → POST /api/evaluations
   → EvaluationController.store() crée évaluation + questions
   → POST /api/evaluations/{id}/publish
   → Évaluation devient is_published = true, status = 'planifiee'
```

### 2. Consultation (Étudiant)

```
1. Étudiant se connecte
   → Même flux d'authentification
   → klassci_token stocké en BDD

2. Étudiant navigue vers /student/evaluations
   → Frontend envoie token Sanctum
   → GET /api/evaluations/student/{klassciEtudiantId}
   → Middleware auth:sanctum valide
   → EvaluationController.studentEvaluations():
     a. Récupère user via $request->user()
     b. Récupère klassci_token depuis $user->klassci_token ✅
     c. Appelle KLASSCI avec klassci_token
     d. Récupère classe_id de l'étudiant
     e. Filtre évaluations:
        - klassci_classe_id = classe_id
        - is_published = true
        - status IN ('planifiee', 'en_cours')
   → Évaluations affichées ✅

3. Étudiant passe l'évaluation
   → POST /api/evaluations/{id}/start
   → EvaluationSubmission créée
   → POST /api/evaluations/{id}/submit
   → Note calculée automatiquement
```

### 3. Édition (Enseignant)

```
1. Enseignant clique "Modifier les questions"
   → Redirigé vers /teacher/evaluations/{lmsId}/edit-questions
   → CreateQuestions.vue se charge avec params.id = lmsId
   → isEditMode = true ✅

2. CreateQuestions.vue.mounted():
   → loadEvaluationKlassci() charge métadonnées KLASSCI
   → loadExistingEvaluation(lmsId) ✅:
     a. GET /api/evaluations/{lmsId}
     b. Charge configuration (duree_minutes, max_attempts, etc.)
     c. Charge questions avec options et bonnes réponses
     d. Affiche le formulaire pré-rempli ✅

3. Enseignant modifie et enregistre
   → updateEvaluation() appelé
   → PUT /api/evaluations/{lmsId}
   → EvaluationController.update():
     a. Met à jour champs de base
     b. Supprime anciennes questions ✅
     c. Crée nouvelles questions ✅
   → Redirection vers /teacher/evaluations
```

---

## 📊 Comparaison Avant/Après

### Problème 1: Étudiants ne voient pas les évaluations

| Aspect | Avant | Après |
|--------|-------|-------|
| Token utilisé | ❌ Sanctum | ✅ KLASSCI (depuis BDD) |
| Appel KLASSCI | ❌ 401 Unauthorized | ✅ 200 OK |
| Récupération classe | ❌ Échoue | ✅ Réussit |
| Filtrage évaluations | ❌ Aucune trouvée | ✅ Évaluations affichées |
| Logs | ❌ Erreur KLASSCI | ✅ Logs détaillés |

### Problème 2: Édition réinitialise les données

| Aspect | Avant | Après |
|--------|-------|-------|
| Détection mode | ❌ Aucune | ✅ isEditMode |
| Chargement éval | ❌ Uniquement KLASSCI | ✅ KLASSCI + LMS |
| Configuration | ❌ Valeurs par défaut | ✅ Valeurs existantes |
| Questions | ❌ Tableau vide | ✅ Questions chargées |
| Titre page | "Créer..." | ✅ "Modifier..." |
| Backend update | ❌ Sans questions | ✅ Avec questions |

---

## 🚨 Dépannage

### Si l'étudiant ne voit toujours pas les évaluations

**1. Vérifier que l'évaluation est publiée**:
```sql
SELECT id, titre, klassci_classe_id, is_published, status
FROM evaluations
WHERE id = <evaluation_id>;
```

**Attendu**:
- `is_published` = 1
- `status` = 'planifiee' ou 'en_cours'
- `klassci_classe_id` = ID de la classe de l'étudiant

**2. Vérifier le token KLASSCI de l'étudiant**:
```sql
SELECT id, name, email, klassci_id, klassci_token
FROM users
WHERE email = 'etudiant@exemple.com';
```

**Attendu**:
- `klassci_token` non NULL
- `klassci_id` correspond à l'ID KLASSCI

**3. Vérifier les logs**:
```bash
tail -f storage/logs/laravel.log | grep "Student Evaluations"
```

**Attendu**:
```
Student Evaluations request
  user_id: 10
  has_klassci_token: true
Student classe found
  classe_id: 36
Evaluations found for student
  count: 1
```

**Si `has_klassci_token: false`**:
- L'utilisateur doit se **reconnecter** pour obtenir un nouveau token

### Si l'édition réinitialise encore les données

**1. Vérifier la console du navigateur**:
```javascript
// La console doit afficher:
📖 Chargement évaluation LMS existante: 5
✅ Évaluation LMS chargée: {...}
✅ Questions chargées: 3
```

**Si "Erreur récupération évaluation"**:
- Vérifier que l'évaluation existe en BDD:
  ```sql
  SELECT * FROM evaluations WHERE id = <lms_id>;
  ```

**2. Vérifier les questions en BDD**:
```sql
SELECT * FROM evaluation_questions
WHERE evaluation_id = <lms_id>
ORDER BY ordre;
```

**Attendu**: Les questions doivent exister avec leurs options et correct_answers

**3. Vérifier la route**:
```javascript
// Dans la console navigateur
console.log(this.$route.params.id) // Doit afficher l'ID LMS
console.log(this.isEditMode) // Doit afficher true
```

---

## ✅ Checklist de Validation Finale

### Côté Backend

- [x] `EvaluationController::studentEvaluations()` utilise `$user->klassci_token`
- [x] `EvaluationController::update()` accepte et traite les questions
- [x] Logs ajoutés pour le débogage
- [x] Transactions DB pour la mise à jour
- [x] Validation des questions dans update()

### Côté Frontend

- [x] `CreateQuestions.vue` a la propriété `isEditMode`
- [x] `CreateQuestions.vue` a la méthode `loadExistingEvaluation()`
- [x] `CreateQuestions.vue` a les méthodes `createEvaluation()` et `updateEvaluation()`
- [x] Interface utilisateur affiche "Créer" ou "Modifier" selon le mode
- [x] Les questions sont chargées depuis l'API en mode édition

### Tests Fonctionnels

- [ ] Un enseignant peut créer une évaluation
- [ ] Un étudiant de la même classe voit l'évaluation
- [ ] L'étudiant peut passer l'évaluation
- [ ] L'enseignant peut modifier l'évaluation
- [ ] Les modifications sont sauvegardées correctement
- [ ] Les questions pré-existantes s'affichent lors de l'édition

---

## 📚 Récapitulatif Technique

### Architecture Dual-Token

**Token Sanctum**:
- Stockage: `personal_access_tokens` table
- Utilisation: Routes LMS (`/api/evaluations`, `/api/lessons`, etc.)
- Validation: Middleware `auth:sanctum`
- Envoyé par: Frontend dans header `Authorization: Bearer <token>`

**Token KLASSCI**:
- Stockage: `users.klassci_token` column
- Utilisation: Appels proxy vers API KLASSCI externe
- Validation: Par l'API KLASSCI elle-même
- Usage: Backend uniquement (jamais exposé au frontend)

### Flux de Données

```
Frontend (Vue)
  │
  ├─ Token Sanctum dans header
  │
  ▼
Backend (Laravel)
  │
  ├─ Middleware auth:sanctum → Charge $user
  │
  ├─ Controller récupère $user->klassci_token
  │
  ▼
API KLASSCI externe
  │
  └─ Retourne données (classes, évaluations, etc.)
```

---

**Date des corrections**: 2025-10-19
**Version**: 1.0
**Fichiers modifiés**:
- `app/Http/Controllers/API/EvaluationController.php`
- `lms-frontend/src/views/evaluations/CreateQuestions.vue`

🤖 Généré avec [Claude Code](https://claude.com/claude-code)
