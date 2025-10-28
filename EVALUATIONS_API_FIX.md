# Corrections API Évaluations - Documentation Frontend

## Problèmes Résolus

### 1. Erreur 401 (Unauthorized) sur `/api/evaluations`

**Cause**: L'endpoint `/api/evaluations` requiert une authentification Sanctum, mais le service frontend n'envoie pas le token d'authentification.

**Solution Frontend**:

Le fichier `evaluation.js` doit inclure le token Sanctum dans les headers de la requête. Voici comment corriger:

```javascript
// AVANT (incorrect)
export async function getEvaluations() {
  try {
    const response = await axios.get('/api/evaluations');
    return response.data;
  } catch (error) {
    console.error('Erreur récupération évaluations:', error);
    throw error;
  }
}

// APRÈS (correct)
import api from '@/services/api'; // Utiliser votre instance axios configurée avec auth

export async function getEvaluations() {
  try {
    // L'instance api doit automatiquement inclure le token Sanctum
    const response = await api.get('/api/evaluations');
    return response.data;
  } catch (error) {
    console.error('Erreur récupération évaluations:', error);
    throw error;
  }
}
```

**Configuration requise dans `api.js`**:
```javascript
import axios from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000',
});

// Intercepteur pour ajouter le token Sanctum automatiquement
api.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('sanctum_token'); // Ou votre méthode de stockage
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  (error) => {
    return Promise.reject(error);
  }
);

export default api;
```

### 2. Données Manquantes (classe, matière, date)

**Correction Appliquée (Backend)**:

L'API `/api/evaluations` retourne maintenant des données enrichies avec:
- Informations complètes de la classe
- Informations complètes de la matière
- Dates formatées pour l'affichage

**Structure de Réponse Enrichie**:

```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "klassci_evaluation_id": 123,
      "klassci_matiere_id": 5,
      "klassci_classe_id": 10,
      "klassci_enseignant_id": 3,
      "titre": "Évaluation de Mathématiques",
      "description": "Test sur les fonctions",
      "type": "qcm",
      "status": "planifiee",
      "date_evaluation": "2025-10-25T10:00:00.000000Z",
      "date_evaluation_formatted": "25/10/2025 à 10:00",
      "date_evaluation_short": "25/10/2025",
      "duree_minutes": 60,
      "coefficient": 2.0,
      "bareme": 20.0,
      "is_online": true,
      "allow_retake": false,
      "max_attempts": 1,
      "shuffle_questions": true,
      "show_results": true,
      "is_published": true,
      "notes_published": false,
      "created_at": "2025-10-19T...",
      "updated_at": "2025-10-19T...",

      // NOUVELLES DONNÉES ENRICHIES
      "classe": {
        "id": 10,
        "code": "6A",
        "nom": "Sixième A",
        "niveau": "6ème",
        "filiere": "Général",
        "annee_academique": "2024-2025",
        "effectif": 35
      },

      "matiere": {
        "id": 5,
        "code": "MATH",
        "nom": "Mathématiques",
        "coefficient": 3
      },

      "questions": [...],
      "submissions": [...]
    }
  ]
}
```

## Utilisation dans le Frontend

### Exemple d'Affichage avec les Nouvelles Données

```vue
<template>
  <div class="evaluation-card">
    <h3>{{ evaluation.titre }}</h3>

    <!-- Classe - Maintenant disponible -->
    <div class="classe">
      <strong>Classe:</strong>
      {{ evaluation.classe?.nom || 'Non définie' }}
      ({{ evaluation.classe?.code }})
    </div>

    <!-- Matière - Maintenant disponible -->
    <div class="matiere">
      <strong>Matière:</strong>
      {{ evaluation.matiere?.nom || 'Non définie' }}
    </div>

    <!-- Date - Maintenant formatée -->
    <div class="date">
      <strong>Date:</strong>
      {{ evaluation.date_evaluation_formatted }}
      <!-- ou utilisez date_evaluation_short pour format court -->
    </div>

    <div class="details">
      <span>Durée: {{ evaluation.duree_minutes }} min</span>
      <span>Coefficient: {{ evaluation.coefficient }}</span>
      <span>Barème: {{ evaluation.bareme }}/20</span>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { getEvaluations } from '@/services/evaluation';

const evaluations = ref([]);

onMounted(async () => {
  try {
    const response = await getEvaluations();
    evaluations.value = response.data;
  } catch (error) {
    console.error('Erreur chargement évaluations:', error);
  }
});
</script>
```

## Endpoints Affectés

Tous ces endpoints retournent maintenant les données enrichies:

1. `GET /api/evaluations` - Liste toutes les évaluations
2. `GET /api/evaluations/{id}` - Détails d'une évaluation
3. `GET /api/evaluations/student/{klassciEtudiantId}` - Évaluations d'un étudiant

## Migration

### Checklist Frontend

- [ ] Vérifier que `evaluation.js` utilise l'instance axios avec authentification
- [ ] Mettre à jour les composants pour utiliser `evaluation.classe` au lieu de chercher la classe séparément
- [ ] Mettre à jour les composants pour utiliser `evaluation.matiere` au lieu de chercher la matière séparément
- [ ] Utiliser `evaluation.date_evaluation_formatted` pour l'affichage des dates
- [ ] Supprimer les appels API redondants pour récupérer classes/matières (maintenant inclus automatiquement)
- [ ] Tester l'authentification sur tous les endpoints d'évaluation

## Performance

Les données de classe et matière sont mises en cache côté backend (10 minutes), ce qui améliore les performances:
- Pas besoin d'appels API supplémentaires depuis le frontend
- Temps de réponse optimisé grâce au cache KLASSCI
- Une seule requête pour obtenir toutes les données nécessaires

## Questions / Support

Si vous rencontrez des problèmes:
1. Vérifiez que le token Sanctum est bien stocké après le login
2. Vérifiez que l'instance axios inclut le token dans les headers
3. Consultez les logs Laravel pour plus de détails sur les erreurs
4. Testez l'authentification avec `/api/auth/me` pour vérifier que le token fonctionne
