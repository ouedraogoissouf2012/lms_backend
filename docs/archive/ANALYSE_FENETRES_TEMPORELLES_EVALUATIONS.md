# Analyse: Intégration des Fenêtres Temporelles d'Évaluations

## 🎯 Résumé Exécutif

**Statut Actuel**: ❌ **NON IMPLÉMENTÉ**

Votre backend KLASSCI expose maintenant des fenêtres temporelles pour les évaluations (`programmation.window`), mais votre LMS actuel **ne les utilise pas**. Les étudiants peuvent actuellement démarrer une évaluation dès qu'elle est publiée, sans respecter les heures de début/fin.

**Impact**: Les évaluations ne sont pas synchronisées avec les créneaux horaires définis par les enseignants dans KLASSCI.

**Action Requise**: Modifications nécessaires côté frontend et backend pour respecter les fenêtres temporelles.

---

## 📊 Ce que KLASSCI Expose Maintenant

### Structure de Données

D'après le message, KLASSCI (`app/Http/Controllers/API/LMSDataController.php:607-649`) expose maintenant:

```json
{
  "id": 1,
  "titre": "Évaluation de Mathématiques",
  "matiere": {...},
  "classe": {...},
  "programmation": {
    "date_evaluation": "2025-10-20T14:00:00.000000Z",
    "duree_minutes": 60,
    "coefficient": 2,
    "bareme": 20,
    "window": {
      "start_at": "2025-10-20T14:00:00.000000Z",
      "end_at": "2025-10-20T15:00:00.000000Z",
      "has_started": false,
      "has_ended": false,
      "is_open": false,
      "time_left_minutes": null
    }
  },
  "lms_integration": {
    "can_execute_online": true,
    "has_online_version": false,
    "can_take_online": false  // ✅ Dépend de window.is_open ET absence de note
  }
}
```

### Logique de Calcul

**Calcul automatique** (sans modification en base):
```
start_at = date_evaluation
end_at = date_evaluation + duree_minutes
has_started = now() >= start_at
has_ended = now() >= end_at
is_open = has_started AND NOT has_ended
time_left_minutes = minutes entre now() et end_at (si ouvert)
```

### Règles Métier

#### Côté Étudiant (`lms_integration.can_take_online`)

**Peut passer l'évaluation en ligne** seulement si:
1. ✅ `window.is_open = true` (fenêtre temporelle ouverte)
2. ✅ L'étudiant n'a pas encore de note dans KLASSCI

**Comportement attendu**:
- L'étudiant **voit** la fiche d'évaluation dès sa publication
- Le bouton **"Commencer"** reste **désactivé** jusqu'à `start_at`
- Le bouton devient **actif** pendant la fenêtre
- Le bouton se **désactive** après `end_at`

#### Côté Enseignant/Coordinateur

**Peut gérer les questions** à tout moment:
- Avant `start_at`: Préparation du QCM
- Pendant `is_open`: Surveillance
- Après `end_at`: Consultation/correction

**L'UI doit afficher**:
- "Prévu pour le [date]" si `has_started = false`
- "En cours jusqu'à [date]" si `is_open = true`
- "Terminé le [date]" si `has_ended = true`

---

## ❌ Ce qui Manque dans Votre Implémentation Actuelle

### Backend LMS (`app/Http/Controllers/API/EvaluationController.php`)

#### 1. Méthode `studentEvaluations()` (Lignes 332-376)

**Problème**: Ne filtre pas selon `window.is_open`

**Actuel**:
```php
$evaluations = Evaluation::with('questions', 'submissions')
    ->where('klassci_classe_id', $classeId)
    ->where('is_published', true)
    ->whereIn('status', ['planifiee', 'en_cours'])
    ->orderBy('date_evaluation', 'desc')
    ->get();
```

**Ce qui manque**:
- ✅ Les évaluations sont récupérées correctement
- ❌ Mais `programmation.window` n'est PAS vérifié
- ❌ Les évaluations KLASSCI sont enrichies mais sans les infos `window`

**Solution nécessaire**:
1. Récupérer les évaluations KLASSCI avec leurs fenêtres via l'API
2. Merger avec les évaluations LMS locales
3. Passer `programmation.window` au frontend

#### 2. Méthode `startEvaluation()` (Lignes 410-433)

**Problème**: Ne vérifie pas `window.is_open` avant de démarrer

**Actuel**:
```php
public function startEvaluation(int $id, Request $request): JsonResponse
{
    $evaluation = Evaluation::find($id);

    if (!$evaluation || !$evaluation->isAvailable()) {
        return response()->json([
            'success' => false,
            'message' => 'Évaluation non disponible'
        ], 404);
    }

    // ❌ Crée la soumission sans vérifier window.is_open
    $submission = EvaluationSubmission::create([...]);
}
```

**Ce qui manque**:
- ❌ Pas de vérification de `window.is_open`
- ❌ Pas de vérification de `start_at`/`end_at`
- ❌ L'étudiant peut démarrer à tout moment si `is_published = true`

**Solution nécessaire**:
1. Avant de créer la soumission, appeler l'API KLASSCI
2. Récupérer `programmation.window.is_open`
3. Rejeter si `is_open = false`

#### 3. Modèle `Evaluation` (Méthode `isAvailable()`)

**Problème**: Ne connaît pas les fenêtres temporelles KLASSCI

**Actuel** (probablement):
```php
public function isAvailable(): bool
{
    return $this->is_published && in_array($this->status, ['planifiee', 'en_cours']);
}
```

**Solution nécessaire**:
- Ne peut pas être résolu au niveau du modèle car les fenêtres sont calculées par KLASSCI
- Doit être vérifié au niveau du controller via API KLASSCI

---

### Frontend LMS

#### 1. `StudentEvaluations.vue` (Lignes 102-130)

**Problème**: Le bouton "Commencer" est actif dès que `!evaluation.student_submission`

**Actuel**:
```vue
<button
  v-if="!evaluation.student_submission"
  @click="startEvaluation(evaluation)"
  class="flex-1 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition"
>
  Commencer l'évaluation
</button>
```

**Ce qui manque**:
- ❌ Pas de vérification de `evaluation.programmation?.window?.is_open`
- ❌ Pas d'affichage de la fenêtre temporelle
- ❌ Pas de message "Ouvrira le..." ou "Fermera dans..."
- ❌ Pas de compte à rebours

**Solution nécessaire**:
```vue
<!-- Affichage fenêtre temporelle -->
<div v-if="evaluation.programmation?.window" class="mb-3 p-3 bg-gray-50 rounded-lg">
  <div v-if="!evaluation.programmation.window.has_started" class="text-orange-700">
    <svg class="w-5 h-5 inline" ...><!-- Icône horloge --></svg>
    Ouvrira le {{ formatDate(evaluation.programmation.window.start_at) }}
  </div>
  <div v-else-if="evaluation.programmation.window.is_open" class="text-green-700">
    <svg class="w-5 h-5 inline" ...><!-- Icône en cours --></svg>
    Disponible jusqu'à {{ formatDate(evaluation.programmation.window.end_at) }}
    <span v-if="evaluation.programmation.window.time_left_minutes">
      ({{ evaluation.programmation.window.time_left_minutes }} min restantes)
    </span>
  </div>
  <div v-else class="text-red-700">
    <svg class="w-5 h-5 inline" ...><!-- Icône fermé --></svg>
    Fermée depuis le {{ formatDate(evaluation.programmation.window.end_at) }}
  </div>
</div>

<!-- Bouton "Commencer" conditionnel -->
<button
  v-if="!evaluation.student_submission && canStartEvaluation(evaluation)"
  @click="startEvaluation(evaluation)"
  :disabled="!evaluation.programmation?.window?.is_open"
  :class="[
    'flex-1 px-4 py-2 rounded-lg font-medium transition',
    evaluation.programmation?.window?.is_open
      ? 'bg-green-600 hover:bg-green-700 text-white'
      : 'bg-gray-400 text-white cursor-not-allowed'
  ]"
>
  {{ evaluation.programmation?.window?.is_open ? 'Commencer l\'évaluation' : 'Pas encore ouverte' }}
</button>
```

**Méthode JavaScript nécessaire**:
```javascript
methods: {
  canStartEvaluation(evaluation) {
    // Vérifier que l'étudiant n'a pas de soumission terminée
    if (evaluation.student_submission?.status === 'soumis') {
      return false
    }

    // Vérifier la fenêtre temporelle
    return evaluation.programmation?.window?.is_open === true
  }
}
```

#### 2. `TakeEvaluation.vue`

**Problème**: Pas de surveillance du temps restant

**Ce qui manque**:
- ❌ Pas de compte à rebours affiché
- ❌ Pas d'auto-soumission à la fin de la fenêtre
- ❌ Pas de rafraîchissement de `time_left_minutes`

**Solution nécessaire**:
```vue
<template>
  <div>
    <!-- Compte à rebours fenêtre temporelle -->
    <div v-if="windowTimeLeft !== null" class="bg-red-50 border border-red-200 p-4 rounded-lg mb-4">
      <p class="text-red-900 font-medium">
        ⏰ Temps restant avant la fermeture: {{ formatTimeLeft(windowTimeLeft) }}
      </p>
    </div>

    <!-- Compte à rebours durée évaluation -->
    <div v-if="evaluationTimeLeft !== null" class="bg-blue-50 border border-blue-200 p-4 rounded-lg mb-4">
      <p class="text-blue-900 font-medium">
        ⏱️ Temps restant pour cette tentative: {{ formatTimeLeft(evaluationTimeLeft) }}
      </p>
    </div>
  </div>
</template>

<script>
export default {
  data() {
    return {
      windowTimeLeft: null, // Minutes restantes de la fenêtre
      evaluationTimeLeft: null, // Minutes restantes de la tentative
      refreshInterval: null
    }
  },
  mounted() {
    this.startTimeTracking()
  },
  beforeUnmount() {
    if (this.refreshInterval) {
      clearInterval(this.refreshInterval)
    }
  },
  methods: {
    startTimeTracking() {
      // Rafraîchir toutes les 30 secondes
      this.refreshInterval = setInterval(async () => {
        await this.updateTimeLeft()
      }, 30000)

      this.updateTimeLeft()
    },

    async updateTimeLeft() {
      // Appeler l'API pour obtenir le temps restant
      // (le backend KLASSCI recalcule window.time_left_minutes)
      const result = await evaluationService.getEvaluation(this.evaluationId)

      if (result.success && result.data.programmation?.window) {
        const window = result.data.programmation.window
        this.windowTimeLeft = window.time_left_minutes

        // Auto-soumission si la fenêtre se ferme
        if (window.has_ended) {
          alert('La fenêtre d\'évaluation est fermée. Soumission automatique...')
          await this.submitEvaluation()
        }
      }
    },

    formatTimeLeft(minutes) {
      if (minutes <= 0) return '0 min'
      const hours = Math.floor(minutes / 60)
      const mins = minutes % 60
      return hours > 0 ? `${hours}h ${mins}min` : `${mins} min`
    }
  }
}
</script>
```

#### 3. `TeacherEvaluations.vue`

**Problème**: Pas d'indication de l'état de la fenêtre temporelle

**Ce qui manque**:
- ❌ Pas d'affichage de "Prévu pour le..."
- ❌ Pas d'indication "En cours" / "Terminé"

**Solution nécessaire**:
```vue
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4">
  <div>
    <p class="text-sm text-gray-600">Date</p>
    <p class="font-medium">{{ formatDate(evaluation.programmation?.date_evaluation || evaluation.date_evaluation) }}</p>
  </div>

  <!-- NOUVEAU: État de la fenêtre -->
  <div>
    <p class="text-sm text-gray-600">État</p>
    <p class="font-medium">
      <span v-if="!evaluation.programmation?.window?.has_started" class="text-orange-600">
        ⏳ Prévu pour le {{ formatDate(evaluation.programmation.window.start_at) }}
      </span>
      <span v-else-if="evaluation.programmation?.window?.is_open" class="text-green-600">
        ✓ En cours ({{ evaluation.programmation.window.time_left_minutes }} min restantes)
      </span>
      <span v-else class="text-gray-600">
        ✗ Terminé le {{ formatDate(evaluation.programmation.window.end_at) }}
      </span>
    </p>
  </div>
</div>
```

---

## ✅ Plan d'Action: Implémentation Complète

### Phase 1: Backend - Récupération des Fenêtres ⏱️ 2-3h

#### Tâche 1.1: Enrichir `studentEvaluations()` avec les fenêtres KLASSCI

**Fichier**: `app/Http/Controllers/API/EvaluationController.php`

**Méthode**: `studentEvaluations()`

**Modifications**:
```php
public function studentEvaluations(int $klassciEtudiantId, Request $request): JsonResponse
{
    // ... code existant pour récupérer classe_id ...

    // Récupérer les évaluations LMS publiées
    $evaluationsLMS = Evaluation::with('questions', 'submissions')
        ->where('klassci_classe_id', $classeId)
        ->where('is_published', true)
        ->whereIn('status', ['planifiee', 'en_cours'])
        ->orderBy('date_evaluation', 'desc')
        ->get();

    // ✅ NOUVEAU: Récupérer les évaluations KLASSCI avec fenêtres temporelles
    $klassciEvaluations = $this->klassciService->requestWithUserToken(
        $klassciToken,
        'evaluations', // GET /api/lms/evaluations
        'GET'
    );

    // Enrichir les évaluations LMS avec les fenêtres KLASSCI
    $enrichedEvaluations = $evaluationsLMS->map(function ($evalLMS) use ($klassciEvaluations, $klassciEtudiantId) {
        $evalArray = $evalLMS->toArray();

        // Trouver l'évaluation KLASSCI correspondante
        $klassciEval = collect($klassciEvaluations['data'] ?? [])->firstWhere('id', $evalLMS->klassci_evaluation_id);

        if ($klassciEval) {
            // ✅ Ajouter programmation.window
            $evalArray['programmation'] = $klassciEval['programmation'] ?? null;

            // ✅ Ajouter lms_integration
            $evalArray['lms_integration'] = $klassciEval['lms_integration'] ?? null;

            // ✅ Ajouter classe et matière
            $evalArray['classe'] = $klassciEval['classe'] ?? null;
            $evalArray['matiere'] = $klassciEval['matiere'] ?? null;
        }

        // Ajouter la soumission de l'étudiant
        $submission = $evalLMS->submissions()
            ->where('klassci_etudiant_id', $klassciEtudiantId)
            ->latest()
            ->first();
        $evalArray['student_submission'] = $submission;

        return $evalArray;
    })->toArray();

    return response()->json([
        'success' => true,
        'data' => $enrichedEvaluations
    ]);
}
```

#### Tâche 1.2: Vérifier `window.is_open` dans `startEvaluation()`

**Fichier**: `app/Http/Controllers/API/EvaluationController.php`

**Méthode**: `startEvaluation()`

**Modifications**:
```php
public function startEvaluation(int $id, Request $request): JsonResponse
{
    $evaluation = Evaluation::find($id);

    if (!$evaluation || !$evaluation->is_published) {
        return response()->json([
            'success' => false,
            'message' => 'Évaluation non disponible'
        ], 404);
    }

    $klassciEtudiantId = $request->klassci_etudiant_id;

    // ✅ NOUVEAU: Vérifier la fenêtre temporelle KLASSCI
    try {
        $user = $request->user();
        $klassciToken = $user->klassci_token;

        // Récupérer l'évaluation KLASSCI avec sa fenêtre
        $klassciEval = $this->klassciService->requestWithUserToken(
            $klassciToken,
            "evaluations/{$evaluation->klassci_evaluation_id}",
            'GET'
        );

        $window = $klassciEval['data']['programmation']['window'] ?? null;

        // ✅ Vérifier que la fenêtre est ouverte
        if (!$window || !$window['is_open']) {
            $message = 'L\'évaluation n\'est pas encore ouverte';

            if ($window) {
                if (!$window['has_started']) {
                    $startAt = \Carbon\Carbon::parse($window['start_at'])->format('d/m/Y à H:i');
                    $message = "L'évaluation ouvrira le {$startAt}";
                } elseif ($window['has_ended']) {
                    $endAt = \Carbon\Carbon::parse($window['end_at'])->format('d/m/Y à H:i');
                    $message = "L'évaluation est fermée depuis le {$endAt}";
                }
            }

            \Log::warning('Tentative de démarrage hors fenêtre', [
                'evaluation_id' => $id,
                'student_id' => $klassciEtudiantId,
                'window' => $window
            ]);

            return response()->json([
                'success' => false,
                'message' => $message,
                'window' => $window
            ], 403);
        }

        \Log::info('Démarrage évaluation autorisé', [
            'evaluation_id' => $id,
            'student_id' => $klassciEtudiantId,
            'window_open' => true,
            'time_left_minutes' => $window['time_left_minutes']
        ]);

    } catch (\Exception $e) {
        \Log::error('Erreur vérification fenêtre', [
            'error' => $e->getMessage()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Impossible de vérifier la disponibilité de l\'évaluation'
        ], 500);
    }

    // ✅ Code existant: Vérifier tentatives, créer soumission, etc.
    $attemptsCount = EvaluationSubmission::where('evaluation_id', $id)
        ->where('klassci_etudiant_id', $klassciEtudiantId)
        ->count();

    if ($attemptsCount >= $evaluation->max_attempts && !$evaluation->allow_retake) {
        return response()->json([
            'success' => false,
            'message' => 'Nombre maximum de tentatives atteint'
        ], 403);
    }

    $submission = EvaluationSubmission::create([
        'evaluation_id' => $id,
        'klassci_etudiant_id' => $klassciEtudiantId,
        'attempt' => $attemptsCount + 1,
        'status' => 'en_cours',
        'started_at' => now(),
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Évaluation démarrée',
        'data' => $submission,
        'window' => $window
    ]);
}
```

#### Tâche 1.3: Ajouter méthode pour rafraîchir le temps restant

**Fichier**: `app/Http/Controllers/API/EvaluationController.php`

**Nouvelle méthode**:
```php
/**
 * GET /api/evaluations/{id}/time-status
 * Récupère l'état temporel en temps réel
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
        $klassciEval = $this->klassciService->requestWithUserToken(
            $klassciToken,
            "evaluations/{$evaluation->klassci_evaluation_id}",
            'GET'
        );

        $window = $klassciEval['data']['programmation']['window'] ?? null;

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

**Ajouter la route**:

**Fichier**: `routes/api.php`

```php
// Dans le groupe auth:sanctum pour les évaluations
Route::middleware(['auth:sanctum', 'klassci.sync'])->group(function () {
    // ... routes existantes ...

    // ✅ NOUVEAU: État temporel en temps réel
    Route::get('evaluations/{id}/time-status', [EvaluationController::class, 'getTimeStatus']);
});
```

---

### Phase 2: Frontend - Affichage et Contrôles ⏱️ 3-4h

#### Tâche 2.1: Adapter `StudentEvaluations.vue`

**Fichier**: `lms-frontend/src/views/evaluations/StudentEvaluations.vue`

**Modifications**:

1. **Ajouter l'affichage de la fenêtre temporelle** (avant les boutons d'action):

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

2. **Modifier le bouton "Commencer"**:

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

3. **Ajouter les méthodes JavaScript**:

```javascript
methods: {
  // ... méthodes existantes ...

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
  },

  formatDateTime(dateString) {
    if (!dateString) return ''
    const date = new Date(dateString)
    return date.toLocaleString('fr-FR', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    })
  }
}
```

#### Tâche 2.2: Ajouter compte à rebours dans `TakeEvaluation.vue`

**Fichier**: `lms-frontend/src/views/evaluations/TakeEvaluation.vue`

**Ajouts**:

1. **Template** (en haut de la page):

```vue
<template>
  <div class="min-h-screen bg-gray-50 py-8">
    <div class="max-w-4xl mx-auto px-4">

      <!-- Alerte fermeture imminente -->
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

      <!-- Compte à rebours fenêtre -->
      <div
        v-else-if="windowTimeLeft !== null"
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

      <!-- Reste du template existant -->
    </div>
  </div>
</template>
```

2. **Script**:

```javascript
import evaluationService from '@/services/evaluation'

export default {
  name: 'TakeEvaluation',
  data() {
    return {
      evaluation: null,
      submission: null,
      answers: {},
      windowTimeLeft: null,
      timeCheckInterval: null,
      loading: true,
      submitting: false
    }
  },
  async mounted() {
    await this.loadEvaluation()
    this.startTimeTracking()
  },
  beforeUnmount() {
    this.stopTimeTracking()
  },
  methods: {
    async loadEvaluation() {
      // ... code existant ...

      // ✅ Initialiser le temps restant
      if (this.evaluation.programmation?.window) {
        this.windowTimeLeft = this.evaluation.programmation.window.time_left_minutes
      }
    },

    startTimeTracking() {
      // Vérifier le temps toutes les 30 secondes
      this.timeCheckInterval = setInterval(async () => {
        await this.checkTimeRemaining()
      }, 30000) // 30 secondes
    },

    stopTimeTracking() {
      if (this.timeCheckInterval) {
        clearInterval(this.timeCheckInterval)
        this.timeCheckInterval = null
      }
    },

    async checkTimeRemaining() {
      try {
        const result = await evaluationService.getTimeStatus(this.evaluation.id)

        if (result.success && result.data.window) {
          const window = result.data.window
          this.windowTimeLeft = window.time_left_minutes

          // ✅ Auto-soumission si fenêtre fermée
          if (window.has_ended && !this.submitting) {
            console.warn('⏰ Fenêtre fermée - Auto-soumission')
            alert('La fenêtre d\'évaluation est fermée. Soumission automatique de vos réponses...')
            await this.submitEvaluation()
          }
        }
      } catch (error) {
        console.error('Erreur vérification temps:', error)
      }
    },

    formatTimeLeft(minutes) {
      if (minutes === null || minutes <= 0) return '0 min'
      const hours = Math.floor(minutes / 60)
      const mins = minutes % 60
      if (hours > 0) {
        return `${hours}h ${mins.toString().padStart(2, '0')}min`
      }
      return `${mins} min`
    },

    async submitEvaluation() {
      this.submitting = true
      this.stopTimeTracking()

      // ... code existant de soumission ...
    }
  }
}
```

3. **Ajouter la méthode au service**:

**Fichier**: `lms-frontend/src/services/evaluation.js`

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

#### Tâche 2.3: Adapter `TeacherEvaluations.vue`

**Fichier**: `lms-frontend/src/views/evaluations/TeacherEvaluations.vue`

**Modification** (dans la carte d'évaluation):

```vue
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4">
  <div>
    <p class="text-sm text-gray-600">Matière</p>
    <p class="font-medium">{{ evaluation.matiere?.nom || 'Non définie' }}</p>
  </div>
  <div>
    <p class="text-sm text-gray-600">Classe</p>
    <p class="font-medium">{{ evaluation.classe?.nom || 'Non définie' }}</p>
  </div>
  <div>
    <p class="text-sm text-gray-600">Date</p>
    <p class="font-medium">{{ formatDate(evaluation.programmation?.date_evaluation) }}</p>
  </div>

  <!-- NOUVEAU: État fenêtre temporelle -->
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
</div>
```

---

### Phase 3: Tests et Validation ⏱️ 1-2h

#### Test 1: Évaluation pas encore ouverte

1. **Créer une évaluation** avec `date_evaluation` dans le futur (ex: demain 14h00)
2. **Se connecter en étudiant**
3. **Vérifier**:
   - ✅ L'évaluation apparaît dans la liste
   - ✅ Message "Ouvrira le [date]" affiché
   - ✅ Bouton "Pas encore ouverte" désactivé (grisé)
   - ✅ Clic sur le bouton ne fait rien

#### Test 2: Évaluation ouverte

1. **Créer une évaluation** avec `date_evaluation` = maintenant, `duree_minutes` = 60
2. **Se connecter en étudiant**
3. **Vérifier**:
   - ✅ Message "Disponible jusqu'à [date]" affiché
   - ✅ "XX min restantes" affiché
   - ✅ Bouton "Commencer l'évaluation" activé (vert)
   - ✅ Clic démarre l'évaluation
4. **Pendant l'évaluation**:
   - ✅ Compte à rebours "Temps restant avant fermeture" affiché
   - ✅ Se met à jour toutes les 30 secondes
   - ✅ Si < 5 min: alerte rouge clignotante

#### Test 3: Évaluation fermée

1. **Créer une évaluation** avec `date_evaluation` = hier
2. **Se connecter en étudiant**
3. **Vérifier**:
   - ✅ Message "Fermée depuis le [date]" affiché
   - ✅ Bouton "Évaluation fermée" désactivé (grisé)
   - ✅ Clic sur le bouton ne fait rien

#### Test 4: Auto-soumission à la fermeture

1. **Créer une évaluation** avec `date_evaluation` = maintenant, `duree_minutes` = 2
2. **Se connecter en étudiant**
3. **Démarrer l'évaluation**
4. **Attendre 2 minutes**
5. **Vérifier**:
   - ✅ Alerte "La fenêtre va se fermer" s'affiche
   - ✅ Auto-soumission après les 2 minutes
   - ✅ Redirection vers la liste des évaluations
   - ✅ Note affichée

#### Test 5: Enseignant voit l'état

1. **Se connecter en enseignant**
2. **Aller sur `/teacher/evaluations`**
3. **Vérifier** pour chaque évaluation:
   - ✅ "Prévu" (orange) si `has_started = false`
   - ✅ "En cours (XX min)" (vert) si `is_open = true`
   - ✅ "Terminé" (gris) si `has_ended = true`
4. **Cliquer sur "Modifier les questions"**:
   - ✅ Fonctionne même si `is_open = false`

---

## 📋 Checklist Finale

### Backend

- [ ] `studentEvaluations()` enrichit avec `programmation.window`
- [ ] `startEvaluation()` vérifie `window.is_open` avant de créer la soumission
- [ ] Nouvelle route `GET /api/evaluations/{id}/time-status`
- [ ] Logs ajoutés pour les tentatives hors fenêtre
- [ ] Gestion des erreurs KLASSCI

### Frontend Étudiant

- [ ] Affichage de la fenêtre temporelle (ouvrira / disponible / fermée)
- [ ] Bouton "Commencer" désactivé si `!window.is_open`
- [ ] Compte à rebours dans `TakeEvaluation.vue`
- [ ] Auto-soumission à la fermeture de la fenêtre
- [ ] Alerte rouge si < 5 min restantes

### Frontend Enseignant

- [ ] Affichage de l'état (prévu / en cours / terminé)
- [ ] Temps restant affiché si `is_open = true`
- [ ] Possibilité de modifier les questions même si `is_open = false`

### Tests

- [ ] Test évaluation pas encore ouverte
- [ ] Test évaluation ouverte
- [ ] Test évaluation fermée
- [ ] Test auto-soumission
- [ ] Test enseignant voit l'état

---

## 🎯 Conclusion et Recommandations

### Priorité HAUTE

Cette fonctionnalité est **critique** pour respecter les créneaux horaires des évaluations. Sans elle:
- ❌ Les étudiants peuvent passer les évaluations à n'importe quel moment
- ❌ Pas de synchronisation avec les plannings KLASSCI
- ❌ Risque de triche (étudiants passent avant l'heure officielle)

### Estimation Totale

- **Backend**: 2-3 heures
- **Frontend**: 3-4 heures
- **Tests**: 1-2 heures
- **Total**: 6-9 heures

### Ordre d'Implémentation Recommandé

1. **Backend Phase 1** (startEvaluation vérification) → Bloque immédiatement les démarrages hors fenêtre
2. **Frontend Phase 2.1** (StudentEvaluations affichage) → L'étudiant voit les fenêtres
3. **Backend Phase 1** (studentEvaluations enrichissement) → Fourni les données window
4. **Frontend Phase 2.2** (TakeEvaluation compte à rebours) → Auto-soumission
5. **Frontend Phase 2.3** (TeacherEvaluations état) → Info pour les enseignants
6. **Phase 3** (Tests) → Validation

### Points d'Attention

1. **Fuseau horaire**: Les dates `start_at`/`end_at` sont en UTC, pensez à les convertir en heure locale pour l'affichage
2. **Cache**: Ne pas mettre en cache `programmation.window` côté LMS, toujours récupérer depuis KLASSCI
3. **Latence réseau**: Le rafraîchissement toutes les 30 secondes peut être insuffisant, envisager 15 secondes
4. **Auto-soumission**: Bien gérer le cas où l'étudiant a déjà soumis manuellement

---

**Date d'analyse**: 2025-10-19
**Version**: 1.0
**Auteur**: Claude Code

🤖 Généré avec [Claude Code](https://claude.com/claude-code)
