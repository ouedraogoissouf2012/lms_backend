# Requirements — #548 [P2] per_page/limit non bornés + throttle manquant (listings & recherche)

## Contexte vérifié (code réel, recherche exhaustive confirmée le 2026-08-14, HEAD 05634ed2)

Tous les endpoints ci-dessous lisent `per_page`/`limit` **sans aucune borne haute**,
et aucun n'a de `FormRequest` dédié (contrairement aux endpoints de mutation
voisins sur les mêmes contrôleurs, qui en ont systématiquement un — la convention
existe déjà, elle n'a simplement pas été appliquée aux listings).

| # | Endpoint | Lu à (fichier:ligne) | Défaut actuel | FormRequest | Route (fichier:ligne) | Throttle |
|---|---|---|---|---|---|---|
| 1 | `GET /api/evaluations` | `EvaluationListService.php:71` — **aucune pagination, `->get()` brut** | — | Non | `routes/api/evaluation.php:20` | Non |
| 2a | `GET /api/lms/attendance/history` | `AttendanceHistoryQueryService.php:48-50` | 50 | Non | `routes/api/lms.php:136-137` | Non |
| 2b | `GET /api/lms/seances/history` | `LMSSeancesHistoryController.php:50` | 50 | Non | `routes/api/lms.php:95-97` | Non |
| 3a | `GET /notifications` | `NotificationsController.php:57` | 10 | Non | `routes/api/admin.php:65` | Non |
| 3b | `GET /notifications/recent` | `NotificationsController.php:94` | 5 | Non | `routes/api/admin.php:71` | Non |
| 4 | `GET /api/quizzes` | `QuizCrudService.php:110` (via `$request->all()` transmis brut) | 15 | Non | `routes/api/content.php:190` | Non |
| 5 | `GET /api/forum/topics` | `ForumTopicService.php:89` | 15 | Non | `routes/api/content.php:121` | Non |
| 6 | `GET /api/search`, `/search/suggestions`, `/search/history`, `POST /search/history` | `SearchController.php:59` (`limit`, `globalSearch` uniquement) | 5 | Non | `routes/api/admin.php:118-130` | **Aucun — ni sur le groupe, ni global (`api` middleware group), ni named limiter dédié** |

**Cas particulier #1 (`/api/evaluations`)** : contrairement aux 5 autres, il n'y a
**aucune pagination existante** — `->get()` retourne l'intégralité du résultat.
Vérifié dans `lms-frontend/src/services/evaluation.js:13` +
`useTeacherEvaluations.js:106-112` : le frontend fait
`result.data.map((e) => ...)` sur la réponse — **un tableau plat, pas une
enveloppe de pagination**. Convertir en `->paginate()` changerait la forme JSON
de la réponse (`{data: [...], current_page: ..., ...}` au lieu d'un tableau) et
casserait `result.data.map()` en production **immédiatement au déploiement**,
sans qu'aucune PR frontend ne soit prévue dans cette tâche. Décision retenue :
plafonner avec `->limit()`/`take()` (garde la forme de réponse actuelle), pas
`->paginate()`. La pagination complète reste une dette explicitement tracée
(cf. hors périmètre).

**Cas particulier #6 (recherche)** : `globalSearch` fait fan-out sur 5
sous-requêtes indépendantes (`GlobalSearchService.php` — users, lessons,
evaluations, classes, matieres), chacune bornée séparément par le même `$limit`
— un `limit=999999` déclenche donc 5 scans non bornés, pas un seul. `$limit`
alimente aussi la clé de cache (`GlobalSearchService.php:70`) → un `limit`
arbitraire crée aussi des entrées de cache non bornées (vecteur secondaire).

## Périmètre de fichiers (étendu, accord explicite du user 2026-08-14)

Le périmètre initial (`app/Http/Requests/*` + config throttle/RateLimit) est
étendu à :
- `app/Http/Controllers/**` — **uniquement** le type-hint du `FormRequest`
  remplaçant `Illuminate\Http\Request` sur les méthodes listées ci-dessus, et le
  remplacement de `$request->input()/->integer()/->get()` par
  `$formRequest->validated()`. **Aucune logique métier** des services
  (`EvaluationListService`, `QuizCrudService`, `ForumTopicService`, etc.) n'est
  modifiée au-delà du plafond de page.
- `routes/api/admin.php` — attacher `throttle:search` au groupe `search`
  (ligne 118).
- `app/Providers/RateLimitServiceProvider.php` — ajouter le named limiter
  `search`.

## Exigences (format EARS)

**R1 — Chaque endpoint listé reçoit un FormRequest dédié**
QUAND une requête arrive sur l'un des 8 endpoints du tableau ci-dessus, LE
contrôleur DOIT valider `per_page`/`limit` via un `FormRequest` dédié plutôt que
de le lire brut sur `Illuminate\Http\Request`.

**R2 — Bornes cohérentes avec la convention déjà établie**
LA règle de validation DOIT suivre le pattern déjà utilisé par
`FilterLessonsRequest`/`ListFilesRequest`/`MyCoursesRequest`
(`'sometimes','integer','min:1','max:100'`), avec les défauts **actuels**
préservés (pas de changement de comportement par défaut, seulement un plafond) :

| Endpoint | Paramètre | Défaut préservé | Max |
|---|---|---|---|
| `/api/evaluations` | (nouveau plafond, pas de `per_page` existant) | — | 100 (`->limit(100)`, pas de pagination) |
| `/lms/attendance/history` | `per_page` | 50 | 100 |
| `/lms/seances/history` | `per_page` | 50 | 100 |
| `/notifications` | `per_page` | 10 | 100 |
| `/notifications/recent` | `limit` | 5 | 20 (liste courte de widget dashboard) |
| `/api/quizzes` | `per_page` | 15 | 100 |
| `/api/forum/topics` | `per_page` | 15 | 100 |
| `/search` (`globalSearch`) | `limit` | 5 | 20 (fan-out ×5 sous-requêtes — cf. contexte) |

**R3 — Aucun changement de forme de réponse sur `/api/evaluations`**
LA réponse de `GET /api/evaluations` DOIT rester un tableau plat identique à la
forme actuelle (`successResponse($evaluations)` sur une `Collection`), jamais une
enveloppe de pagination Laravel — vérifié contre l'usage réel du frontend
(`useTeacherEvaluations.js:108`, `result.data.map(...)`).

**R4 — Throttle sur `/api/search/*`**
LE groupe de routes `Route::prefix('search')` (`routes/api/admin.php:118`) DOIT
porter un middleware `throttle:search`, adossé à un named rate limiter
`RateLimiter::for('search', ...)` défini dans
`app/Providers/RateLimitServiceProvider.php`, réutilisant le helper privé
`limitForUser()` déjà existant (bypass supradmin + clé par user/IP — même
politique que les limiters `proxy`/`proxy-write` existants).

**R5 — Aucune régression sur les endpoints déjà throttlés**
LES routes qui portent déjà un `throttle:N,1` inline (ex. `quizzes/{quiz}/start`,
`topics` POST/PUT/DELETE) NE DOIVENT PAS être modifiées — hors périmètre de
cette issue, qui cible spécifiquement les listings non bornés et la recherche
non throttlée.

## Hors périmètre (explicitement écarté, avec raison)

- **Pagination complète de `/api/evaluations`** (`->paginate()` au lieu de
  `->limit()`) — casserait le frontend actuel (`result.data.map()`), aucune PR
  frontend coordonnée dans cette tâche. **Dette tracée** : une future issue
  dédiée, avec une PR frontend en parallèle adaptant `useTeacherEvaluations.js`
  à l'enveloppe de pagination, devra migrer cet endpoint pour un vrai
  scroll/pagination plutôt qu'un plafond fixe à 100.
- **Logique métier des services listés** (enrichissement N+1, filtres métier,
  etc.) — hors scope, certains recoupent des issues dédiées déjà en cours dans
  d'autres fenêtres (`#517` mentionné explicitement par l'issue pour
  attendance/seances history).
- **Modifier les routes déjà throttlées** — cf. R5.

## Vérification

Pour chacun des 8 endpoints : un test Feature qui envoie `per_page`/`limit` au
delà du plafond (`101`, `21` selon le cas) et vérifie soit un `422` (validation
stricte) soit un clamp silencieux au max — **décision de design tranchée en
phase design.md**, pas ici (Q11 self-critique : éviter de trancher un choix
d'architecture dans les requirements). Pour `/api/search`, test additionnel :
6 requêtes rapides successives → la 6e reçoit `429`.
