# AJOUT EFFECTIF CLASSE DANS BADGE PARTICIPANTS

**Date:** 2025-10-21
**Demande:** Afficher le nombre d'étudiants attendus dans "Participants (X)" au lieu de 0

---

## PROBLÈME INITIAL

**Badge affichait:** "Participants (0)"
**Attendu:** "Participants (2)" si 2 étudiants inscrits dans la classe

---

## SOLUTION IMPLÉMENTÉE

### 1. Backend - Récupération effectif classe

#### Fichier: `app/Http/Controllers/API/LMSDataController.php`

**Méthode `myTeachingSeances()` (ligne 1553-1605):**

Ajout de la récupération de l'effectif de la classe pour chaque séance:

```php
// Récupérer effectif de la classe
$classeEffectif = 0;
if (isset($seance['classe']['id'])) {
    try {
        $classeDetails = $this->klassciService->requestWithUserToken(
            $klassciToken,
            "classes/{$seance['classe']['id']}",
            'GET'
        );
        $classeEffectif = $classeDetails['data']['classe']['places_occupees'] ?? 0;
    } catch (\Exception $e) {
        $classeEffectif = 0;
    }
}

// Ajouté dans le retour:
'classe' => [
    'id' => $seance['classe']['id'] ?? null,
    'nom' => $seance['classe']['nom'] ?? 'N/A',
    'effectif' => $classeEffectif  // ← NOUVEAU
],
```

**Méthode `matiereDetails()` (ligne 436-479):**

Même logique ajoutée:

```php
// Ajouter effectif de la classe
if (isset($seance['classe']['id'])) {
    try {
        $classeDetails = $this->klassciService->requestWithUserToken(
            $klassciToken,
            "classes/{$seance['classe']['id']}",
            'GET'
        );
        $seanceEnrichie['classe_effectif'] = $classeDetails['data']['classe']['places_occupees'] ?? 0;
    } catch (\Exception $e) {
        $seanceEnrichie['classe_effectif'] = 0;
    }
} else {
    $seanceEnrichie['classe_effectif'] = 0;
}
```

---

### 2. Frontend - Affichage effectif

#### Fichier: `src/components/visio/VisioManager.vue`

**Template (ligne 98):**

```vue
<!-- AVANT -->
Participants ({{ participantCount }})

<!-- APRÈS -->
Participants ({{ expectedParticipants }})
```

**Computed property (ligne 149-154):**

```javascript
/**
 * Nombre d'étudiants attendus pour cette séance
 */
expectedParticipants() {
  return this.seance.classe_effectif || this.seance.classe?.effectif || 0
}
```

**Explication:**
- `seance.classe_effectif` : Pour `matiereDetails` (MatiereDetails.vue)
- `seance.classe?.effectif` : Pour `myTeachingSeances` (TeacherSeances.vue)
- Fallback à `0` si aucune donnée

---

## DONNÉES KLASSCI UTILISÉES

**Endpoint:** `GET /api/classes/{id}`

**Réponse:**
```json
{
  "data": {
    "classe": {
      "id": 1,
      "name": "B2 COM",
      "places_totales": 30,
      "places_occupees": 2,  ← UTILISÉ
      "is_active": true
    },
    "etudiants": [
      { "id": 2, "nom_complet": "Issouf Ouedraogo" },
      { "id": 3, "nom_complet": "MARCEL OUEDRAOGO" }
    ]
  }
}
```

**Champ utilisé:** `places_occupees` = nombre d'étudiants inscrits

---

## TESTS EFFECTUÉS

### Test backend - myTeachingSeances

```bash
php artisan tinker --execute="
\$user = User::where('role', 'enseignant')->first();
\$controller = app(App\Http\Controllers\API\LMSDataController::class);
\$request = new Illuminate\Http\Request();
\$request->setUserResolver(function() use (\$user) { return \$user; });

\$response = \$controller->myTeachingSeances(\$request);
\$data = \$response->getData(true);
\$seance = \$data['data'][0];
echo 'Effectif: ' . \$seance['classe']['effectif'];
"
```

**Résultat:**
```
Nombre séances: 1
Séance ID: 19
Classe: B2 COM
Effectif: 2  ✅
```

---

## RÉSULTAT ATTENDU

### Avant
```
+------------------+
| Participants (0) |  ← Toujours 0
+------------------+
```

### Après
```
+------------------+
| Participants (2) |  ← Nombre réel d'étudiants inscrits
+------------------+
```

---

## PAGES AFFECTÉES

| Page | URL | Composant | Donnée utilisée |
|------|-----|-----------|-----------------|
| Mes Séances (Enseignant) | `/teacher/seances` | TeacherSeances.vue | `seance.classe.effectif` |
| Détails Matière | `/matieres/{id}` | MatiereDetails.vue | `seance.classe_effectif` |

---

## INSTRUCTIONS UTILISATEUR

1. **Rafraîchir le navigateur:**
   ```
   CTRL + SHIFT + R
   ```

2. **Aller sur:**
   - http://localhost:5173/teacher/seances (si enseignant)
   - http://localhost:5173/matieres/1 (si coordinateur/enseignant)

3. **Vérifier:**
   - ✅ Badge "Participants (2)" au lieu de "Participants (0)"
   - ✅ Le nombre correspond à l'effectif de la classe B2 COM

---

## NOTES TECHNIQUES

### Différence avec `participantCount`

| Variable | Signification | Utilisation |
|----------|---------------|-------------|
| `participantCount` | Nombre d'étudiants **connectés en visio** (temps réel) | Pour statistiques en direct |
| `expectedParticipants` | Nombre d'étudiants **inscrits dans la classe** (total) | Pour badge "Participants" |

**Pourquoi ce changement ?**
- Plus utile de voir le **total attendu** que le nombre connecté
- Permet à l'enseignant de savoir combien d'étudiants devraient être présents
- Plus cohérent avec les plateformes e-learning (Moodle, etc.)

---

## OPTIMISATION POSSIBLE (FUTURE)

**Problème:** Chaque séance fait un appel API `GET /classes/{id}`
**Impact:** Si 10 séances de la même classe → 10 appels identiques

**Solution future:** Cache au niveau backend
```php
// Pseudo-code
static $classeCache = [];
if (!isset($classeCache[$classeId])) {
    $classeCache[$classeId] = $this->klassciService->requestWithUserToken(...);
}
$effectif = $classeCache[$classeId]['places_occupees'];
```

**Priorité:** FAIBLE (les enseignants ont rarement plus de 5-6 séances actives)

---

## CONCLUSION

✅ **Backend:** Récupère l'effectif via API KLASSCI `/classes/{id}`
✅ **Frontend:** Affiche l'effectif dans le badge Participants
✅ **Tests:** Effectif correct (2 pour B2 COM)

**Le badge "Participants" affiche maintenant le nombre d'étudiants inscrits.**
