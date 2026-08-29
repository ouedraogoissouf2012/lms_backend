# Requirements — #574 : jambe MySQL 8 dans la matrice PHPUnit de la CI

> Sous-issue de #563 · Sévérité **P1 — ÉLEVÉ** · **Prérequis de la Phase 1** (les sous-issues
> #575 / #580 / #583 s'appuient sur cette jambe pour être *prouvées*).
> Références : `CONTRIBUTING.md` §B (« `php artisan test` = 100 % »), `PRODUCTION_STANDARDS.md`
> §1.3 (tests obligatoires) et Q15 (critère d'invalidation).

## Contexte — vérifié dans le code réel

| Fait | Preuve |
|---|---|
| La suite de tests tourne sur SQLite | `phpunit.xml:49-50` — `<env name="DB_CONNECTION" value="sqlite"/>` |
| Les deux jambes CI actuelles font varier le **cache**, pas le **moteur** | `.github/workflows/security.yml:212-214` — `matrix: leg: [redis, database]` |
| La production tourne sur **MySQL** | `GUIDE_DEPLOIEMENT_PRODUCTION.md:73` — `DB_CONNECTION=mysql` ; `GUIDE_DEPLOIEMENT_PRODUCTION.md:21` — « Base de données : **MySQL** (SQLite = dev local uniquement) » |
| Contournement spécifique MySQL présent en code | `app/Providers/AppServiceProvider.php:105` — `Schema::defaultStringLength(191)` (sans effet sous SQLite) |
| La prod utilise le cache/queue **`database`** | `GUIDE_DEPLOIEMENT_PRODUCTION.md:95-96` — `QUEUE_CONNECTION=database`, `CACHE_STORE=database` |
| La prod se connecte avec un **utilisateur dédié, jamais root** | `GUIDE_DEPLOIEMENT_PRODUCTION.md:77` — `DB_USERNAME=utilisateur_dedie   # jamais root` |
| Laravel impose lui-même le mode strict | `config/database.php:59` — `'strict' => true` sur la connexion `mysql` |
| 234 fichiers de test sur 290 utilisent `RefreshDatabase` | `grep -rl RefreshDatabase tests/` |

**Conséquence** : 1 446 tests verts attestent le comportement du code sous SQLite. Ils
n'attestent **rien** du comportement sous le moteur qui sert les utilisateurs.

## Fait technique structurant (Phase 1 — lu dans `vendor/`)

`vendor/phpunit/phpunit/src/TextUI/Configuration/PhpHandler.php:112-119` :

```php
if ($force || getenv($name) === false) {
    putenv("{$name}={$value}");
}
```

⇒ une `<env>` de `phpunit.xml` **sans `force="true"`** ne surcharge **jamais** une variable
d'environnement déjà exportée par le job GitHub Actions. C'est déjà le mécanisme sur lequel
repose la jambe `redis` (`phpunit.xml:46-47` le documente explicitement).

⇒ **`phpunit.xml` n'a pas à être modifié.** Le pilotage du moteur se fait entièrement depuis le
workflow. Cette exigence est donc formulée en R6 comme une **interdiction**, pas comme une tâche.

## Exigences (format EARS)

### R1 — Une jambe exécutant la suite contre un vrai MySQL 8

- **R1.1** WHEN la CI s'exécute (PR vers `lms` ou push sur `lms`), THE job `tests` SHALL exécuter
  la suite PHPUnit complète contre un serveur **MySQL 8** réel, fourni par un *service container*
  GitHub Actions.
- **R1.2** THE version de l'image MySQL SHALL être **épinglée explicitement** (ex. `mysql:8.4`),
  jamais un tag flottant (`mysql:8`, `mysql:latest`) : un projet à 10 ans ne peut pas voir son
  moteur de référence changer sans commit.
- **R1.3** THE jambe MySQL SHALL utiliser la connexion `mysql` de `config/database.php:46-64`
  **sans modification de cette configuration** — donc avec `'strict' => true`, qui fait appliquer
  par Laravel le `sql_mode` strict (`ONLY_FULL_GROUP_BY`, `STRICT_TRANS_TABLES`, …) **identique à
  celui de la production**, indépendamment de la configuration serveur du conteneur.

### R2 — Conservation de la jambe SQLite

- **R2.1** THE matrice SHALL **conserver** au moins une jambe SQLite : c'est l'environnement de
  développement local documenté (`GUIDE_DEPLOIEMENT_PRODUCTION.md:21`) et sa suppression
  masquerait toute régression propre au poste des développeurs.
- **R2.2** THE couverture existante du runtime Redis (`#374`) SHALL être préservée à l'identique :
  une jambe continue d'exécuter la suite avec `CACHE_STORE`/`SESSION_DRIVER`/`QUEUE_CONNECTION`
  = `redis`.

### R3 — Fidélité à la production sur la jambe MySQL

- **R3.1** THE jambe MySQL SHALL être appariée au driver de cache/session/queue **`database`**,
  parce que c'est la configuration de production (`GUIDE_DEPLOIEMENT_PRODUCTION.md:95-96`) : elle
  valide donc aussi les tables `cache`, `sessions` et `jobs` **sur le moteur réel**.
- **R3.2** THE connexion applicative SHALL se faire avec un **utilisateur MySQL dédié non-root**,
  disposant des seuls privilèges sur la base de test — réplique de l'invariant de production
  (`GUIDE_DEPLOIEMENT_PRODUCTION.md:77`). Cela vérifie en passant que les migrations n'exigent
  aucun privilège d'administration.
- **R3.3** THE mot de passe `root` du conteneur SHALL être **aléatoire et jamais utilisé**
  (`MYSQL_RANDOM_ROOT_PASSWORD`) : aucun identifiant d'administration en clair dans le dépôt
  (`PRODUCTION_STANDARDS.md` §1.2).

### R4 — Démarrage déterministe (readiness)

- **R4.1** THE conteneur MySQL SHALL déclarer un *healthcheck* Docker que le runner attend avant
  d'exécuter la moindre étape.
- **R4.2** THE sonde SHALL forcer une connexion **TCP** (`-h 127.0.0.1`), et non par socket : le
  point d'entrée de l'image officielle démarre un **serveur temporaire en `--skip-networking`**
  pendant l'initialisation, qu'une sonde par socket déclarerait « prêt » à tort.
- **R4.3** THE job SHALL en outre exécuter une étape de readiness applicative qui **prouve** la
  disponibilité : connexion PDO réussie *avec les identifiants de l'application* et *sur la base
  attendue* — pas seulement un port ouvert. (`feedback_5_questions_protocol` : « no errors » ≠
  « comportement vérifié ».)

### R5 — Signal de diagnostic lisible sur une jambe rouge par construction

- **R5.1** WHEN les migrations échouent sous MySQL, THE CI SHALL le signaler dans une **étape
  dédiée et nommée**, distincte de l'exécution de la suite — sinon un échec de schéma se présente
  comme 1 446 tests en erreur et le log devient illisible. C'est la contrepartie assumée du fait
  que cette jambe est **attendue rouge à la première exécution** (énoncé de #574).
- **R5.2** THE log de CI SHALL consigner l'**identité du moteur réellement testé** (`SELECT
  VERSION()` et `@@sql_mode` du serveur) : la preuve de ce qui a été validé doit être dans le run,
  pas dans une affirmation de PR.
- **R5.3** THE matrice SHALL rester en `fail-fast: false` : une jambe rouge ne doit pas annuler
  les autres, sinon on perd la comparaison SQLite ↔ MySQL qui est tout l'intérêt de l'issue.

### R6 — Non-régression et périmètre

- **R6.1** THE correctif SHALL **ne modifier ni `phpunit.xml`, ni `config/database.php`, ni aucun
  fichier de `app/`, `database/` ou `tests/`** — la surcharge par variable d'environnement suffit
  (cf. « Fait technique structurant »). Toute modification de test ou de migration révélée par
  cette jambe relève de #575 / #580 / #583, pas de #574.
- **R6.2** THE jambe SQLite SHALL conserver **exactement** les mêmes variables effectives
  qu'aujourd'hui (`DB_CONNECTION=sqlite`, `DB_DATABASE=database/database.testing.sqlite`,
  `CACHE_STORE`/`SESSION_DRIVER`/`QUEUE_CONNECTION` inchangés) : aucune dérive silencieuse.
- **R6.3** THE dépôt SHALL **ne pas** recevoir de fichier `.env.testing`. Laravel charge
  `.env.{APP_ENV}` en priorité : un `.env.testing` versionné écraserait silencieusement les
  variables du job et rendrait la matrice inopérante. Interdiction explicite, pas un oubli.
- **R6.4** THE extension `pdo_mysql` SHALL être déclarée explicitement dans `setup-php` — elle
  n'est pas garantie par défaut, et `config/database.php:61` la teste déjà via
  `extension_loaded('pdo_mysql')`.

### R7 — Coût et documentation

- **R7.1** THE durée de CI avant / après SHALL être mesurée et consignée (critère de fermeture de
  #574), à partir des durées réelles de jobs GitHub Actions, pas d'une estimation.
- **R7.2** THE fonctionnement de la matrice (quelles jambes, pourquoi celles-là, comment
  reproduire la jambe MySQL en local) SHALL être documenté dans le document de référence du
  workflow, `docs/SECURITY_CI.md`.
- **R7.3** THE procédure de développement local SHALL rester **inchangée** (SQLite) : aucune
  contrainte nouvelle imposée aux développeurs. La reproduction locale de la jambe MySQL est
  documentée comme **optionnelle**.

## Critères de fermeture (issue #574)

- [ ] Jambe MySQL 8 présente dans la matrice, verte en CI *(la première exécution est attendue
      rouge — la fermeture suppose le traitement de #575 / #580 / #583)*.
- [ ] Liste des échecs initiaux et de leur traitement consignée en commentaire de l'issue
      (aucun test supprimé ni `skip` sans issue dédiée — `CONTRIBUTING.md` §E).
- [ ] Durée totale de CI documentée avant / après.
- [ ] Documentation mise à jour (`docs/SECURITY_CI.md`).

## Hors périmètre (renvoyé explicitement)

- **Corriger** un test ou une migration que la jambe MySQL fait tomber → #575 (identifiant
  `teacher_id` entre guillemets doubles), #580 (index unique `(email, institution_id)` nullable),
  #583 (contraintes FK), ou nouvelle sous-issue de #563 si la cause diffère.
- Ajouter une jambe MariaDB. La production est décrite comme « MySQL » ; si le serveur cPanel
  s'avère être MariaDB, la divergence MySQL↔MariaDB (JSON, `CHECK`, séquences) justifie une
  **issue de suivi**, pas un élargissement de #574. **Dette tracée : la version exacte du moteur
  de production n'est documentée nulle part dans le dépôt** — à confirmer par le user.
- Étendre la jambe MySQL au job `docs-sync` (qui exécute `--filter OpenApiSyncTest`) : ce test ne
  touche pas la base (il n'utilise pas `RefreshDatabase`), la jambe n'y apporterait aucun signal.
