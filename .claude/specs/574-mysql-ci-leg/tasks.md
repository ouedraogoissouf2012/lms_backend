# Tasks — #574 : jambe MySQL 8 dans la matrice PHPUnit de la CI

- [ ] 1. **Matrice** — remplacer `matrix: leg: [redis, database]` par un `include:` seul de trois
      jambes (`db`, `cache`, `db_database`), `fail-fast: false` conservé. Renommer le job en
      `Tests (PHPUnit — <db> / <cache>)`. _Requirements: R1.1, R2.1, R2.2, R3.1, R5.3_

- [ ] 2. **Service MySQL** — ajouter le *service container* `mysql:8.4` (version épinglée),
      `MYSQL_RANDOM_ROOT_PASSWORD` + utilisateur dédié `lms_test` sur la base `lms_testing`,
      healthcheck `mysqladmin ping -h 127.0.0.1 --silent` avec `--health-start-period`.
      _Requirements: R1.2, R3.2, R3.3, R4.1, R4.2_

- [ ] 3. **Environnement piloté par la matrice** — porter `DB_CONNECTION`, `DB_DATABASE`,
      `DB_HOST`/`PORT`/`USERNAME`/`PASSWORD`, `CACHE_STORE`, `SESSION_DRIVER`,
      `QUEUE_CONNECTION`, `REDIS_HOST`/`PORT` dans le bloc `env:` du **job**. Commenter le
      mécanisme (`PhpHandler.php:112-119`) pour qu'un lecteur futur ne « corrige » pas
      `phpunit.xml` par erreur. _Requirements: R1.3, R6.1, R6.2_

- [ ] 4. **`pdo_mysql`** — déclarer l'extension dans `setup-php` (non garantie par défaut ;
      `config/database.php:61` la teste déjà). _Requirements: R6.4_

- [ ] 5. **Readiness MySQL** — étape `if: matrix.db == 'mysql'` : boucle de connexion **PDO avec
      les identifiants applicatifs sur la base attendue**, puis journalisation de `VERSION()` et
      `@@GLOBAL.sql_mode`. _Requirements: R4.3, R5.2_

- [ ] 6. **Étape de migration isolée** — `php artisan migrate --force --no-interaction` sur la
      seule jambe MySQL, pour que l'échec de schéma attendu soit un signal net et nommé plutôt
      que 1 446 tests en erreur. Redondance avec `migrate:fresh` de `RefreshDatabase` assumée et
      commentée. _Requirements: R5.1_

- [ ] 7. **Simplification** — conditionner `Prepare sqlite test database` à `matrix.db ==
      'sqlite'` ; fusionner les deux étapes `Run PHPUnit suite (redis|database)` en **une seule**
      sans `if:`. _Requirements: R6.2_

- [ ] 8. **Documentation** — section « Matrice de tests PHPUnit » dans `docs/SECURITY_CI.md` :
      les trois jambes et leur justification, le mécanisme de surcharge d'environnement, la
      reproduction locale **optionnelle** de la jambe MySQL, les durées avant/après mesurées.
      _Requirements: R7.1, R7.2, R7.3_

- [ ] 9. **Validation locale** — parseur YAML sur `security.yml` ; suite PHPUnit complète en
      SQLite pour prouver la non-régression de R6.2 ; `git diff` vérifié à zéro fichier
      `app/`, `tests/`, `database/`, `phpunit.xml`, `config/`. _Requirements: R6.1_

- [ ] 10. **Revue** — `/thermo-nuclear-code-quality-review` (repli :
      `production-grade-standards` + `/code-review`), puis agents `spec-architect` /
      `spec-reviewer`. Le job `tests` ne touchant aucun fichier `app/`, `spec-security` est sans
      objet sur le diff — le point sécurité (identifiants du conteneur) est traité en Décision 6.

- [ ] 11. **PR** — commit conventionnel `ci(tests): …` (sujet en minuscule, ≤ 70 caractères),
      `Co-Authored-By`, **après accord explicite du user**. `git add -f` des specs (le dossier
      `.claude/specs/` est suivi mais les règles d'ignore du dépôt imposent le `-f`). PR vers
      `lms`, signalée **PRIORITAIRE** à la fenêtre d'orchestration (prérequis de #575 / #580 /
      #583).

## Inventaire des échecs révélés — **mesuré**, pas prédit

La jambe a été exécutée en local contre un `mysql:8.4` configuré à l'identique du service CI
(Docker + `pdo_mysql` disponibles sur le poste). Suite complète, `--exclude-filter
KlassciHttpClientTest` (segfault local préexistant, sans rapport) :

| | SQLite | MySQL 8.4 |
|---|---|---|
| Tests | 1706 | 1706 |
| Erreurs | 0 | **21** |
| Échecs | 1 *(flake local, cf. plus bas)* | **11** |
| Durée | 5 min 52 | 17 min 13 *(Docker Desktop/Windows — non transposable à un runner Linux)* |

### Les 5 causes racines, avec le code d'erreur MySQL

| # | Cause | Tests | Origine | Affectation |
|---|---|---|---|---|
| 1 | **1054 — `Unknown column 'klassci_token'`** | 12 | `app/Services/Seances/Sync/KlassciSeancesSyncService.php:66` — `whereNotNull('klassci_token')`, alors que la colonne a été **supprimée** par `database/migrations/2026_04_27_000001_encrypt_klassci_tokens.php:38` (remplacée par `klassci_token_encrypted`) | **Nouvelle sous-issue** — bug de production actif |
| 2 | **1064 — `ESCAPE '\'`** | 4 | `app/Services/Cache/Purge/DatabaseCachePurger.php:49` — SQL invalide sous MySQL | **Nouvelle sous-issue** — introduit par la PR #600 (mergée le 2026-08-22) |
| 3 | **1265 — `Data truncated for column 'video_provider'`** | 4 | `STRICT_TRANS_TABLES` : valeur hors de la liste `ENUM`. SQLite tronque en silence | **Nouvelle sous-issue** |
| 4 | **3140 — `Invalid JSON text` sur `users.klassci_data`** | 1 | MySQL valide les colonnes `json`, SQLite les stocke en `TEXT` sans contrôle | **Nouvelle sous-issue** |
| 5 | **500 / 404 sur endpoints recherche & dashboard** | 7 | `QueryException` — famille `teacher_id` : `lessons` porte `enseignant_id`, pas `teacher_id` (vérifié `SHOW COLUMNS`) | **#575** (sujet déclaré de la sous-issue) |
| — | 3 tests « risky » (`did not remove its own error handlers`) | 3 | Cascade des erreurs ci-dessus dans `KlassciSeancesSyncServiceTest` | suit la cause 1 |

**Hors périmètre MySQL** (échouent aussi sous SQLite ou dépendent du poste) :
`QueueDrainCommandTest::test_successful_empty_drain_records_worker_heartbeat` — flake d'ordre,
sortie 12 = `Illuminate\Queue\Worker::EXIT_MEMORY_LIMIT` ; **passe isolément**, et la CI est verte
sur `lms@3d2e4c9e`. Les 2 tests `skipped` (imagick, redis) sont attendus hors de leur jambe.

### La divergence de fond, prouvée

```
SQLite  : select "colonne_inexistante" from t  →  renvoie le LITTÉRAL 'colonne_inexistante'
          → `IS NOT NULL` est TOUJOURS vrai → le filtre est silencieusement MORT
MySQL   : select `colonne_inexistante` from t  →  ERROR 1054
```

C'est exactement le mécanisme décrit dans l'énoncé de #574. Le filtre
`whereNotNull('klassci_token')` de la synchronisation des séances **ne filtre rien** sous SQLite
(donc les tests passent) et **fait tomber la synchronisation en production**.

⇒ **Q15 (critère d'invalidation) tranché** : l'hypothèse « la CI ne voit pas les bugs moteur »
n'est pas infirmée, elle est **confirmée par 31 tests**.

## Ce qui reste ouvert après cette PR (à ne pas fermer par erreur)

- **La jambe MySQL sera rouge au premier run** — c'est le résultat attendu (#574 : « s'attendre à
  ce que la première exécution soit rouge »). L'issue #574 ne se ferme qu'une fois les échecs
  révélés triés et traités par leurs sous-issues respectives.
- **Consigner en commentaire de #574** l'inventaire ci-dessus et son affectation, plus la durée
  de CI après. Aucun test ne doit être supprimé ni `skip` sans issue dédiée
  (`CONTRIBUTING.md` §E).
- **Ouvrir 4 sous-issues** pour les causes 1 à 4 : elles ne sont couvertes ni par #575, ni par
  #580, ni par #583. La cause 1 et la cause 2 sont des **bugs de production actifs**.
- **Version du moteur de production non documentée** dans le dépôt — à confirmer par le user
  (MySQL 8.0 ? 8.4 ? MariaDB ?). Si MariaDB, ouvrir une issue de suivi ; le changement se limite
  à la ligne `image:`.
