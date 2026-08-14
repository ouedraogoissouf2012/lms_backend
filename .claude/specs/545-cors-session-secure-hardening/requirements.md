# Requirements — #545 [P2][SECURITY] Durcissement config : CORS wildcard + SESSION_SECURE_COOKIE sans défaut

## Contexte vérifié (code réel)

- `config/cors.php` **n'existe pas** dans le dépôt (`ls config/cors.php` → absent).
  `HandleCors` (middleware actif, câblé dans
  `vendor/laravel/framework/.../Configuration/Middleware.php:458`) lit donc le
  défaut du package vendor : `vendor/laravel/framework/config/cors.php` —
  `allowed_origins => ['*']`, `paths => ['api/*', 'sanctum/csrf-cookie']`,
  `supports_credentials => false`.
- `supports_credentials=false` limite déjà la casse pratique (les navigateurs
  n'envoient pas de cookies cross-origin sans `credentials: 'include'` +
  `Access-Control-Allow-Credentials: true`, donc l'auth Bearer actuelle n'est pas
  directement exploitable via ce wildcard) — mais un wildcard non versionné reste
  fragile : chaque `composer install` régénère le défaut vendor, et toute évolution
  future qui activerait `supports_credentials` (ex. passage à l'auth SPA cookie de
  Sanctum, déjà partiellement câblée via `config/sanctum.php` `stateful`/`guard`)
  ouvrirait immédiatement un CSRF cross-origin sur `api/*`.
- `config/session.php:177` : `'secure' => env('SESSION_SECURE_COOKIE')` — sans
  valeur par défaut. Si la variable d'environnement est absente en production
  (oubli d'exploitation, aucun garde-fou dans `docs/DEPLOIEMENT_CPANEL.md` ni
  `GUIDE_DEPLOIEMENT_PRODUCTION.md`), `env()` retourne `null` → le cookie de
  session n'est **pas** marqué `Secure`, transmissible en clair sur HTTP.
- Domaine frontend de production confirmé par l'utilisateur : `https://edu.klassci.com`.
- Précédent déjà établi dans ce dépôt pour un défaut sûr conditionné par
  l'environnement : `config/app.php:29` — `'env' => env('APP_ENV', 'production')`
  (suppose "prod" par défaut, jamais "local" par défaut — fail-safe).
- Précédent déjà établi pour une liste d'origines/domaines configurable par env,
  séparée par virgules : `config/sanctum.php:18-23` (`SANCTUM_STATEFUL_DOMAINS`).

## Exigences (format EARS)

**R1 — `config/cors.php` versionné avec origine explicite**
LE dépôt DOIT contenir un fichier `config/cors.php` versionné (actuellement absent)
qui remplace le défaut vendor `allowed_origins => ['*']` par une liste explicite
d'origines, configurable via une variable d'environnement dédiée, avec pour valeur
par défaut l'origine de production confirmée (`https://edu.klassci.com`) — de sorte
qu'un déploiement sans configuration explicite de cette variable obtienne quand même
une origine réelle plutôt qu'un wildcard.

**R2 — Cohérence avec le reste de la config CORS**
LES autres clés de `config/cors.php` (`paths`, `allowed_methods`,
`allowed_headers`, `supports_credentials`, etc.) DOIVENT rester identiques au
comportement actuel (défaut vendor) — cette issue corrige uniquement
`allowed_origins`, pas le reste de la politique CORS (hors périmètre, cf.
ci-dessous).

**R3 — `SESSION_SECURE_COOKIE` avec défaut sûr conditionné par l'environnement**
QUAND `APP_ENV` vaut `production` (valeur par défaut de `config/app.php:29` si
`APP_ENV` est lui-même absent) ET QUE `SESSION_SECURE_COOKIE` n'est pas défini
explicitement, ALORS `config('session.secure')` DOIT valoir `true`.

**R4 — Non-régression du développement local**
QUAND `APP_ENV` ne vaut PAS `production` (`local`, `testing`, etc.) ET QUE
`SESSION_SECURE_COOKIE` n'est pas défini explicitement, ALORS `config('session.secure')`
DOIT continuer de valoir `false` (comportement actuel, HTTP local sans certificat
nécessaire).

**R5 — L'override explicite reste toujours respecté**
QUAND `SESSION_SECURE_COOKIE` est explicitement défini (`true` ou `false`), peu
importe `APP_ENV`, ALORS `config('session.secure')` DOIT respecter cette valeur
explicite (le défaut sûr ne doit jamais écraser une décision opérationnelle
explicite).

## Hors périmètre (explicitement écarté, avec raison)

- **Activer `supports_credentials => true`** : non demandé par l'issue, changerait
  le modèle d'auth cross-origin (actuellement Bearer, pas cookie SPA) — décision
  architecturale plus large hors scope d'un correctif CORS ponctuel.
- **Restreindre `allowed_methods`/`allowed_headers` au-delà du défaut vendor** :
  non cité par l'issue ; le risque identifié est spécifiquement l'origine
  wildcard, pas la permissivité des méthodes/headers.
- **Ajouter un domaine de staging** : aucun domaine de staging confirmé par
  l'utilisateur ; `CORS_ALLOWED_ORIGINS` reste configurable par env pour couvrir ce
  cas sans le coder en dur.

## Vérification

- Test Feature HTTP : requête `OPTIONS`/`GET` sur une route `api/*` avec header
  `Origin: https://edu.klassci.com` → `Access-Control-Allow-Origin` reflète cette
  origine. Requête avec `Origin: https://attaquant.example` → l'origine n'est PAS
  reflétée (comportement CORS standard : header absent ou différent — vérifié
  empiriquement plutôt que supposé, cf. design.md).
- Test direct sur le fichier `config/session.php` (chargé à froid via
  `require config_path('session.php')` avec `putenv()` manipulé) pour les 3 cas
  R3/R4/R5 — pattern nécessaire car `config()` est figé au boot du framework de
  test et ne peut pas être ré-évalué avec un `APP_ENV` différent dans le même
  process PHPUnit.
