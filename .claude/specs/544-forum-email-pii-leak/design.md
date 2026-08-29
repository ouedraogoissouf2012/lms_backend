# Design — #544 [P2][SECURITY] PII : email d'autrui exposé via GET /forum/topics

> **Révisé après audit `spec-security` (finding CRITICAL)** : le design initial
> ne couvrait que les 5 sites cités par l'issue (des `select` partiels avec
> `email` explicite). L'audit a trouvé 3 `fresh(['user'])` **sans aucune
> restriction de colonnes** (`ForumTopicService::update()`,
> `ForumPostService::update()`, `ForumPostService::markAsSolution()`),
> confirmés exploitables cross-utilisateur (le propriétaire d'un topic peut
> marquer la réponse d'un AUTRE étudiant comme solution et recevoir son email).
> Corrigés avec le même pattern. Le design ci-dessous couvre les 8 sites au
> total et introduit une constante partagée pour éviter qu'un 9ᵉ site oublie
> l'exclusion.

## Changement (2 fichiers, 8 occurrences)

Une constante `private const AUTHOR_COLUMNS = 'user:id,name,role';` par classe
de service, référencée à chaque `with()`/`load()`/`fresh()` — une seule source
de vérité au lieu de 8 chaînes littérales dupliquées (finding MEDIUM de l'audit
`spec-architect` : un futur contributeur qui ré-ajoute `,email` à un seul
endroit ne casse pas les 7 autres si tous pointent vers la même constante).

### `app/Services/Forum/ForumTopicService.php`

| Ligne | Avant | Après |
|---|---|---|
| 46 | `'user:id,name,email,role'` | `self::AUTHOR_COLUMNS` (`list()`) |
| 107 | `'user:id,name,email,role'` | `self::AUTHOR_COLUMNS` (`create()`) |
| 120 | `'user:id,name,email,role'` | `self::AUTHOR_COLUMNS` (`showWithPosts()`, topic) |
| 124 | `['user:id,name,email,role', 'replies.user:id,name,email,role']` | `[self::AUTHOR_COLUMNS, 'replies.' . self::AUTHOR_COLUMNS]` |
| ~150 | `$topic->fresh(['user'])` **(non restreint)** | `$topic->fresh([self::AUTHOR_COLUMNS])` (`update()`) |

### `app/Services/Forum/ForumPostService.php`

| Ligne | Avant | Après |
|---|---|---|
| 59 | `'user:id,name,email,role'` | `self::AUTHOR_COLUMNS` (`create()`) |
| ~122 | `$post->fresh(['user'])` **(non restreint)** | `$post->fresh([self::AUTHOR_COLUMNS])` (`update()`) |
| ~173 | `$post->fresh(['user'])` **(non restreint)** | `$post->fresh([self::AUTHOR_COLUMNS])` (`markAsSolution()`) |

Aucun autre changement — ni contrôleur, ni route, ni FormRequest.

## Pourquoi pas de couche API Resource (alternative écartée, Q12 self-critique)

1. **`ResourceCollection` sur `index` (paginé)** — écarté : Laravel sérialise
   nativement une `Resource::collection()` bâtie sur un paginator avec
   l'enveloppe `{data: [...], links: {...}, meta: {current_page, ...}}`,
   différente de la sérialisation native du paginator brut
   (`{current_page, data: [...], ...}`) que `successResponse()` transmet
   aujourd'hui. Le frontend (`Forum.vue:147-148`) fait
   `Array.isArray(response.data.data) ? ... : []` — sur la nouvelle forme,
   `response.data.data` serait l'objet `{data, links, meta}`, pas un tableau
   → `Array.isArray()` retournerait `false` → liste vidée en silence.
   Contournable via `$paginator->through(fn ($topic) => TopicResource::make($topic))`
   (préserve la forme du paginator), mais alors la complexité d'une classe
   Resource entière est ajoutée pour un résultat strictement équivalent à
   « ne pas sélectionner la colonne » — sans bénéfice.
2. **Masquage conditionnel par rôle** (visible pour soi-même/admin) — écarté :
   non demandé par l'issue, aucun besoin produit identifié (aucune UI
   n'affiche l'email d'un autre auteur de topic/post).

## Non-régression (R2)

- Le seul changement de forme JSON est la disparition de la clé `user.email`
  (et `replies.*.user.email`) — `id`, `name`, `role` inchangés.
- Aucune requête SQL supplémentaire, aucun N+1 introduit : c'est le MÊME
  eager-load, juste une colonne en moins dans le `SELECT` partiel.
- Vérifié qu'aucun code (backend ou frontend) ne lit `.email` sur ces
  relations avant d'écrire ce design (cf. requirements.md, section
  « Vérifications effectuées »).

## Tests

Feature tests ciblés sur les 4 endpoints qui exposent un `user` de topic/post :
`index`, `show`, `store` (topic), `storePost`. Pattern : créer un topic/post
avec un auteur dont l'email est une valeur distinctive, appeler l'endpoint en
tant qu'un AUTRE utilisateur authentifié du même tenant, asserter que la
réponse JSON ne contient PAS cette adresse email (recherche substring dans le
corps brut — plus robuste qu'un chemin de clé JSON qui dépendrait de la forme
exacte de pagination) et QUE `id`/`name`/`role` de l'auteur sont bien présents.
