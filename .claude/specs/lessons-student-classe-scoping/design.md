# Design — Restreindre les leçons d'un étudiant à sa classe (#482)

## 1. Le pont KLASSCI → local (cœur du problème)

```
UserClass.klassci_classe_id  (KLASSCI id, classe de l'étudiant, tenant-scopé)
        │  == classes.klassci_id
        ▼
classes.id                   (local id)
        │  == lessons.classe_id
        ▼
lessons visibles par l'étudiant
```

Résolution en **UNE requête bornée** (pas de N+1) :

```php
// ids KLASSCI des classes de l'étudiant (tenant-scopé), puis pont vers ids locaux.
$klassciClasseIds = UserClass::query()
    ->where('user_id', $user->id)
    ->where('institution_id', $user->institution_id)   // REQ-6
    ->pluck('klassci_classe_id');

$localClasseIds = Classe::query()
    ->whereIn('klassci_id', $klassciClasseIds)
    ->pluck('id');                                      // list<int> local
```

Puis `Lesson::whereIn('classe_id', $localClasseIds)` (REQ-1/2/3 : `NULL` exclu
mécaniquement par `whereIn`).

## 2. Collaborateur dédié (REQ-7, DRY)

Nouveau service `StudentClasseResolver` (pur, DI-friendly, aucune Facade) :

```php
namespace App\Services\Lesson;

final class StudentClasseResolver
{
    /**
     * Ids LOCAUX (classes.id) des classes de l'étudiant, résolus depuis
     * UserClass (source de vérité KLASSCI) via le pont classes.klassci_id.
     * Tenant-scopé. Étudiant sans classe → liste vide (REQ-4, fail-secure).
     *
     * @return list<int>
     */
    public function localClasseIdsFor(User $user): array
    {
        $klassciIds = UserClass::query()
            ->where('user_id', $user->id)
            ->when($user->institution_id !== null,
                fn ($q) => $q->where('institution_id', $user->institution_id))
            ->pluck('klassci_classe_id');

        if ($klassciIds->isEmpty()) {
            return [];
        }

        return Classe::query()
            ->when($user->institution_id !== null,
                fn ($q) => $q->where('institution_id', $user->institution_id))
            ->whereIn('klassci_id', $klassciIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
```

- Injecté par constructeur dans `LessonListService` (pattern MANIFESTE :
  collaborateur, pas de logique en ligne dupliquée).
- `when(institution_id !== null)` : cohérent avec la « défense en profondeur »
  déjà présente dans le service (supradmin cross-tenant = `null`), REQ-6.

## 3. Câblage dans `LessonListService`

### 3.1 Constructeur

```php
public function __construct(
    private readonly LessonProgressService $progressService,
    private readonly StudentClasseResolver $classeResolver, // NEW
) {}
```

### 3.2 `myCourses()` (REQ-1)

Juste après le filtre tenant, avant `->get()` :

```php
if ($user->isStudent()) {
    $query->whereIn('classe_id', $this->classeResolver->localClasseIdsFor($user));
}
```

`whereIn(…, [])` (étudiant sans classe) produit `WHERE 0=1` en SQL Laravel →
**résultat vide** sans exception (REQ-4). ✅

### 3.3 `list()` (REQ-2)

Même ligne, dans la branche `if ($user->isStudent())` déjà existante (celle qui
applique `->published()`), pour appliquer classe **et** publié ensemble :

```php
if ($user->isStudent()) {
    $query->published()
        ->whereIn('classe_id', $this->classeResolver->localClasseIdsFor($user));
} elseif ($request->has('status')) {
    $query->where('status', $request->status);
}
```

Les non-étudiants ne passent pas dans cette branche → **inchangés** (REQ-5).

## 4. Décisions & justifications

| Décision | Pourquoi |
|---|---|
| Collaborateur `StudentClasseResolver` plutôt que méthode privée dupliquée | DRY entre `myCourses()` et `list()` (REQ-7), testable en isolation. |
| Source = `UserClass`, PAS le pivot `classe_etudiant` | `classe_etudiant` n'est jamais écrit en runtime (cartographie) ; `UserClass` est la vérité KLASSCI. |
| `whereIn` (pas de `orWhereNull`) | Exclut `classe_id NULL` mécaniquement (D2/REQ-3). |
| `whereIn([])` pour étudiant sans classe | Fail-secure natif Laravel (`0=1`), pas de branche spéciale (REQ-4). |
| Filtre `isStudent()` uniquement | Préserve strictement le comportement des autres rôles (REQ-5). |
| 2 `pluck` (pas de jointure SQL manuelle) | Lisible, borné, testable ; 2 requêtes constantes, pas de N+1 (Q15). |

## 5. Impact & non-régression

- **Sortie** : pour un étudiant, le résultat rétrécit aux leçons de sa classe.
  Pour les autres rôles : **identique**.
- **Perf** : +2 requêtes constantes par appel étudiant (pluck UserClass + pluck
  Classe), indépendantes du nombre de leçons → pas de N+1.
- **Tests existants** : ceux de `myCourses`/`list` avec étudiant devront fournir
  une `UserClass` + `Classe` pontée pour voir des leçons (sinon vide, ce qui est
  le comportement correct attendu). À ajuster dans l'implémentation.

## 6. Fichiers touchés

| Fichier | Nature |
|---|---|
| `app/Services/Lesson/StudentClasseResolver.php` (NEW) | Résolution ids classes locales de l'étudiant. |
| `app/Services/Lesson/LessonListService.php` | +dépendance, filtre classe dans `myCourses()` et `list()`. |
| `tests/Feature/Lesson/…` (NEW) | Isolation classe A/B, NULL exclu, sans-classe vide, non-régression rôles. |
