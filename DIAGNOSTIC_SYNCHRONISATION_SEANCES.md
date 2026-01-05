# Diagnostic : Problème de synchronisation des séances KLASSCI → LMS

## Date : 2025-12-03

## Problème constaté

Vous avez créé une séance sur KLASSCI mais elle n'apparaît pas dans le LMS.

## Cause identifiée

**L'API KLASSCI ne retourne AUCUNE séance dans le champ `seances_programmees`** lors de l'appel à `/api/lms/matieres/{id}`.

### Tests effectués

```
✅ GET /api/lms/matieres → 3 matières retournées
✅ GET /api/lms/matieres/1 → Structure correcte avec clé 'seances_programmees'
❌ matieres/1 → seances_programmees: [] (vide)
❌ matieres/2 → seances_programmees: [] (vide)
❌ matieres/3 → seances_programmees: [] (vide)
❌ GET /api/lms/emploi-temps → Erreur SQL (colonne date_cours inexistante)
❌ GET /api/lms/seances → Route inexistante (404)
```

## Comment le LMS récupère les séances

Le LMS utilise la méthode suivante (workaround documenté dans le code) :

1. Appel à `/api/lms/matieres` pour récupérer toutes les matières
2. Pour chaque matière, appel à `/api/lms/matieres/{id}`
3. Récupération du tableau `data.seances_programmees`
4. Filtrage par date (séances à venir uniquement)
5. Enrichissement avec les données locales (visio, etc.)

**Fichier concerné** : `app/Http/Controllers/API/LMSDataController.php:765` (méthode `upcomingSeances`)

## Actions à effectuer côté KLASSCI

### 1. Vérifier la séance créée

Connectez-vous à KLASSCI et vérifiez que votre séance :

- ✓ Est bien enregistrée dans la base de données
- ✓ A un statut "programmée" (pas "brouillon")
- ✓ Est liée à une matière (champ `matiere_id` non null)
- ✓ Est liée à une classe (champ `classe_id` non null)
- ✓ A une date de programmation (champ `programmation.date` non null)
- ✓ Est active (champ `is_active` = true si cette colonne existe)

### 2. Vérifier l'API KLASSCI

Dans le contrôleur/modèle KLASSCI qui génère la réponse pour `/api/lms/matieres/{id}`, vérifiez :

**A. Relation Eloquent**

```php
// Dans le modèle Matiere (KLASSCI)
public function seancesProgrammees()
{
    return $this->hasMany(SeanceCours::class, 'matiere_id')
        ->whereNotNull('programmation_date')
        ->where('is_active', true) // Si cette colonne existe
        ->orderBy('programmation_date', 'asc');
}
```

**B. Controller**

```php
// Dans le controller qui retourne les détails d'une matière
public function show($id)
{
    $matiere = Matiere::with('seancesProgrammees')->findOrFail($id);

    return response()->json([
        'success' => true,
        'data' => [
            'matiere' => $matiere,
            // ...
            'seances_programmees' => $matiere->seancesProgrammees,
            // ...
        ]
    ]);
}
```

### 3. Vérifier la structure de la table seances

Sur KLASSCI, vérifiez la structure de la table des séances :

```sql
-- Exemple de requête à exécuter
SELECT * FROM esbtp_seance_cours
WHERE matiere_id IN (1, 2, 3)
ORDER BY created_at DESC
LIMIT 5;
```

Vérifiez que :
- La table s'appelle bien `esbtp_seance_cours` (ou autre nom)
- La colonne de programmation existe (date_programmation, programmation_date, etc.)
- Votre séance nouvellement créée apparaît dans cette table

### 4. Déboguer l'endpoint KLASSCI

Ajoutez des logs dans le controller KLASSCI :

```php
public function show($id)
{
    $matiere = Matiere::with('seancesProgrammees')->findOrFail($id);

    Log::info('Matière ' . $id, [
        'seances_count' => $matiere->seancesProgrammees->count(),
        'seances' => $matiere->seancesProgrammees->toArray()
    ]);

    return response()->json([
        'success' => true,
        'data' => [
            // ...
            'seances_programmees' => $matiere->seancesProgrammees,
        ]
    ]);
}
```

## Alternative : Endpoint dédié pour les séances

Si le workaround via `/matieres/{id}` ne fonctionne pas, vous pourriez créer un endpoint dédié :

```php
// KLASSCI: routes/api.php
Route::get('/lms/seances/enseignant/{enseignant_id}', [SeanceController::class, 'byEnseignant']);

// KLASSCI: SeanceController.php
public function byEnseignant($enseignantId)
{
    $seances = SeanceCours::where('enseignant_id', $enseignantId)
        ->whereNotNull('programmation_date')
        ->where('programmation_date', '>=', now())
        ->with(['matiere', 'classe'])
        ->orderBy('programmation_date', 'asc')
        ->get();

    return response()->json([
        'success' => true,
        'data' => $seances
    ]);
}
```

Ensuite, modifier le LMS pour utiliser ce nouvel endpoint à la place du workaround `/matieres/{id}`.

## Tests de validation

Une fois les corrections effectuées côté KLASSCI, relancer ces scripts :

```bash
# 1. Vérifier que les séances apparaissent dans l'API
php test_klassci_matieres_seances.php

# 2. Vérifier que l'endpoint LMS fonctionne
php diagnostic_seances_sync.php
```

Les séances devraient maintenant apparaître dans :
- Le résultat de `test_klassci_matieres_seances.php` (section seances_programmees)
- Le résultat de `diagnostic_seances_sync.php` (section "Total séances à venir")

## Contact

Si le problème persiste après ces vérifications, il faudra :
1. Vérifier les logs de KLASSCI (`storage/logs/laravel.log`)
2. Vérifier la structure exacte de la table des séances dans la BDD KLASSCI
3. Déboguer directement l'endpoint `/api/lms/matieres/{id}` côté KLASSCI
