# ✅ SOLUTION IMPLÉMENTÉE: Masquage de séances par les étudiants

**Date**: 2025-11-19
**Status**: ✅ IMPLÉMENTÉ ET TESTÉ

---

## 📋 FONCTIONNALITÉ

### Ce qui a été demandé:

> "est il possible de donné la main au etudiant de supprimer les seance dont il veulent"

### Solution choisie:

**Option 1: Masquage individuel** (au lieu de suppression)

**Pourquoi masquage au lieu de suppression ?**
- ✅ Chaque étudiant contrôle sa propre vue
- ✅ Pas de risque de supprimer pour tout le monde
- ✅ Réversible (peut réafficher si besoin)
- ✅ Pas de perte de données
- ✅ Les autres étudiants ne sont pas affectés

---

## 🔧 ARCHITECTURE DE LA SOLUTION

### 1. Nouvelle table `seance_user_hidden`

**Fichier**: `database/migrations/2025_11_19_094151_create_seance_user_hidden_table.php`

**Structure**:
```sql
CREATE TABLE seance_user_hidden (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    seance_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    hidden_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,

    FOREIGN KEY (seance_id) REFERENCES seances(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY (seance_id, user_id),
    INDEX (user_id),
    INDEX (seance_id)
);
```

**Signification**:
- Une ligne = un étudiant a masqué une séance
- `UNIQUE (seance_id, user_id)` = pas de doublons
- `CASCADE` = si séance/user supprimé, le masquage aussi

---

### 2. Modèle `SeanceUserHidden`

**Fichier**: [app/Models/SeanceUserHidden.php](app/Models/SeanceUserHidden.php)

**Méthodes utiles**:

```php
// Vérifier si masquée
SeanceUserHidden::isHidden($seanceId, $userId): bool

// Masquer
SeanceUserHidden::hide($seanceId, $userId): SeanceUserHidden

// Réafficher
SeanceUserHidden::unhide($seanceId, $userId): bool
```

**Exemple d'utilisation**:
```php
$etudiant = auth()->user();
$seance = Seance::find(1);

// Masquer
SeanceUserHidden::hide($seance->id, $etudiant->id);

// Vérifier
if (SeanceUserHidden::isHidden($seance->id, $etudiant->id)) {
    echo "Cette séance est masquée pour cet étudiant";
}

// Réafficher
SeanceUserHidden::unhide($seance->id, $etudiant->id);
```

---

### 3. Endpoints API

**Fichier**: [app/Http/Controllers/API/LMSDataController.php](app/Http/Controllers/API/LMSDataController.php)

#### POST `/api/lms/seances/{seanceId}/hide`

**Masquer une séance pour l'utilisateur connecté**

**Restriction**: Étudiants uniquement

**Requête**:
```bash
curl -X POST http://localhost:8000/api/lms/seances/1/hide \
  -H 'Authorization: Bearer TOKEN_ETUDIANT' \
  -H 'Content-Type: application/json'
```

**Réponse succès (200)**:
```json
{
  "success": true,
  "message": "Séance masquée avec succès",
  "data": {
    "seance_id": 1,
    "hidden_at": "2025-11-19 09:45:12"
  }
}
```

**Réponse erreur (403)** - Si pas étudiant:
```json
{
  "success": false,
  "message": "Seuls les étudiants peuvent masquer des séances"
}
```

---

#### POST `/api/lms/seances/{seanceId}/unhide`

**Réafficher une séance précédemment masquée**

**Restriction**: Étudiants uniquement

**Requête**:
```bash
curl -X POST http://localhost:8000/api/lms/seances/1/unhide \
  -H 'Authorization: Bearer TOKEN_ETUDIANT' \
  -H 'Content-Type: application/json'
```

**Réponse succès (200)**:
```json
{
  "success": true,
  "message": "Séance réaffichée avec succès",
  "data": {
    "seance_id": 1
  }
}
```

**Réponse erreur (404)** - Si pas masquée:
```json
{
  "success": false,
  "message": "La séance n'était pas masquée"
}
```

---

### 4. Routes configurées

**Fichier**: [routes/api.php](routes/api.php)

**Lignes 510-518**:
```php
// Masquer une séance (étudiant uniquement)
Route::post('/seances/{seanceId}/hide', [LMSDataController::class, 'hideSeance'])
    ->name('lms.seances.hide')
    ->middleware('role:etudiant');

// Réafficher une séance (étudiant uniquement)
Route::post('/seances/{seanceId}/unhide', [LMSDataController::class, 'unhideSeance'])
    ->name('lms.seances.unhide')
    ->middleware('role:etudiant');
```

---

### 5. Filtrage automatique dans les APIs

**Modifications dans** [app/Http/Controllers/API/LMSDataController.php](app/Http/Controllers/API/LMSDataController.php):

#### Dans `myClassesSeances()` (ligne 2483-2498)

```php
// IMPORTANT: Pour les étudiants, filtrer aussi les séances archivées et masquées
$seancesClasse = $seancesClasse->filter(function ($seance) use ($user) {
    $localSeance = \App\Models\Seance::where('klassci_seance_id', $seance['id'])->first();

    // Si la séance existe en local mais est archivée, ne pas la montrer
    if ($localSeance && !$localSeance->is_active) {
        return false;
    }

    // Si la séance est masquée par l'étudiant, ne pas la montrer
    if ($localSeance && \App\Models\SeanceUserHidden::isHidden($localSeance->id, $user->id)) {
        return false;
    }

    return true;
});
```

#### Dans `upcomingSeances()` (ligne 848-862)

```php
// IMPORTANT: Pour les étudiants, filtrer les séances archivées et masquées
if ($user && $user->role === 'etudiant') {
    $seancesFiltrees = $seancesFiltrees->filter(function ($seance) use ($user) {
        $localSeance = \App\Models\Seance::where('klassci_seance_id', $seance['id'])->first();

        // Si archivée, ne pas montrer
        if ($localSeance && !$localSeance->is_active) {
            return false;
        }

        // Si masquée par l'étudiant, ne pas montrer
        if ($localSeance && \App\Models\SeanceUserHidden::isHidden($localSeance->id, $user->id)) {
            return false;
        }

        return true;
    });
}
```

---

## 🧪 TESTS EFFECTUÉS

**Script de test**: [test_masquage_seances.php](test_masquage_seances.php)

### Résultats:

```
✅ Table seance_user_hidden: Créée et fonctionnelle
✅ Masquage: Fonctionne correctement
✅ Réaffichage: Fonctionne correctement
✅ Masquage personnel: Vérifié
✅ Protection doublons: Fonctionne
```

### Scénarios testés:

1. **Création de la table** → ✅ Structure correcte
2. **Masquage d'une séance** → ✅ Fonctionne
3. **Vérification personnalisation** → ✅ Autre étudiant non affecté
4. **Réaffichage d'une séance** → ✅ Fonctionne
5. **Protection contre doublons** → ✅ UNIQUE constraint respecté
6. **Cascade DELETE** → ✅ Si séance/user supprimé, masquage aussi

---

## 📊 COMPORTEMENT DÉTAILLÉ

### Qui peut masquer des séances?

| Rôle | Peut masquer? | Raison |
|------|---------------|--------|
| Étudiant | ✅ Oui | Gère sa vue personnelle |
| Enseignant | ❌ Non | Pas d'intérêt fonctionnel |
| Coordinateur | ❌ Non | Doit voir toutes les séances |
| Admin | ❌ Non | Doit voir toutes les séances |

### Qu'est-ce qui est masqué exactement?

**Pour l'étudiant A qui masque la séance 123**:
- ❌ Ne voit plus la séance dans "Mes cours → Séances"
- ❌ Ne voit plus la séance dans "Emploi du temps"
- ❌ Ne voit plus la séance dans "Séances à venir"
- ✅ Peut la réafficher à tout moment

**Pour les autres étudiants B, C, D, etc.**:
- ✅ Voient toujours la séance normalement
- 🔹 Pas affectés par le masquage de A

**Pour les enseignants/coordinateurs**:
- ✅ Voient toujours toutes les séances
- 🔹 Pas de filtre de masquage appliqué

---

## 🎯 CAS D'USAGE

### Cas 1: Étudiant veut nettoyer son emploi du temps

**Problème**: "J'ai 50 séances passées qui encombrent ma vue"

**Solution**:
```javascript
// Frontend: Bouton "Masquer" sur chaque séance passée
onClick = () => {
  fetch(`/api/lms/seances/${seanceId}/hide`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    }
  });

  // Rafraîchir la liste
  fetchSeances();
}
```

**Résultat**: L'étudiant voit seulement ses séances pertinentes

---

### Cas 2: Étudiant a masqué par erreur

**Problème**: "J'ai masqué une séance importante par erreur"

**Solution**:
```javascript
// Frontend: Bouton "Réafficher" dans une section "Séances masquées"
onClick = () => {
  fetch(`/api/lms/seances/${seanceId}/unhide`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    }
  });

  // Rafraîchir la liste
  fetchSeances();
}
```

**Résultat**: La séance réapparaît dans la liste

---

### Cas 3: Enseignant veut savoir si étudiants masquent ses séances

**Problème**: "Je veux voir si mes étudiants masquent mes séances"

**Solution** (à implémenter si souhaité):
```php
// Statistiques pour enseignant
$seance = Seance::find(123);
$hiddenCount = SeanceUserHidden::where('seance_id', $seance->id)->count();

echo "{$hiddenCount} étudiants ont masqué cette séance";
```

---

## 🚀 INTÉGRATION FRONTEND

### 1. Ajouter un bouton "Masquer" sur chaque séance

**Fichier suggéré**: `lms-frontend/src/views/classes/MesCoursSeances.vue`

**Code exemple**:
```vue
<template>
  <div class="seance-card">
    <h3>{{ seance.matiere_nom }}</h3>
    <p>{{ formatDate(seance.date) }}</p>

    <!-- Bouton masquer -->
    <button
      @click="hideSeance(seance.id)"
      class="btn-hide"
      v-if="!seance.is_hidden"
    >
      🙈 Masquer
    </button>

    <!-- Indicateur masquée -->
    <span v-else class="hidden-badge">
      Masquée
    </span>
  </div>
</template>

<script>
export default {
  methods: {
    async hideSeance(seanceId) {
      try {
        const response = await fetch(`/api/lms/seances/${seanceId}/hide`, {
          method: 'POST',
          headers: {
            'Authorization': `Bearer ${this.$store.state.auth.token}`,
            'Content-Type': 'application/json'
          }
        });

        const data = await response.json();

        if (data.success) {
          this.$toast.success('Séance masquée');
          this.fetchSeances(); // Rafraîchir
        }
      } catch (error) {
        this.$toast.error('Erreur lors du masquage');
      }
    }
  }
}
</script>
```

---

### 2. Section "Séances masquées"

**Permettre de visualiser et réafficher**:

```vue
<template>
  <div class="hidden-seances-section">
    <h3>Séances masquées ({{ hiddenSeances.length }})</h3>

    <div v-for="seance in hiddenSeances" :key="seance.id">
      <span>{{ seance.matiere_nom }}</span>
      <button @click="unhideSeance(seance.id)">
        👁️ Réafficher
      </button>
    </div>
  </div>
</template>

<script>
export default {
  data() {
    return {
      hiddenSeances: []
    }
  },

  async mounted() {
    // Récupérer les séances masquées
    // (nécessite un endpoint supplémentaire)
    this.fetchHiddenSeances();
  },

  methods: {
    async unhideSeance(seanceId) {
      const response = await fetch(`/api/lms/seances/${seanceId}/unhide`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${this.$store.state.auth.token}`,
          'Content-Type': 'application/json'
        }
      });

      if (response.ok) {
        this.$toast.success('Séance réaffichée');
        this.fetchHiddenSeances();
      }
    }
  }
}
</script>
```

---

## 📝 FICHIERS MODIFIÉS/CRÉÉS

### Nouveaux fichiers:
1. `database/migrations/2025_11_19_094151_create_seance_user_hidden_table.php`
2. `app/Models/SeanceUserHidden.php`
3. `test_masquage_seances.php`
4. `SOLUTION_MASQUAGE_SEANCES_ETUDIANT.md` (ce fichier)

### Fichiers modifiés:
1. [app/Http/Controllers/API/LMSDataController.php](app/Http/Controllers/API/LMSDataController.php):
   - Ajout `hideSeance()` (ligne 3557-3612)
   - Ajout `unhideSeance()` (ligne 3618-3678)
   - Modification `myClassesSeances()` (ligne 2483-2498)
   - Modification `upcomingSeances()` (ligne 848-862)

2. [routes/api.php](routes/api.php):
   - Ajout routes hide/unhide (ligne 510-518)

---

## ✅ VALIDATION FINALE

**Status global**: ✅ IMPLÉMENTÉ ET FONCTIONNEL

- [x] Migration créée et exécutée
- [x] Modèle SeanceUserHidden créé
- [x] Endpoints API hide/unhide créés
- [x] Routes configurées avec middleware
- [x] Filtrage automatique dans les APIs
- [x] Tests unitaires passent
- [x] Protection contre doublons
- [x] Masquage personnel vérifié

**La solution est prête à être utilisée!** 🎉

---

## 📞 PROCHAINES ÉTAPES SUGGÉRÉES

### Backend (optionnel):

1. **Endpoint pour lister les séances masquées**:
```php
// GET /api/lms/my-hidden-seances
public function getMyHiddenSeances(Request $request): JsonResponse
{
    $user = $request->user();

    $hidden = SeanceUserHidden::where('user_id', $user->id)
        ->with('seance')
        ->get();

    return response()->json([
        'success' => true,
        'data' => $hidden
    ]);
}
```

2. **Statistiques pour enseignants** (optionnel):
```php
// Combien d'étudiants ont masqué cette séance?
$hiddenCount = SeanceUserHidden::where('seance_id', $seanceId)->count();
```

### Frontend:

1. **Bouton "Masquer"** sur chaque carte de séance
2. **Section "Séances masquées"** pour gérer les masquages
3. **Toast notifications** pour confirmer les actions
4. **Badge "Masquée"** sur les séances (si dans une vue admin)

---

**Document créé le**: 2025-11-19
**Auteur**: Claude Code
**Version**: 1.0
