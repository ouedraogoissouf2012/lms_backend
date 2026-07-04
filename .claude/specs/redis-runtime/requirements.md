# Requirements Document

## Introduction

Aujourd'hui, chaque requête HTTP authentifiée sur le backend LMS déclenche plusieurs écritures et lectures MySQL qui n'ont aucune valeur métier :

- `CACHE_STORE=database` (`config/cache.php:18`) — chaque `Cache::remember()` (10 fichiers identifiés à ce jour, dont `app/Services/KlassciProxyService.php` et `app/Services/Klassci/KlassciCacheKeyStrategy.php`) et chaque middleware `throttle:*` (49 occurrences recensées dans `routes/api/*.php`) lit/écrit dans la table `cache`.
- `SESSION_DRIVER=database` (`config/session.php:21`) — lecture et écriture de session à chaque requête stateful.
- `QUEUE_CONNECTION=database` (`config/queue.php:16`) — les workers pollent la table `jobs` par requêtes SQL répétées.
- Sanctum écrit un `UPDATE personal_access_tokens SET last_used_at = ...` à **chaque** requête authentifiée, sans aucun throttle.
- `Cache::tags(["institution_X"])`, exigé par `CONTRIBUTING.md` §E pour l'invalidation multi-tenant sans `Cache::flush()` global, n'est **pas** supporté par le driver `database` — seuls Redis et Memcached le supportent.

Le terrain est prêt côté code : la configuration Redis existe déjà (`config/database.php:130-166`), et `predis/predis` est déclaré (`composer.json:16`).

**Contrainte structurante de cette spec** : la bascule complète en production est bloquée par l'issue #367 (migration vers un VPS), actuellement **ouverte et non démarrée** — la production tourne sur un hébergement mutualisé cPanel qui ne fournit pas Redis. Tant que #367 n'est pas résolue, aucune exigence de cette spec ne peut être validée en conditions de production réelles. Cette spec distingue donc explicitement :

- ce qui peut être spécifié, conçu, codé et validé **dès maintenant**, en local et en CI (Redis y est disponible dès aujourd'hui) ;
- ce qui reste **hors périmètre de validation** tant que #367 n'est pas résolue (déploiement VPS, bascule des variables d'environnement de production, mesure de charge k6 en conditions réelles, constat "zéro requête MySQL" via un outil d'inspection branché sur la production).

## Requirements

### Requirement 1 — Bascule du runtime Redis en local et en CI

**User Story:** En tant que développeur backend, je veux que le cache, la session, la file d'attente et le rate-limiter s'appuient sur Redis en local et en CI, afin de pouvoir concevoir, coder et valider la bascule avant que l'environnement de production (VPS, issue #367) ne soit disponible.

#### Acceptance Criteria

1. WHEN l'application démarre dans un environnement local ou CI où Redis est accessible THEN le système SHALL utiliser `REDIS_CLIENT=phpredis` comme client Redis par défaut.
2. WHEN les variables d'environnement `CACHE_STORE`, `SESSION_DRIVER` et `QUEUE_CONNECTION` sont positionnées sur `redis` en local ou en CI THEN le système SHALL servir le cache, les sessions et les files d'attente exclusivement via Redis, sans requête MySQL additionnelle pour ces trois usages.
3. IF l'extension PHP `phpredis` n'est pas disponible dans l'environnement d'exécution THEN le système SHALL basculer explicitement sur le client `predis` déjà déclaré (`composer.json:16`) sans modification de code applicatif.
4. WHERE la connexion Redis de cache est configurée (`config/database.php`, bloc `redis.cache`) THE système SHALL utiliser une base logique Redis dédiée au cache, distincte de la base par défaut, afin d'isoler les clés de cache des autres usages Redis (queue, session).
5. IF `REDIS_PERSISTENT=true` est positionné THEN le système SHALL réutiliser les connexions Redis persistantes entre requêtes PHP-FPM sans dégradation fonctionnelle observable.

### Requirement 2 — Non-régression des clés de cache existantes

**User Story:** En tant que développeur backend, je veux que le changement de store de cache ne casse aucune clé existante, afin d'éviter des incohérences de données ou des erreurs applicatives au moment de la bascule.

#### Acceptance Criteria

1. WHEN le store de cache passe de `database` à `redis` THEN chaque usage existant de la façade `Cache::` (recensement actuel : 10 fichiers, dont `app/Services/KlassciProxyService.php`, `app/Services/Klassci/KlassciCacheKeyStrategy.php`, `app/Services/AdminAnalytics/*`, `app/Services/File/FileQueryService.php`, `app/Services/Notification/*`, `app/Services/Search/SearchHistoryService.php`, `app/Http/Controllers/API/LMS/LMSEnseignantsController.php`) SHALL continuer à produire les mêmes clés logiques et les mêmes valeurs de retour qu'avec le store `database`.
2. IF une clé de cache générée par `KlassciCacheKeyStrategy` dépend de caractères ou d'une longueur incompatibles avec les contraintes du store Redis THEN le système SHALL normaliser cette clé avant son utilisation, sans perte d'unicité entre institutions ou entre utilisateurs.
3. WHEN une valeur en cache est lue après la bascule alors qu'elle avait été écrite par l'ancien store `database` THEN le système SHALL traiter cette absence de valeur comme un cache miss standard (pas d'erreur), la donnée étant reconstruite normalement au prochain appel.

### Requirement 3 — Rate-limiter porté par le store de cache Redis

**User Story:** En tant que mainteneur de la plateforme, je veux que le rate-limiter applicatif (limiters `proxy` et `proxy-write` de `app/Providers/RateLimitServiceProvider.php`, et les 49 middlewares `throttle:*` recensés dans `routes/api/*.php`) s'appuie sur Redis, afin que la limitation de débit reste atomique et correcte sous forte concurrence multi-process.

#### Acceptance Criteria

1. WHEN `CACHE_STORE=redis` est actif THEN le rate-limiter Laravel SHALL utiliser automatiquement le store de cache par défaut sans configuration additionnelle spécifique au rate-limiting.
2. WHEN une clé de rate-limiting est écrite dans Redis THEN le système SHALL la préfixer par le nom de l'application (`REDIS_PREFIX` / préfixe de store), afin d'éviter toute collision avec d'autres clés applicatives partageant la même instance Redis.
3. WHILE deux processus PHP-FPM traitent des requêtes concurrentes pour le même utilisateur et le même limiter THE système SHALL garantir un comptage atomique du quota consommé, sans double-comptage ni perte d'incrément.
4. IF le quota d'un limiter est dépassé THEN le système SHALL continuer à répondre avec un statut 429 et les en-têtes `Retry-After` / `X-RateLimit-*` existants, sans changement de comportement observable côté client.

### Requirement 4 — Invalidation de cache multi-tenant par tags

**User Story:** En tant que mainteneur de la plateforme, je veux que l'invalidation du cache soit scopée par institution via `Cache::tags(["institution_X"])`, afin qu'aucun `Cache::flush()` global ne puisse plus purger le cache de toutes les institutions à la fois.

#### Acceptance Criteria

1. WHEN un usage de cache concerne une donnée propre à une institution THEN le système SHALL taguer l'entrée de cache correspondante avec un tag identifiant cette institution.
2. WHEN une invalidation de cache est déclenchée pour l'institution A THEN le système SHALL purger uniquement les entrées taguées pour l'institution A, en laissant intactes les entrées de toute autre institution (notamment l'institution B).
3. IF le code contient un appel `Cache::flush()` global existant destiné à une invalidation métier THEN le système SHALL le remplacer par un appel `Cache::tags([...])->flush()` scopé à l'institution concernée.
4. WHEN une entrée de cache n'est rattachée à aucune institution (donnée globale, hors périmètre tenant) THEN le système SHALL la gérer sans tag d'institution, de façon distincte des entrées tenant-scopées.
5. IF une opération d'invalidation par tags est appelée alors que le store de cache actif ne supporte pas les tags (mode dégradé, voir Requirement 6) THEN le système SHALL soit ignorer silencieusement le tag et invalider plus largement, soit lever une erreur explicite documentée — ce comportement SHALL être défini et testé, pas laissé au hasard.

### Requirement 5 — Réduction de la fréquence d'écriture de `last_used_at`

**User Story:** En tant que mainteneur de la plateforme, je veux que la mise à jour de `last_used_at` sur les tokens Sanctum ne soit plus effectuée à chaque requête authentifiée, afin de supprimer une écriture MySQL systématique et inutile par requête.

#### Acceptance Criteria

1. WHEN une requête authentifiée est traitée et que le token utilisé a déjà été marqué comme utilisé il y a moins de 5 minutes THEN le système SHALL s'abstenir d'écrire à nouveau `last_used_at` en base de données.
2. WHEN une requête authentifiée est traitée et que le token utilisé n'a pas été marqué comme utilisé depuis au moins 5 minutes (ou jamais) THEN le système SHALL mettre à jour `last_used_at` en base de données.
3. WHERE cette logique de throttle est implémentée THE système SHALL l'appliquer via le modèle `App\Models\PersonalAccessToken` (`app/Models/PersonalAccessToken.php`), déjà enregistré comme modèle de tokens Sanctum applicatif (`app/Providers/AppServiceProvider.php:45`), sans introduire de second mécanisme parallèle.
4. IF le throttle de `last_used_at` est actif THEN le système SHALL continuer à exposer une valeur de `last_used_at` cohérente et exploitable pour l'audit de sécurité et la détection de tokens inactifs, avec une granularité dégradée d'au maximum 5 minutes.

### Requirement 6 — Mode dégradé documenté et testé (fallback `database`)

**User Story:** En tant qu'opérateur de la plateforme, je veux disposer d'un mode de repli documenté et testé vers `CACHE_STORE=database` en cas d'indisponibilité de Redis, afin de garantir la continuité de service même en l'absence de Redis.

#### Acceptance Criteria

1. WHEN Redis est indisponible ou injoignable THEN le système SHALL pouvoir fonctionner en repositionnant `CACHE_STORE`, `SESSION_DRIVER` et `QUEUE_CONNECTION` sur `database`, sans modification de code applicatif.
2. IF le mode dégradé `database` est actif THEN le système SHALL désactiver ou contourner proprement les invalidations par `Cache::tags()` (non supportées par ce store), sans provoquer d'erreur fatale ni de corruption d'état applicatif.
3. WHERE ce mode dégradé existe THE projet SHALL documenter, dans la documentation opérationnelle du dépôt, la procédure de bascule vers `database` et les limitations fonctionnelles associées (perte de l'isolation d'invalidation par tag, retour aux écritures MySQL par requête).
4. WHEN le mode dégradé `database` est exécuté en local ou en CI THEN la suite de tests automatisés SHALL confirmer que l'application reste fonctionnelle (cache, session, queue, rate-limiter) dans cette configuration.

### Requirement 7 — Non-régression et couverture de tests

**User Story:** En tant que mainteneur de la plateforme, je veux une suite de tests automatisés complète pour la bascule Redis, afin de garantir qu'aucune régression fonctionnelle ou d'isolation multi-tenant n'est introduite.

#### Acceptance Criteria

1. WHEN la suite `php artisan test` est exécutée après implémentation de cette spec THEN elle SHALL passer à 100%, store Redis actif.
2. WHEN la suite `php artisan test` est exécutée avec le mode dégradé `database` actif (Requirement 6) THEN elle SHALL également passer à 100%.
3. WHERE l'invalidation par tags multi-tenant (Requirement 4) est testée THE suite de tests SHALL inclure au minimum un scénario pour l'institution A et un scénario pour l'institution B, démontrant qu'une invalidation sur l'une n'affecte pas le cache de l'autre.
4. WHERE le throttle de `last_used_at` (Requirement 5) est testé THE suite de tests SHALL couvrir le cas nominal (écriture après 5 minutes) et le cas limite (aucune écriture avant 5 minutes).
5. IF une classe publique nouvellement introduite ou modifiée par cette spec expose un comportement observable (ex. le modèle `PersonalAccessToken`, une éventuelle abstraction de cache injectable) THEN elle SHALL disposer d'au moins deux tests : un cas nominal et un cas limite.

### Requirement 8 — Vérification de l'absence de requêtes MySQL superflues (local/CI)

**User Story:** En tant que mainteneur de la plateforme, je veux pouvoir constater, en local ou en CI, qu'une requête GET authentifiée typique ne déclenche plus de requête MySQL pour le cache, la session ou le rate-limiting, afin de valider l'objectif de réduction de charge avant tout déploiement en production.

#### Acceptance Criteria

1. WHEN une requête GET authentifiée typique (ex. liste des séances) est exécutée en local ou en CI avec le store Redis actif THEN le nombre de requêtes SQL exécutées sur les tables `cache`, `sessions` et `jobs` SHALL être nul, mesuré par un mécanisme de comptage de requêtes automatisé (ex. écoute `DB::listen` en test, ou tout outil d'inspection disponible dans l'environnement).
2. WHEN cette même requête est exécutée THEN seules les requêtes SQL portant sur la logique métier de l'endpoint SHALL subsister.
3. IF aucun outil d'inspection de requêtes (type Debugbar/Telescope) n'est installé dans le projet au moment de l'implémentation THEN cette vérification SHALL être réalisée par un test automatisé de comptage de requêtes, sans dépendre de l'ajout d'un outil tiers non prévu par cette spec.

### Requirement 9 — Hors périmètre de validation : bascule et mesure en production réelle

**User Story:** En tant que product owner technique, je veux que la spec distingue explicitement ce qui reste bloqué par l'absence d'environnement de production Redis (issue #367, ouverte, non démarrée), afin de ne pas présenter comme "terminé" ou "validé" ce qui ne peut pas l'être avant la résolution de ce blocage.

#### Acceptance Criteria

1. IF l'issue #367 (migration VPS) n'est pas résolue THEN le déploiement de `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis` et `REDIS_PERSISTENT=true` dans le `.env` de production SHALL rester hors périmètre d'exécution de cette spec.
2. IF l'issue #367 n'est pas résolue THEN l'installation de l'extension `phpredis` sur l'hôte de production SHALL rester hors périmètre d'exécution de cette spec, la production actuelle (hébergement mutualisé cPanel) ne mettant pas Redis à disposition.
3. IF l'issue #367 n'est pas résolue THEN un tir de charge k6 (issue #372) mesurant le gain réel de point de saturation en production, et sa consignation dans `docs/LOAD_TESTING.md`, SHALL rester hors périmètre d'exécution de cette spec.
4. IF l'issue #367 n'est pas résolue THEN le critère "zéro requête MySQL sur un GET authentifié type, vérifié via un outil d'inspection branché sur la production" SHALL rester non validable, et ne SHALL PAS être présenté comme satisfait tant que cette vérification n'a pas été effectuée en conditions réelles.
5. WHERE cette spec est livrée sans que #367 soit résolue THE documentation produite SHALL indiquer explicitement le statut "validé en local/CI uniquement, non validé en production" pour chacun des points ci-dessus.

### Requirement 10 — Contraintes d'architecture non-fonctionnelles

**User Story:** En tant que mainteneur de la plateforme, je veux que la bascule Redis respecte les standards d'architecture du projet, afin de préserver la testabilité, la lisibilité et l'évolutivité du code sur le long terme.

#### Acceptance Criteria

1. WHERE un service métier a besoin d'accéder au cache THE service SHALL recevoir sa dépendance de cache via injection de constructeur, sans appel direct et non testable à la façade `Cache::` ou à un `new` d'implémentation concrète au sein de la logique métier.
2. WHERE un fichier de code métier est créé ou modifié par cette spec THE fichier SHALL rester sous la limite de 300 lignes de code métier fixée par les standards du projet ; tout dépassement SHALL déclencher un refactoring avant livraison.
3. IF une erreur survient lors d'une opération Redis (connexion, timeout, échec d'écriture) THEN le système SHALL ne jamais exposer le message d'erreur brut (`$e->getMessage()`) au client final.
4. IF une information sensible (mot de passe Redis, identifiants de connexion) est nécessaire à la configuration THEN elle SHALL être portée exclusivement par des variables d'environnement, jamais en clair dans le code versionné.
5. WHERE une fonctionnalité de cette spec touche à plusieurs institutions (invalidation par tags, throttle de token) THE conception SHALL garantir l'absence de fuite de données ou de comportement entre institutions, conformément à l'isolation multi-tenant exigée par `CONTRIBUTING.md` §E.
