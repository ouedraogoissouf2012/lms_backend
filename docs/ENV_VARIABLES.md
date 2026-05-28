# Variables d'environnement — KLASSCI LMS

> Ce document liste les variables `.env` que l'application lit et qu'un nouveau contributeur doit configurer pour faire tourner le projet.
>
> **Note** : `.env.example` est volontairement listé dans `.gitignore` (cf. lignes 75-76). Ce doc est la référence à la place. Si tu modifies un `config/*.php` pour lire une nouvelle variable, **ajoute-la ici** dans la même PR.

---

## 1. Variables critiques sécurité (NE PAS oublier en prod)

| Variable | Default | Production | Description |
|---|---|---|---|
| `APP_ENV` | `local` | **`production`** | Doit être `production` en prod ; sinon Laravel charge le debug mode |
| `APP_DEBUG` | `true` | **`false`** | Issue #1 : doit être `false` en prod (sinon traces complètes exposées) |
| `APP_KEY` | — | **obligatoire** | `php artisan key:generate` à l'install |
| `APP_URL` | `http://localhost` | `https://lms.klassci.com` | URL canonique de l'API |
| `SANCTUM_TOKEN_EXPIRATION` | `10080` (= 7 jours) | configurable | **Issue #4** : minutes avant expiration des tokens Sanctum. Lu par `config/sanctum.php`. NE JAMAIS mettre à `0` ou vide (= no expiration = token leak permanent) |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost,...` | domaine frontend prod | Domaines autorisés pour Sanctum SPA mode |
| `SANCTUM_TOKEN_PREFIX` | (vide) | recommandé : `klassci_lms_` | Préfixe pour GitHub Secret Scanning ([doc](https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning)) |
| `SESSION_ENCRYPT` | `true` | **`true`** | Cookies session chiffrés (cf. `config/session.php:55`) |
| `SUPRADMIN_EMAIL` | — | obligatoire | Email du compte supradmin (cf. `config/supradmin.php`) |
| `SUPRADMIN_PASSWORD` | — | obligatoire, **rotation régulière** | Mot de passe supradmin. Issue #11 : ne JAMAIS hardcoder, toujours via env. Rotation recommandée trimestrielle |

## 2. Base de données

| Variable | Default test | Production | Description |
|---|---|---|---|
| `DB_CONNECTION` | `sqlite` | `mysql` | Driver PDO (doit correspondre à l'extension PHP installée) |
| `DB_HOST` | — (sqlite) | host fourni | Inutile pour SQLite |
| `DB_PORT` | — (sqlite) | `3306` | Inutile pour SQLite |
| `DB_DATABASE` | `database/database.testing.sqlite` | `klassci_lms_prod` | Chemin du fichier SQLite local OU nom de DB MySQL |
| `DB_USERNAME` | — (sqlite) | user dédié | Pas `root` en prod |
| `DB_PASSWORD` | — | **obligatoire en prod MySQL** | Stocké en vault si possible, jamais en clair en repo |

## 3. KLASSCI (intégration externe)

| Variable | Description |
|---|---|
| `KLASSCI_API_URL` | URL de base de l'API KLASSCI |
| `KLASSCI_API_TIMEOUT` | Timeout en secondes pour les appels sortants |
| `KLASSCI_SSL_VERIFY` | `true` en prod (cf. `SSLVerificationProvider`) — `false` uniquement en local explicitement |

## 4. Cache / Queue / Session

| Variable | Default | Production | Description |
|---|---|---|---|
| `CACHE_DRIVER` | `array` (test) | `redis` recommandé | — |
| `SESSION_DRIVER` | `array` (test) | `redis` ou `database` | — |
| `QUEUE_CONNECTION` | `sync` | `redis` ou `database` | `sync` = exécution synchrone |
| `REDIS_HOST` | `127.0.0.1` | — | Si `redis` activé |

## 5. Mail

| Variable | Description |
|---|---|
| `MAIL_MAILER` | `smtp`, `log`, `array` (test) |
| `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD` | Config SMTP standard Laravel |

---

## Comment vérifier le `.env` prod sans le commiter

Sur cPanel via SSH :

```bash
# Lister les variables sensibles (sans afficher leurs valeurs)
grep -E "^(APP_ENV|APP_DEBUG|SANCTUM_TOKEN_EXPIRATION|SESSION_ENCRYPT|DB_CONNECTION)=" .env | awk -F= '{print $1}'
```

Pour vérifier qu'aucune variable critique n'est manquante, comparer la sortie aux variables marquées **obligatoire** dans le tableau « Variables critiques sécurité » ci-dessus.

---

## Procédure pour ajouter une nouvelle variable

1. Lire la valeur dans un `config/*.php` via `env('VAR_NAME', $default)` (pas d'`env()` direct dans le code applicatif — viole §1.6 DI)
2. **Ajouter une ligne à ce doc** dans la PR
3. Le déployeur (toi) ajoute la variable dans `.env` prod côté cPanel
4. Si la variable est sensible (token, mot de passe, clé) : **rotation initiale immédiate**, ne pas réutiliser la valeur de staging

---

**Version** : 1.0 — créée le 2026-05-15
**Source** : `config/*.php`, `PRODUCTION_STANDARDS.md`, issues #1, #4, #11
