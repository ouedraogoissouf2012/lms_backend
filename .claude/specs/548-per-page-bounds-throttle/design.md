# Design — #548 [P2] per_page/limit non bornés + throttle manquant

## Vue d'ensemble

8 endpoints (6 fichiers `FormRequest` nouveaux — `/evaluations` et
`/notifications` couvrent 2 endpoints chacun avec des règles différentes, donc
2 FormRequest chacun), 6 contrôleurs modifiés (type-hint uniquement), 1 provider
(`RateLimitServiceProvider`), 1 route (`routes/api/admin.php`).

## 1. Décision de design tranchée : `422` (validation stricte), pas de clamp silencieux

**Alternatives écartées (Q12 self-critique)**
1. **Clamp silencieux** (`min(100, $perPage)`) — écarté : masque l'erreur côté
   client (un bug frontend qui envoie `per_page=99999` par erreur ne serait
   jamais visible), et diverge du pattern déjà établi par
   `FilterLessonsRequest`/`ListFilesRequest`/`MyCoursesRequest` qui utilisent
   tous une règle `max:100` classique → `422` si dépassée (`ValidationException`
   gérée globalement, JSON `{errors: {per_page: [...]}}`).
2. **Laisser `per_page` non fourni ignorer la validation** (`nullable` sans
   `sometimes`) — écarté : `sometimes` est le bon choix (valide seulement si la
   clé est présente dans la requête), cohérent avec les 3 précédents du dépôt.

**Retenu** : `'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']` (ou
`max:20` selon l'endpoint, cf. tableau R2 des requirements) → `422` si absent
des bornes, exactement le comportement déjà en place pour
lessons/files/my-courses. Cohérence stricte avec la convention existante
(Q6 self-critique).

## 2. FormRequest — un par endpoint, nommage aligné sur la convention lecture existante

| Endpoint | FormRequest (nouveau) | Règle |
|---|---|---|
| `GET /api/evaluations` | `ListEvaluationsRequest` | `per_page` **absent du payload actuel** — nouvelle règle `'limit' => ['sometimes','integer','min:1','max:100']` (nom `limit` pas `per_page`, car pas de pagination réelle, cf. §4) |
| `GET /lms/attendance/history` | `AttendanceHistoryRequest` | `per_page` défaut 50, max 100 |
| `GET /lms/seances/history` | `SeancesHistoryRequest` | `per_page` défaut 50, max 100 |
| `GET /notifications` | `ListNotificationsRequest` | `per_page` défaut 10, max 100 |
| `GET /notifications/recent` | `RecentNotificationsRequest` | `limit` défaut 5, max 20 |
| `GET /api/quizzes` | `ListQuizzesRequest` | `per_page` défaut 15, max 100 |
| `GET /api/forum/topics` | `ListForumTopicsRequest` | `per_page` défaut 15, max 100 |
| `GET /api/search` | `GlobalSearchRequest` | `limit` défaut 5, max 20 ; **fusionne** la règle `query` déjà présente en inline-validate dans `SearchController::globalSearch` (`'query' => 'required|string|min:2|max:100'`) — déplacée dans le FormRequest plutôt que dupliquée à 2 endroits (Q5 self-critique : supprimer la duplication) |

Toutes suivent le patron exact de `FilterLessonsRequest` : `authorize(): bool { return true; }`
(déjà gated par `auth:sanctum` au niveau route), `rules()`, `messages()` avec le
message `"{champ} ne peut pas dépasser {max}."` (cohérence avec
`ListFilesRequest`), commentaire `// anti-DOS (#548)`.

## 3. Contrôleurs — type-hint uniquement, aucune logique métier modifiée

Pour chacun des 6 contrôleurs : remplacer `Illuminate\Http\Request $request` par
le `FormRequest` dédié dans la signature de la méthode, et remplacer la lecture
brute (`$request->input(...)`, `->integer(...)`, `->get(...)`, `$request->all()`)
par `$request->validated('per_page', <défaut>)` (Laravel : `validated()` accepte
une clé + défaut, évite un second appel séparé pour appliquer le défaut).
Exemple (`NotificationsController::index`) :

```php
// Avant
public function index(Request $request): JsonResponse
{
    $perPage = $request->integer('per_page', 10);
    ...
}

// Après
public function index(ListNotificationsRequest $request): JsonResponse
{
    $perPage = (int) $request->validated('per_page', 10);
    ...
}
```

Le service en aval (`NotificationQueryService::paginate()`) ne change pas de
signature — il reçoit toujours un `int`, juste borné en amont. Même schéma pour
les 5 autres.

**`QuizCrudController::index`** — cas particulier : `$this->crud->list($user, $request->all())`
transmet aujourd'hui tout le query bag brut. Remplacé par
`$this->crud->list($user, $request->validated())`, qui ne contient plus que les
clés validées (`per_page` bornée) — **vérifier** dans `QuizCrudService::list()`
si d'autres clés du query bag (filtres) sont lues au-delà de `per_page` ; si
oui, les ajouter aux `rules()` du FormRequest en `sometimes|string` (pas de
nouvelle contrainte de valeur, juste laisser passer ce qui passait déjà) pour ne
rien casser — vérification faite en tâche d'implémentation, pas supposée ici.

## 4. `/api/evaluations` — plafond sans pagination (préserve la forme de réponse)

```php
// EvaluationListService::listForTeacher()
$limit = $filters['limit'] ?? 100;
$evaluations = $query->orderBy('date_evaluation', 'desc')->limit($limit)->get();
```

Paramètre nommé `limit` (pas `per_page`) car il n'y a pas de pagination réelle —
nommer `per_page` laisserait croire à tort qu'une méta de pagination
(`current_page`, `last_page`) accompagne la réponse, ce qui n'est pas le cas
(R3). `EvaluationCrudController::index` passe désormais
`$request->validated('limit', 100)` dans `$filters` au lieu du tableau
`only([...])` actuel — ajout d'une clé, pas de retrait.

## 5. Throttle recherche

`app/Providers/RateLimitServiceProvider.php` — ajouter :

```php
private const SEARCH_PER_MINUTE = 30;

// dans boot() :
RateLimiter::for('search', function (Request $request): Limit {
    return $this->limitForUser($request, self::SEARCH_PER_MINUTE);
});
```

Réutilise `limitForUser()` existant tel quel (bypass supradmin déjà géré, clé
user-id/IP déjà gérée) — aucune duplication (Q5 self-critique).

`routes/api/admin.php:118` :

```php
// Avant
Route::middleware(['auth:sanctum'])->prefix('search')->group(function () {

// Après
Route::middleware(['auth:sanctum', 'throttle:search'])->prefix('search')->group(function () {
```

30/min appliqué aux 4 routes du groupe (2 GET listing + suggestions + history +
POST history) — `saveSearchHistory` (write) reste sous la même limite ; pas de
limite séparée plus stricte pour l'écriture ici car le volume d'écriture
(sauvegarde d'un historique de recherche) est naturellement borné par le volume
de lecture qui le précède (un utilisateur ne peut pas sauvegarder plus de
recherches qu'il n'en fait), contrairement au pattern `proxy`/`proxy-write` qui
sépare des opérations à coût différent.

## 6. Tests

Pattern uniforme pour les 8 endpoints (Feature test, `withHeaders`/auth
utilisateur existant type factory) :
- `per_page`/`limit` absent → comportement par défaut inchangé (non-régression).
- `per_page`/`limit` = max autorisé → `200`.
- `per_page`/`limit` = max+1 → `422`, corps contient la clé en erreur.
- Recherche uniquement : 31 requêtes en boucle serrée → la dernière `429`
  (`RateLimiter::for('search', ...)` testé via `Illuminate\Support\Facades\RateLimiter::clear()`
  en `setUp` pour isoler des autres tests qui partagent la même clé de rate
  limit process-wide — leçon tirée du bug de fuite d'environnement rencontré
  sur #545 : tout état partagé entre tests doit être explicitement réinitialisé,
  jamais supposé propre par défaut).

## 7. Alternative écartée globalement (Q12)

**Un seul `FormRequest` générique paramétrable** (`PaginationRequest` avec des
bornes configurables par constructeur) — écarté : Laravel resolvet les
`FormRequest` par injection de type, pas par instanciation paramétrée
explicite ; un seul type générique empêcherait des bornes différentes par
endpoint (ex. `max:20` pour `recent`/`search` vs `max:100` ailleurs) sans
passer des paramètres via le conteneur (complexité inutile pour 8 cas simples
et déjà couverts par la convention `FilterLessonsRequest`-like existante).
