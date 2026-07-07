# LOAD_TESTING - Harnais k6 (#372)

Ce dossier documente le harnais de charge versionne dans `tests/load/`.
Il sert a mesurer les etapes du plan scalabilite, pas a inventer des chiffres
locaux. La baseline officielle doit etre tiree sur le VPS cible de #367.

## Prerequis

- PHP et dependances Composer installees.
- Une base de test non production.
- k6 installe sur la machine qui lance le tir.
- Lancer les commandes depuis la racine du depot.

Ne jamais executer les scripts de preparation avec `APP_ENV=production`.
Le script refuse explicitement cet environnement.

## Structure

- `tests/load/lib/` : helpers partages k6.
- `tests/load/scenarios/login.js` : `POST /api/auth/login`.
- `tests/load/scenarios/seances-list.js` : liste des seances.
- `tests/load/scenarios/proxy-klassci-read.js` : lecture proxy Klassci.
- `tests/load/scenarios/notifications.js` : notifications.
- `tests/load/scenarios/dashboard-stats.js` : dashboard/stats.
- `tests/load/scenarios/ramp-up.js` : mix progressif des endpoints.
- `tests/load/setup/prepare-load-test-data.php` : cree fixture et tokens.
- `tests/load/setup/purge-load-test-data.php` : nettoie la fixture.
- `tests/load/stub/klassci-stub-server.php` : stub Klassci loopback.
- `tests/load/monitoring/` : capture CPU/RAM Linux ou Windows.

## Preparation

Demarrer le stub Klassci local dans un terminal dedie :

```bash
php tests/load/stub/klassci-stub-server.php
```

Preparer les donnees de test :

```bash
php tests/load/setup/prepare-load-test-data.php --users=50 --klassci-stub-url=http://127.0.0.1:8089
```

La fixture generee est `tests/load/setup/fixtures/load-test-users.json`.
Elle contient des tokens et identifiants de test; elle ne doit pas etre
versionnee.

## Lancer un scenario

Variables communes :

- `BASE_URL` : URL de l'API cible, defaut `http://127.0.0.1:8000`.
- `HTTP_TIMEOUT` : timeout k6, defaut `30s`.
- `LOAD_TEST_RUN_LABEL` : suffixe de resultat, ex. `baseline-pre-374`.

Exemples :

```bash
k6 run tests/load/scenarios/login.js
k6 run tests/load/scenarios/seances-list.js
k6 run tests/load/scenarios/proxy-klassci-read.js
k6 run tests/load/scenarios/notifications.js
k6 run tests/load/scenarios/dashboard-stats.js
```

Scenario de montee en charge :

```bash
BASE_URL=https://api.example.test LOAD_TEST_RUN_LABEL=baseline-pre-374 \
  k6 run tests/load/scenarios/ramp-up.js
```

Les resultats JSON sont ecrits dans `tests/load/results/`.

## Capture CPU/RAM

Linux/VPS :

```bash
LOAD_TEST_RUN_LABEL=baseline-pre-374 tests/load/monitoring/capture-server-metrics.sh
```

Windows dev :

```powershell
.\tests\load\monitoring\capture-server-metrics.ps1 -RunLabel baseline-pre-374
```

Ces scripts sont independants du tir k6. Les demarrer avant le tir et les
arreter apres.

## Baseline a publier

Pour chaque tir officiel, consigner :

- date, commit, environnement et taille VPS;
- scenario lance et variables k6;
- req/s moyen et max soutenu;
- p50, p95, p99;
- taux d'erreur;
- CPU max/moyen et RAM max/moyenne;
- point de saturation observe;
- conclusion et prochaine action.

## Baseline actuelle

Non publiee. #372 depend de #367 : la baseline doit etre mesuree sur le VPS
reel, avec Redis/queue/runtime cibles et CPU/RAM observables. Un tir local
Windows ou cPanel mutualise ne valide pas les criteres d'acceptation.

## Nettoyage

```bash
php tests/load/setup/purge-load-test-data.php
```
