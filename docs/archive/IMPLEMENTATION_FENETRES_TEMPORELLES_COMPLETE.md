# Implémentation Complète des Fenêtres Temporelles d'Évaluations

## ✅ Statut: TERMINÉ

Toutes les fonctionnalités de fenêtres temporelles pour les évaluations ont été implémentées avec succès.

---

## 📋 Résumé des Modifications

### Backend (Laravel)

#### 1. **EvaluationController - `studentEvaluations()`** (Lignes 370-442)

**Objectif**: Enrichir les évaluations avec les fenêtres temporelles KLASSCI

**Modifications**:
- Appel à l'API KLASSCI pour récupérer les évaluations avec `programmation.window`
- Merge des données KLASSCI avec les évaluations LMS locales
- Exposition de `programmation.window` avec:
  - `start_at`: Date/heure de début
  - `end_at`: Date/heure de fin
  - `has_started`: Boolean
  - `has_ended`: Boolean
  - `is_open`: Boolean (fenêtre ouverte)
  - `time_left_minutes`: Minutes restantes

**Code ajouté**:
```php
// Récupérer les évaluations KLASSCI avec fenêtres temporelles
try {
    $klassciEvaluationsResponse = $this->klassciService->requestWithUserToken(
        $klassciToken,
        'evaluations',
        'GET'
    );

    $klassciEvaluations = collect($klassciEvaluationsResponse['data'] ?? []);
} catch (\Exception $e) {
    \Log::warning('Could not fetch KLASSCI evaluations for windows', [
        'error' => $e->getMessage()
    ]);
    $klassciEvaluations = collect([]);
}

// Enrichir les évaluations LMS avec fenêtres KLASSCI
$enrichedEvaluations = $evaluationsLMS->map(function ($evalLMS) use ($klassciEvaluations, $klassciEtudiantId) {
    $evalArray = $evalLMS->toArray();

    $klassciEval = $klassciEvaluations->firstWhere('id', $evalLMS->klassci_evaluation_id);

    if ($klassciEval) {
        // Ajouter programmation avec window
        $evalArray['programmation'] = $klassciEval['programmation'] ?? null;
        // ...
    }

    return $evalArray;
})->values()->toArray();
```

#### 2. **EvaluationController - `startEvaluation()`** (Lignes 461-580)

**Objectif**: Vérifier que la fenêtre est ouverte avant de démarrer l'évaluation

**Modifications**:
- Récupération de la fenêtre KLASSCI avant de créer la soumission
- Vérification de `window.is_open`
- Messages d'erreur contextuels selon l'état:
  - "L'évaluation ouvrira le..." si `!has_started`
  - "L'évaluation est fermée depuis le..." si `has_ended`
- Fallback gracieux en cas d'erreur KLASSCI (permet le démarrage + log)

**Code ajouté**:
```php
// Récupérer l'évaluation KLASSCI avec sa fenêtre
$klassciEvalResponse = $this->klassciService->requestWithUserToken(
    $klassciToken,
    'evaluations',
    'GET'
);

$klassciEval = collect($klassciEvalResponse['data'] ?? [])
    ->firstWhere('id', $evaluation->klassci_evaluation_id);

$window = $klassciEval['programmation']['window'] ?? null;

// Vérifier que la fenêtre est ouverte
if ($window && !$window['is_open']) {
    $message = 'L\'évaluation n\'est pas encore ouverte';

    if (!$window['has_started']) {
        $startAt = \Carbon\Carbon::parse($window['start_at'])->format('d/m/Y à H:i');
        $message = "L'évaluation ouvrira le {$startAt}";
    } elseif ($window['has_ended']) {
        $endAt = \Carbon\Carbon::parse($window['end_at'])->format('d/m/Y à H:i');
        $message = "L'évaluation est fermée depuis le {$endAt}";
    }

    \Log::warning('Tentative de démarrage hors fenêtre', [...]);

    return response()->json([
        'success' => false,
        'message' => $message,
        'window' => $window
    ], 403);
}
```

#### 3. **EvaluationController - `getTimeStatus()`** (Lignes 640-697)

**Objectif**: Endpoint pour rafraîchir l'état temporel en temps réel

**Nouvelle méthode**:
```php
/**
 * GET /api/evaluations/{id}/time-status
 * Récupère l'état temporel en temps réel d'une évaluation
 */
public function getTimeStatus(int $id, Request $request): JsonResponse
{
    $evaluation = Evaluation::find($id);

    if (!$evaluation) {
        return response()->json([
            'success' => false,
            'message' => 'Évaluation non trouvée'
        ], 404);
    }

    try {
        $user = $request->user();
        $klassciToken = $user->klassci_token;

        // Récupérer l'état KLASSCI à jour
        $klassciEvalResponse = $this->klassciService->requestWithUserToken(
            $klassciToken,
            'evaluations',
            'GET'
        );

        $klassciEval = collect($klassciEvalResponse['data'] ?? [])
            ->firstWhere('id', $evaluation->klassci_evaluation_id);

        $window = $klassciEval['programmation']['window'] ?? null;

        return response()->json([
            'success' => true,
            'data' => [
                'window' => $window,
                'server_time' => now()->toIso8601String()
            ]
        ]);

    } catch (\Exception $e) {
        \Log::error('Erreur récupération état temporel', [
            'evaluation_id' => $id,
            'error' => $e->getMessage()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Impossible de récupérer l\'état temporel'
        ], 500);
    }
}
```

#### 4. **Route ajoutée** (`routes/api.php` - Ligne 393)

```php
// État temporel en temps réel
Route::get('evaluations/{id}/time-status', [EvaluationController::class, 'getTimeStatus']);
```

---

### Frontend (Vue.js)

#### 1. **Service d'évaluation** (`evaluation.js` - Lignes 143-154)

**Nouvelle méthode**:
```javascript
/**
 * Récupère l'état temporel en temps réel
 */
async getTimeStatus(id) {
  try {
    const response = await api.get(`/evaluations/${id}/time-status`)
    return response
  } catch (error) {
    console.error('Erreur récupération état temporel:', error)
    throw error
  }
}
```

#### 2. **StudentEvaluations.vue**

**A. Affichage de la fenêtre temporelle** (Lignes 84-109)

```vue
<!-- Fenêtre temporelle -->
<div
  v-if="evaluation.programmation?.window"
  class="mb-4 p-4 rounded-lg border-2"
  :class="getWindowStatusClass(evaluation.programmation.window)"
>
  <div class="flex items-center gap-2">
    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
      <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
    </svg>
    <p class="font-medium">
      <span v-if="!evaluation.programmation.window.has_started">
        Ouvrira le {{ formatDateTime(evaluation.programmation.window.start_at) }}
      </span>
      <span v-else-if="evaluation.programmation.window.is_open">
        Disponible jusqu'au {{ formatDateTime(evaluation.programmation.window.end_at) }}
        <span v-if="evaluation.programmation.window.time_left_minutes" class="text-sm">
          ({{ evaluation.programmation.window.time_left_minutes }} min restantes)
        </span>
      </span>
      <span v-else>
        Fermée depuis le {{ formatDateTime(evaluation.programmation.window.end_at) }}
      </span>
    </p>
  </div>
</div>
```

**Couleurs**:
- 🟠 Orange: Pas encore ouverte
- 🟢 Vert: Ouverte
- 🔴 Rouge: Fermée

**B. Bouton conditionnel** (Lignes 129-152)

```vue
<button
  v-if="!evaluation.student_submission"
  @click="startEvaluation(evaluation)"
  :disabled="!canStartEvaluation(evaluation)"
  :class="[
    'flex-1 px-4 py-2 rounded-lg font-medium transition',
    canStartEvaluation(evaluation)
      ? 'bg-green-600 hover:bg-green-700 text-white cursor-pointer'
      : 'bg-gray-400 text-white cursor-not-allowed'
  ]"
>
  <span v-if="!evaluation.programmation?.window">
    Commencer l'évaluation
  </span>
  <span v-else-if="!evaluation.programmation.window.has_started">
    Pas encore ouverte
  </span>
  <span v-else-if="evaluation.programmation.window.is_open">
    Commencer l'évaluation
  </span>
  <span v-else>
    Évaluation fermée
  </span>
</button>
```

**C. Méthodes JavaScript** (Lignes 252-287)

```javascript
canStartEvaluation(evaluation) {
  // Pas de soumission terminée
  if (evaluation.student_submission?.status === 'soumis') {
    return false
  }

  // Vérifier la fenêtre temporelle
  if (evaluation.programmation?.window) {
    return evaluation.programmation.window.is_open === true
  }

  // Si pas de fenêtre (anciennes évaluations), autoriser
  return true
},

getWindowStatusClass(window) {
  if (!window.has_started) {
    return 'bg-orange-50 border-orange-300 text-orange-800'
  }
  if (window.is_open) {
    return 'bg-green-50 border-green-300 text-green-800'
  }
  return 'bg-red-50 border-red-300 text-red-800'
}
```

#### 3. **TakeEvaluation.vue**

**A. Alerte fermeture imminente** (Lignes 12-23)

```vue
<!-- Alerte fermeture imminente fenêtre -->
<div
  v-if="windowTimeLeft !== null && windowTimeLeft <= 5"
  class="mb-4 p-4 bg-red-100 border-2 border-red-500 rounded-lg animate-pulse"
>
  <p class="text-red-900 font-bold text-lg">
    ⚠️ ATTENTION: La fenêtre d'évaluation va se fermer dans {{ windowTimeLeft }} minutes!
  </p>
  <p class="text-red-800 text-sm mt-1">
    Votre évaluation sera automatiquement soumise à la fermeture.
  </p>
</div>
```

**B. Compte à rebours** (Lignes 25-43)

```vue
<!-- Compte à rebours fenêtre temporelle -->
<div
  v-else-if="windowTimeLeft !== null && windowTimeLeft > 0"
  class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-lg"
>
  <div class="flex items-center justify-between">
    <div class="flex items-center gap-2">
      <svg class="w-5 h-5 text-blue-700" fill="currentColor" viewBox="0 0 20 20">
        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
      </svg>
      <p class="text-blue-900 font-medium">
        Temps restant avant fermeture de la fenêtre
      </p>
    </div>
    <p class="text-2xl font-bold text-blue-700">
      {{ formatTimeLeft(windowTimeLeft) }}
    </p>
  </div>
</div>
```

**C. Logique de surveillance** (Lignes 411-438)

```javascript
data() {
  return {
    // ...
    windowTimeLeft: null,
    timeCheckInterval: null,
    // ...
  }
},

mounted() {
  // ...
  if (this.evaluation.programmation?.window) {
    this.windowTimeLeft = this.evaluation.programmation.window.time_left_minutes
  }
  this.startWindowTimeTracking()
},

beforeUnmount() {
  if (this.timeCheckInterval) {
    clearInterval(this.timeCheckInterval)
  }
},

methods: {
  startWindowTimeTracking() {
    // Vérifier le temps toutes les 30 secondes
    this.timeCheckInterval = setInterval(async () => {
      await this.checkWindowTimeRemaining()
    }, 30000) // 30 secondes
  },

  async checkWindowTimeRemaining() {
    try {
      const result = await evaluationService.getTimeStatus(this.evaluation.id)

      if (result.success && result.data.window) {
        const window = result.data.window
        this.windowTimeLeft = window.time_left_minutes

        // Auto-soumission si fenêtre fermée
        if (window.has_ended && !this.submitting) {
          console.warn('⏰ Fenêtre fermée - Auto-soumission')
          clearInterval(this.timeCheckInterval)
          clearInterval(this.timer)
          alert('La fenêtre d\'évaluation est fermée. Soumission automatique de vos réponses...')
          await this.submitEvaluation()
        }
      }
    } catch (error) {
      console.error('Erreur vérification temps fenêtre:', error)
    }
  }
}
```

#### 4. **TeacherEvaluations.vue**

**Affichage de l'état** (Lignes 126-153)

```vue
<div v-if="evaluation.programmation?.window">
  <p class="text-sm text-gray-600">État</p>
  <p class="font-medium">
    <span v-if="!evaluation.programmation.window.has_started" class="flex items-center gap-1 text-orange-600">
      <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
      </svg>
      Prévu
    </span>
    <span v-else-if="evaluation.programmation.window.is_open" class="flex items-center gap-1 text-green-600">
      <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
      </svg>
      En cours ({{ evaluation.programmation.window.time_left_minutes }} min)
    </span>
    <span v-else class="flex items-center gap-1 text-gray-600">
      <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
      </svg>
      Terminé
    </span>
  </p>
</div>
```

---

## 🎯 Fonctionnalités Implémentées

### Côté Étudiant

✅ **Affichage de la fenêtre temporelle** sur `/student/evaluations`
- 🟠 "Ouvrira le [date]" si pas encore démarrée
- 🟢 "Disponible jusqu'au [date] (X min restantes)" si ouverte
- 🔴 "Fermée depuis le [date]" si terminée

✅ **Bouton "Commencer" conditionnel**
- Désactivé (gris) si fenêtre fermée
- Activé (vert) si fenêtre ouverte
- Message adapté selon l'état

✅ **Vérification backend au démarrage**
- Impossible de démarrer si `!window.is_open`
- Message d'erreur contextuel avec la date

✅ **Compte à rebours pendant l'évaluation**
- Affichage du temps restant avant fermeture de la fenêtre
- Rafraîchissement automatique toutes les 30 secondes
- Alerte rouge clignotante si < 5 minutes

✅ **Auto-soumission à la fermeture**
- Soumission automatique quand `window.has_ended`
- Arrêt de tous les timers
- Redirection vers le dashboard

### Côté Enseignant

✅ **Affichage de l'état** sur `/teacher/evaluations`
- 🟠 "Prévu" si `!has_started`
- 🟢 "En cours (X min)" si `is_open`
- ⚫ "Terminé" si `has_ended`

✅ **Gestion des questions autorisée**
- Possibilité de créer/modifier les questions avant l'ouverture
- Possibilité de consulter après la fermeture

---

## 🧪 Guide de Test

### Test 1: Évaluation Pas Encore Ouverte

**Scénario**: Évaluation planifiée pour demain 14h00

**Étapes**:
1. Dans KLASSCI, créer une évaluation avec `date_evaluation` = demain 14h00, `duree_minutes` = 60
2. Dans le LMS, créer la version en ligne avec des questions
3. Se connecter en tant qu'**étudiant**
4. Aller sur `/student/evaluations`

**Résultats attendus**:
- ✅ Évaluation visible dans la liste
- ✅ Encadré orange "Ouvrira le [date]"
- ✅ Bouton "Pas encore ouverte" grisé et désactivé
- ✅ Clic sur le bouton ne fait rien
- ✅ Tentative de démarrage via URL directe → erreur 403

### Test 2: Évaluation Ouverte

**Scénario**: Évaluation ouverte maintenant pour 60 minutes

**Étapes**:
1. Dans KLASSCI, créer évaluation avec `date_evaluation` = maintenant, `duree_minutes` = 60
2. Créer version LMS
3. Se connecter en **étudiant**
4. Aller sur `/student/evaluations`

**Résultats attendus**:
- ✅ Encadré vert "Disponible jusqu'au [date] (60 min restantes)"
- ✅ Bouton "Commencer l'évaluation" vert et actif
- ✅ Clic démarre l'évaluation
- ✅ Page d'évaluation affiche:
  - Encadré bleu "Temps restant avant fermeture: 60 min"
  - Timer normal de l'évaluation
- ✅ Après 30 sec: Le temps se met à jour (59 min)

### Test 3: Évaluation Fermée

**Scénario**: Évaluation terminée hier

**Étapes**:
1. Créer évaluation avec `date_evaluation` = hier
2. Créer version LMS
3. Se connecter en **étudiant**

**Résultats attendus**:
- ✅ Encadré rouge "Fermée depuis le [date]"
- ✅ Bouton "Évaluation fermée" grisé
- ✅ Impossible de démarrer

### Test 4: Auto-Soumission

**Scénario**: Évaluation ouverte pour 2 minutes seulement

**Étapes**:
1. Créer évaluation avec `date_evaluation` = maintenant, `duree_minutes` = 2
2. Créer version LMS
3. Se connecter en **étudiant**
4. Démarrer l'évaluation
5. Répondre à quelques questions
6. **Attendre 2 minutes**

**Résultats attendus**:
- ✅ Après ~1min30: Alerte rouge "⚠️ La fenêtre va se fermer dans 1 minutes!"
- ✅ L'alerte clignote (animate-pulse)
- ✅ Après 2 min: Alert "La fenêtre est fermée. Soumission automatique..."
- ✅ Auto-soumission des réponses
- ✅ Redirection vers `/student/evaluations`
- ✅ Note affichée si `show_results = true`

### Test 5: Enseignant Voit l'État

**Scénario**: Vérifier que l'enseignant voit l'état correct

**Étapes**:
1. Créer 3 évaluations:
   - Eval A: demain (pas encore ouverte)
   - Eval B: maintenant, 60 min (ouverte)
   - Eval C: hier (fermée)
2. Se connecter en **enseignant**
3. Aller sur `/teacher/evaluations`

**Résultats attendus**:
- ✅ Eval A: État = 🟠 "Prévu"
- ✅ Eval B: État = 🟢 "En cours (60 min)"
- ✅ Eval C: État = ⚫ "Terminé"
- ✅ Possibilité de cliquer "Modifier les questions" sur toutes

### Test 6: Compatibilité Ascendante

**Scénario**: Évaluations créées avant l'implémentation des fenêtres

**Étapes**:
1. Utiliser une ancienne évaluation LMS (avant cette mise à jour)
2. Se connecter en **étudiant**

**Résultats attendus**:
- ✅ Pas d'encadré de fenêtre temporelle affiché
- ✅ Bouton "Commencer l'évaluation" actif
- ✅ Fonctionnement normal (comme avant)

---

## 🔍 Logs et Débogage

### Logs Backend Importants

**Logs lors de `studentEvaluations()`**:
```
[2025-10-19 ...] Student Evaluations request
  user_id: 10
  klassci_id: 999001
  klassci_etudiant_id: 999001
  has_klassci_token: true

[2025-10-19 ...] Student classe found
  classe_id: 36

[2025-10-19 ...] Evaluations LMS found for student
  count: 2

[2025-10-19 ...] KLASSCI evaluations retrieved
  count: 5

[2025-10-19 ...] Evaluation enriched with KLASSCI data
  lms_id: 3
  klassci_id: 12
  has_window: true
```

**Logs lors de `startEvaluation()` - Succès**:
```
[2025-10-19 ...] Démarrage évaluation autorisé
  evaluation_id: 3
  student_id: 999001
  window_open: true
  time_left_minutes: 58
```

**Logs lors de `startEvaluation()` - Refusé**:
```
[2025-10-19 ...] Tentative de démarrage hors fenêtre
  evaluation_id: 3
  klassci_evaluation_id: 12
  student_id: 999001
  window: {
    "has_started": false,
    "has_ended": false,
    "is_open": false,
    "start_at": "2025-10-21T14:00:00.000000Z",
    "end_at": "2025-10-21T15:00:00.000000Z",
    "time_left_minutes": null
  }
```

**Logs lors de `getTimeStatus()`**:
```
[2025-10-19 ...] GET /api/evaluations/3/time-status
  user_id: 10
  window: {
    "is_open": true,
    "time_left_minutes": 45
  }
```

### Console Frontend

**StudentEvaluations.vue**:
```javascript
console.log('Évaluations chargées:', evaluations)
console.log('Fenêtre eval 1:', evaluations[0].programmation?.window)
// {
//   "start_at": "2025-10-20T14:00:00.000000Z",
//   "end_at": "2025-10-20T15:00:00.000000Z",
//   "has_started": true,
//   "has_ended": false,
//   "is_open": true,
//   "time_left_minutes": 58
// }
```

**TakeEvaluation.vue**:
```javascript
console.log('Vérification temps fenêtre:', result.data.window)
// Toutes les 30 secondes

console.warn('⏰ Fenêtre fermée - Auto-soumission')
// Quand has_ended = true
```

---

## 📚 Structure de Données Complète

### Réponse de `GET /api/evaluations/student/{klassciEtudiantId}`

```json
{
  "success": true,
  "data": [
    {
      "id": 3,
      "klassci_evaluation_id": 12,
      "klassci_matiere_id": 5,
      "klassci_classe_id": 36,
      "titre": "Évaluation de Mathématiques",
      "description": "QCM sur les équations",
      "type": "qcm",
      "status": "planifiee",
      "date_evaluation": "2025-10-20T14:00:00.000000Z",
      "duree_minutes": 60,
      "coefficient": 2,
      "bareme": 20,
      "is_published": true,
      "allow_retake": false,
      "max_attempts": 1,
      "shuffle_questions": false,
      "show_results": true,
      "questions_count": 10,

      "programmation": {
        "date_evaluation": "2025-10-20T14:00:00.000000Z",
        "duree_minutes": 60,
        "coefficient": 2,
        "bareme": 20,
        "window": {
          "start_at": "2025-10-20T14:00:00.000000Z",
          "end_at": "2025-10-20T15:00:00.000000Z",
          "has_started": true,
          "has_ended": false,
          "is_open": true,
          "time_left_minutes": 58
        }
      },

      "lms_integration": {
        "can_execute_online": true,
        "has_online_version": true,
        "can_take_online": true,
        "notes_count": 0
      },

      "classe": {
        "id": 36,
        "nom": "1A BTS F Bâtiment",
        "code": "1ABTS",
        "niveau": "BTS"
      },

      "matiere": {
        "id": 5,
        "nom": "Mathématiques",
        "code": "MATH101"
      },

      "student_submission": null,

      "questions": [...],
      "submissions": [...]
    }
  ]
}
```

### Réponse de `GET /api/evaluations/{id}/time-status`

```json
{
  "success": true,
  "data": {
    "window": {
      "start_at": "2025-10-20T14:00:00.000000Z",
      "end_at": "2025-10-20T15:00:00.000000Z",
      "has_started": true,
      "has_ended": false,
      "is_open": true,
      "time_left_minutes": 45
    },
    "server_time": "2025-10-20T14:15:32.123456Z"
  }
}
```

### Réponse de `POST /api/evaluations/{id}/start` - Refusé

```json
{
  "success": false,
  "message": "L'évaluation ouvrira le 21/10/2025 à 14:00",
  "window": {
    "start_at": "2025-10-21T14:00:00.000000Z",
    "end_at": "2025-10-21T15:00:00.000000Z",
    "has_started": false,
    "has_ended": false,
    "is_open": false,
    "time_left_minutes": null
  }
}
```

---

## ⚙️ Configuration et Personnalisation

### Intervalle de Rafraîchissement

**Actuel**: 30 secondes

**Modifier** dans `TakeEvaluation.vue:413`:
```javascript
startWindowTimeTracking() {
  this.timeCheckInterval = setInterval(async () => {
    await this.checkWindowTimeRemaining()
  }, 15000) // 15 secondes au lieu de 30
}
```

### Seuil d'Alerte Rouge

**Actuel**: 5 minutes

**Modifier** dans `TakeEvaluation.vue:14`:
```vue
<div
  v-if="windowTimeLeft !== null && windowTimeLeft <= 10"
  class="mb-4 p-4 bg-red-100 border-2 border-red-500 rounded-lg animate-pulse"
>
  <!-- 10 minutes au lieu de 5 -->
```

### Fallback Gracieux Backend

**Actuel**: En cas d'erreur KLASSCI, le démarrage est autorisé (avec log)

**Désactiver le fallback** dans `EvaluationController.php:542-550`:
```php
} catch (\Exception $e) {
    \Log::error('Erreur vérification fenêtre temporelle', [
        'evaluation_id' => $id,
        'error' => $e->getMessage()
    ]);

    // BLOQUER au lieu de permettre
    return response()->json([
        'success' => false,
        'message' => 'Impossible de vérifier la disponibilité de l\'évaluation'
    ], 500);
}
```

---

## 🚨 Points d'Attention

### 1. Fuseaux Horaires

Les dates `start_at`/`end_at` sont en **UTC** (format ISO 8601).

**Conversion côté frontend**:
```javascript
formatDateTime(dateString) {
  const date = new Date(dateString) // Conversion automatique en heure locale
  return date.toLocaleString('fr-FR', {...})
}
```

**Vérifier** dans la console:
```javascript
const window = evaluation.programmation.window
console.log('UTC:', window.start_at)
console.log('Local:', new Date(window.start_at).toString())
```

### 2. Cache et Performances

**Pas de cache côté LMS** pour `programmation.window`:
- Toujours récupéré depuis KLASSCI en temps réel
- Cache KLASSCI géré par le backend KLASSCI lui-même

**Performance**:
- Appel à `/evaluations` KLASSCI toutes les 30 sec pendant l'évaluation
- Acceptable car calcul côté serveur (pas de requête DB lourde)

### 3. Compatibilité

**Évaluations anciennes** (sans fenêtre):
- `programmation?.window` retourne `null` ou `undefined`
- Comportement par défaut: autoriser le démarrage
- Pas d'affichage de fenêtre dans l'UI

**KLASSCI non disponible**:
- Backend log l'erreur
- Fallback gracieux: autorisation + log warning
- Frontend: Pas de fenêtre affichée

---

## ✅ Checklist de Validation

### Backend

- [x] `studentEvaluations()` enrichit avec `programmation.window`
- [x] `startEvaluation()` vérifie `window.is_open`
- [x] Route `GET /api/evaluations/{id}/time-status` ajoutée
- [x] Logs ajoutés pour les tentatives hors fenêtre
- [x] Gestion des erreurs KLASSCI (fallback gracieux)
- [x] Messages d'erreur contextuels (ouvrira le... / fermée depuis...)

### Frontend Étudiant

- [x] Affichage fenêtre temporelle (ouvrira/disponible/fermée)
- [x] Couleurs selon état (orange/vert/rouge)
- [x] Bouton "Commencer" désactivé si `!window.is_open`
- [x] Compte à rebours dans `TakeEvaluation.vue`
- [x] Rafraîchissement toutes les 30 secondes
- [x] Alerte rouge si < 5 min restantes
- [x] Auto-soumission à la fermeture
- [x] Compatibilité évaluations anciennes (sans fenêtre)

### Frontend Enseignant

- [x] Affichage état (prévu/en cours/terminé)
- [x] Temps restant affiché si `is_open = true`
- [x] Possibilité de modifier questions même si `is_open = false`

### Documentation

- [x] `ANALYSE_FENETRES_TEMPORELLES_EVALUATIONS.md` (Analyse initiale)
- [x] `IMPLEMENTATION_FENETRES_TEMPORELLES_COMPLETE.md` (Ce document)

---

## 📖 Ressources

### Fichiers Modifiés

**Backend**:
- `app/Http/Controllers/API/EvaluationController.php` (Lignes 370-442, 461-580, 640-697)
- `routes/api.php` (Ligne 393)

**Frontend**:
- `src/services/evaluation.js` (Lignes 143-154)
- `src/views/evaluations/StudentEvaluations.vue` (Lignes 84-109, 129-152, 252-287)
- `src/views/evaluations/TakeEvaluation.vue` (Lignes 12-43, 299-300, 336-342, 363-371, 401-438, 473-479)
- `src/views/evaluations/TeacherEvaluations.vue` (Lignes 126-153)

### Références KLASSCI

**Backend KLASSCI** (référence mentionnée):
- `app/Http/Controllers/API/LMSDataController.php:607-649` (Exposition fenêtres)
- `app/Http/Controllers/API/LMSDataController.php:940-1020` (Teacher dashboard)
- `app/Http/Controllers/API/LMSDataController.php:1207-1246` (Student dashboard)

---

**Date d'implémentation**: 2025-10-19
**Version**: 1.0
**Auteur**: Claude Code
**Statut**: ✅ PRODUCTION READY

🤖 Généré avec [Claude Code](https://claude.com/claude-code)
