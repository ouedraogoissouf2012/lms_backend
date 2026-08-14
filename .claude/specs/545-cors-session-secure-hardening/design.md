# Design — #545 [P2][SECURITY] CORS wildcard + SESSION_SECURE_COOKIE sans défaut

## 1. `config/cors.php` (nouveau, versionné)

### Vérification du comportement réel de `fruitcake/php-cors` (pas supposé)

Lu dans `vendor/fruitcake/php-cors/src/CorsService.php:204-229` :
- Si `allowedOrigins` contient exactement **1** origine (et aucun pattern) →
  `isSingleOriginAllowed()` est vrai → `Access-Control-Allow-Origin` est **toujours**
  fixé à cette origine unique, sans comparer au header `Origin` de la requête
  (ligne 211).
- Si `allowedOrigins` contient **plusieurs** origines → mode "dynamique" : le
  header `Origin` de la requête est **reflété** uniquement s'il figure dans la
  liste (`isOriginAllowed()`, ligne 170-189), sinon le header
  `Access-Control-Allow-Origin` n'est simplement pas positionné.

Dans les deux cas, un navigateur qui reçoit un `Access-Control-Allow-Origin` ne
correspondant pas à sa propre origine (ou absent) **bloque la lecture de la
réponse côté JS** — c'est le navigateur qui applique la politique, pas le serveur.
Donc même en mode "origine unique fixe", une requête cross-origin depuis un site
tiers reste bloquée : le tiers reçoit un header CORS qui ne matche jamais son
propre `origin`. Vérifié en lisant le code du package, pas supposé.

### Contenu retenu

```php
<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'https://edu.klassci.com'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
```

- **`CORS_ALLOWED_ORIGINS`** (variable d'env, liste séparée par virgules) :
  même convention que `SANCTUM_STATEFUL_DOMAINS` (`config/sanctum.php:18-23`,
  seul précédent de ce type déjà établi dans le dépôt) — cohérence de style
  (Q6 self-critique : nommage prévisible pour un futur lecteur).
- **Défaut = origine de production confirmée**, pas une chaîne vide : un
  déploiement sans configuration explicite de `CORS_ALLOWED_ORIGINS` obtient une
  origine réelle et fonctionnelle plutôt qu'un `*` — amélioration stricte même en
  l'absence totale de configuration côté ops (défense en profondeur, même
  philosophie que `config/app.php:29` qui suppose "production" par défaut).
- **`array_map('trim', ...)`** : évite un bug silencieux si l'opérateur écrit
  `CORS_ALLOWED_ORIGINS=https://edu.klassci.com, https://autre.tld` (espace après
  la virgule) — un espace de tête ferait échouer la comparaison stricte
  `in_array($origin, $this->allowedOrigins)` du package.
- **`(string)` cast sur `env(...)`** : ajouté après coup pour PHPStan level 9
  (`explode()` exige un `string` strict ; le type inféré de `env()` est
  `bool|string` côté PHPStan, plus large que ce que cette variable retourne en
  pratique pour une URL).
- **Toutes les autres clés inchangées** par rapport au défaut vendor
  (`vendor/laravel/framework/config/cors.php`) — R2 : cette issue ne touche que
  `allowed_origins`.

### Alternatives écartées (Q12 self-critique)

1. **Coder l'origine en dur sans variable d'env** — écarté : un futur changement
   de domaine frontend (staging, renommage) exigerait une modification de code
   versionnée au lieu d'une simple variable d'environnement, alors que
   `SANCTUM_STATEFUL_DOMAINS` établit déjà ce pattern pour un besoin identique
   (domaines frontend autorisés) dans ce même dépôt.
2. **Défaut vide (`env('CORS_ALLOWED_ORIGINS', '')`) forçant une configuration
   explicite** — écarté : contrairement à `.env` (jamais versionné, donc un
   défaut vide serait sans risque), le fichier `config/cors.php` **est** versionné
   et sert de filet pour tout déploiement qui omettrait la variable — un défaut
   vide laisserait `allowed_origins = ['']`, qui ne matche jamais aucune origine
   réelle (violerait le trafic front légitime en silence) au lieu de fournir une
   valeur sûre ET fonctionnelle.

## 2. `config/session.php:177` — défaut sûr conditionné par `APP_ENV`

### Contenu retenu

```php
'secure' => env('SESSION_SECURE_COOKIE', env('APP_ENV', 'production') === 'production'),
```

- Reproduit exactement le raisonnement déjà utilisé par `config/app.php:29`
  (`env('APP_ENV', 'production')` — suppose "production" si `APP_ENV` est
  lui-même absent, jamais l'inverse) : même philosophie fail-safe, cohérence de
  style dans le même dépôt.
- `APP_ENV=production` (ou absent, donc supposé production) + `SESSION_SECURE_COOKIE`
  absent → `secure=true` (R3, corrige la faille de l'issue).
- `APP_ENV=local`/`testing` + `SESSION_SECURE_COOKIE` absent → `secure=false`
  (R4, aucune régression du développement local, qui tourne en HTTP sans
  certificat).
- `SESSION_SECURE_COOKIE` explicitement défini (`true` ou `false`) → toujours
  respecté quel que soit `APP_ENV` (R5, deuxième argument de `env()` n'est
  utilisé QUE si la variable est absente — comportement natif du helper Laravel,
  pas une logique custom à maintenir).

### Alternative écartée (Q12 self-critique)

**Défaut inconditionnel `true`** (`env('SESSION_SECURE_COOKIE', true)`) — écarté :
casserait le développement local par défaut pour tout contributeur qui n'a pas
explicitement `SESSION_SECURE_COOKIE=false` dans son `.env` local (aucune garantie
que ce soit le cas — `.env` local n'est pas versionné, donc pas auditable). Le
correctif conditionné par `APP_ENV` ferme la faille en prod sans imposer une
configuration supplémentaire à chaque développeur.

## 3. Tests

### CORS — Feature test HTTP réel (comportement observé, pas la config brute)

Un test qui lit juste `config('cors.allowed_origins')` prouverait la valeur du
tableau mais pas le comportement HTTP réel (le mapping vers
`Access-Control-Allow-Origin` passe par le package `fruitcake/php-cors`, tiers).
Donc : requête réelle via `$this->withHeaders(['Origin' => ...])->get('/api/...')`
sur une route `api/*` existante, assertion sur le header de réponse.

- Origine autorisée (`https://edu.klassci.com`) → header
  `Access-Control-Allow-Origin` présent et égal à cette origine.
- Origine non autorisée (`https://attaquant.example`) → header
  `Access-Control-Allow-Origin` absent OU différent de `https://attaquant.example`
  (les deux sont un blocage navigateur valide — cf. analyse §1 ; ne pas assumer
  "absent" spécifiquement, ce serait faux en mode origine-unique).

### `SESSION_SECURE_COOKIE` — lecture à froid du fichier de config

`config('session.secure')` est figé au boot de l'application de test (un seul
`APP_ENV` par process PHPUnit) — impossible de le ré-évaluer avec un `APP_ENV`
différent via `config()`. Pattern retenu : `putenv()` + `$_ENV`/`$_SERVER`
manipulés, puis `require config_path('session.php')` à froid (bypass du cache de
config Laravel), pour exécuter réellement le fichier de production avec des
variables d'environnement contrôlées par le test — teste le fichier réel, pas une
réimplémentation de sa logique.

3 cas (R3, R4, R5) + `tearDown()` qui restaure l'environnement pour ne pas polluer
les tests suivants du même process.
