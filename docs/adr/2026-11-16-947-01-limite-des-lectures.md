# ADR-947-01 — Une limite de débit sur toute lecture authentifiée

**Date :** 2026-11-16  
**Statut :** accepté  
**Issue :** #947 · suite : #1134 (le 429 décrit dans la spec)

## Décision

Un limiteur nommé, **`lecture`** (`app/Support/Http/LimiteDesLectures.php`), posé **une fois sur le groupe `api`** (`bootstrap/app.php`, `$middleware->api(append: ['throttle:lecture'])`) :

- il ne compte que les **lectures** (`isMethodSafe`) d'un **compte authentifié**. Une écriture ou un invité rend `Limit::none()` : les écritures gardent leurs `throttle:N,1`, et les routes publiques leurs limiteurs propres ;
- le **supradmin** de plateforme est exempté, comme des autres limiteurs (`RateLimitServiceProvider::limitForUser`) ;
- la clé est **`lecture|famille|user_id`**, un seau par famille et par compte :

| Famille | Chemins | Seuil (par minute, par compte) |
|---|---|---|
| suivi | les sondages du front : `lms/imports/{id}`, `lms/seances/{id}/recording`, `lms/seances/{id}/visio-participants`, `evaluations/{id}/time-status`, `quiz-attempts/{id}/time-remaining`, `notifications/unread-count`, `chapters/uploads/{id}/status` | **60** |
| agrégats | `admin/analytics/*`, `admin/statistics`, `admin/notifications/stats`, `admin/audit-log`, `files/stats`, `teacher/stats`, `dashboard/stats` | **30** |
| navigation | tout le reste | **300** |

Le 429 passe par le rendu global, avec `Retry-After`.

**Sur le groupe, et non route par route** : une route GET nouvelle naît limitée. La priorité de Laravel place `ThrottleRequests` juste après `AuthenticatesRequests` (`Illuminate\Foundation\Http\Kernel::$middlewarePriority`) : le limiteur s'exécute après l'`auth:sanctum` de la route, et connaît le compte.

## La mesure

Mesuré sur `lms` 05e00810 (`route:list --json`, alias `v1`/`v2` exclus) : **97 routes GET authentifiées, 77 sans limite**.

Le trafic de production n'est pas lisible. Le meilleur substitut est le **parcours E2E** du front (`frontend_lms`, workflow E2E, run `43802781668`, 16/11) : deux écoles (créée depuis le LMS, et KLASSCI), 11 comptes, 61 ou 62 écrans chacun.

`resultats.json` n'horodate pas les appels : il donne le statut et le chemin, sans méthode ni heure. D'où une **borne supérieure prouvable**. Chaque écran attend `waitForTimeout(800)` puis `networkidle` (500 ms sans réseau), donc dure au moins 1,3 s, et une minute contient au plus 46 écrans. Le maximum d'appels sur **46 écrans consécutifs** d'un compte majore son maximum réel sur une minute glissante. Comme toutes les méthodes sont comptées, c'est encore une majoration pour les seules lectures.

| Compte (école LMS) | Écrans | Appels | Max sur 46 écrans : navigation / suivi / agrégats |
|---|---|---|---|
| enseignant | 61 | 122 | **94** / **26** / 1 |
| admin | 61 | 146 | 78 / 18 / **8** |
| coordinateur | 61 | 146 | 78 / 18 / 8 |
| étudiant | 61 | 76 | 59 / 16 / 0 |

L'école KLASSCI donne des chiffres voisins (enseignant 94 / 25 / 1 ; admin 76 / 17 / 8).

**Seuil = 2 × le maximum mesuré, avec un plancher de 300 en navigation** :
- navigation : max(2 × 94 = 188, 300) = **300** ;
- suivi : 2 × 26 = 52, d'où **60** ;
- agrégats : 2 × 8 = 16, d'où **30**.

Le sondage le plus rapide du front, le statut d'un import (toutes les 2 s, soit 30/min), tient deux fois dans « suivi ».

## Pourquoi par famille

Un seul seau mettrait en concurrence un sondage et la navigation. Un compte qui suit un import, à 30 lectures par minute, consommerait le budget de ses écrans. Le compteur `notifications/unread-count`, appelé à chaque écran (27 fois sur 61 écrans pour l'admin), relève du suivi pour la même raison.

## Les routes déjà limitées

`proxy` (100), `search` (30), `admin/reports` et les autres gardent leur limite propre, plus stricte. Elles comptent AUSSI dans « navigation » : c'est le plafond général de lecture du compte, pas un second sens. Laravel n'écrase pas les en-têtes `X-RateLimit-*` d'une limite intérieure plus proche de l'épuisement (`ThrottleRequests::getHeaders`).

## La preuve

`tests/Feature/RateLimit/LimiteDesLecturesTest.php` :
- une garde : toute route GET authentifiée passe par `throttle:lecture` (groupes résolus par `gatherRouteMiddleware`) ;
- le seuil et la clé de chaque famille, dans les deux volets d'école ;
- une écriture, un invité et le supradmin ne sont pas comptés ;
- **le parcours E2E complet de l'enseignant** (122 appels, figés dans `parcours-e2e-enseignant.json`), rejoué en une seule minute : aucun seau ne déborde. C'est plus strict que la mesure, qui bornait 46 écrans ;
- au-delà du seuil, un 429 avec `Retry-After`, et les autres familles du compte restent ouvertes.

## Ce qui reste

- **Les seuils viennent du parcours E2E, pas de la production.** Ils sont à réviser sur une mesure du trafic réel (journaux d'accès), si elle devient possible.
- **La spec** ne décrit pas encore le 429 sur les lectures : c'est #1134.
