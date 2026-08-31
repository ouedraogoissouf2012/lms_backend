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
| `KLASSCI_CONNECT_TIMEOUT` | Timeout de connexion KLASSCI en secondes (défaut 2) |
| `KLASSCI_TIMEOUT` | Timeout total KLASSCI en secondes (défaut 5) |
| `KLASSCI_RETRY_AFTER` | Valeur du header `Retry-After` sur panne KLASSCI retryable |
| `KLASSCI_CIRCUIT_BREAKER_ENABLED` | Active le circuit breaker KLASSCI sans service externe |
| `KLASSCI_CIRCUIT_BREAKER_FAILURES` | Nombre d'échecs 5xx/transport avant ouverture du circuit |
| `KLASSCI_CIRCUIT_BREAKER_COOLDOWN` | Durée d'ouverture du circuit en secondes |
| `KLASSCI_CIRCUIT_BREAKER_WINDOW` | Fenêtre de comptage des échecs en secondes |
| `KLASSCI_SSL_VERIFY` | `true` en prod (cf. `SSLVerificationProvider`) — `false` uniquement en local explicitement |

## 4. Cache / Queue / Session

| Variable | Default | Production | Description |
|---|---|---|---|
| `CACHE_STORE` | `database` ou `array` en test | `redis` | Store cache Laravel 12 ; le rate limiter suit ce store |
| `SESSION_DRIVER` | `array` en test | `redis` | Sessions hors MySQL en prod |
| `SESSION_STORE` | — | vide ou `redis` | Store optionnel utilise par les sessions |
| `QUEUE_CONNECTION` | `sync` ou `database` en test | `redis` | File cible des jobs en prod VPS |
| `REDIS_CLIENT` | `phpredis` | `phpredis` | Extension C attendue sur VPS |
| `REDIS_PERSISTENT` | `false` | `true` | Connexions Redis persistantes |
| `REDIS_HOST` | `127.0.0.1` | host Redis | Si `redis` active |
| `REDIS_PORT` | `6379` | `6379` | Port Redis |
| `REDIS_PASSWORD` | — | obligatoire si configure | Mot de passe Redis genere au provisionnement VPS |
| `REDIS_DB` | `0` | `0` | Connexion Redis default/queue |
| `REDIS_CACHE_DB` | `1` | `1` | Connexion Redis cache/rate limiter |
| `REDIS_PREFIX` | slug app | prefix app unique | Evite collisions entre environnements |

Fallback temporaire si Redis tombe avant remediation infra :
`CACHE_STORE=database`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`.
Ce mode garde la compatibilite fonctionnelle mais ne valide pas les objectifs
perf #374.

## 5. Mail

| Variable | Description |
|---|---|
| `MAIL_MAILER` | `smtp`, `log`, `array` (test) |
| `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD` | Config SMTP standard Laravel |

## 6. Visio (Jitsi / Jibri)

`config/services.php` déclare ces clés depuis #668 et #469 ; **aucune** n'était documentée ici.
Un déploiement neuf part donc avec un secret vide, et le webhook d'enregistrement répond **503
en permanence** sans le moindre indice — d'où cette section.

### 6.1 Accès aux salles

| Variable | Obligatoire | Description |
|---|---|---|
| `JITSI_DOMAIN` | oui | Domaine public du serveur Jitsi (ex. `visio.klassci.com`). Vide ⇒ aucun lien de salle n'est émis. |
| `JITSI_APP_SECRET` | **oui** | Secret HS256 de signature des jetons d'accès. **Doit être identique à `JWT_APP_SECRET` côté prosody.** Une divergence rejette les jetons *sans message exploitable*. |
| `JITSI_APP_ID` | non (`lms-klassci`) | Doit correspondre à `JWT_APP_ID` **et** à `JWT_ACCEPTED_ISSUERS` côté prosody. |
| `JITSI_AUDIENCE` | non (`visio-klassci`) | Doit correspondre à `JWT_ACCEPTED_AUDIENCES` côté prosody. |
| `JITSI_XMPP_DOMAIN` | non (`meet.jitsi`) | Domaine **interne** XMPP, jamais résolu par le navigateur. À laisser au défaut. |
| `JITSI_TOKEN_LIFETIME` | non (`7200`) | Durée de vie d'un jeton d'accès, en secondes. |

### 6.2 Finalisation des enregistrements

| Variable | Obligatoire | Description |
|---|---|---|
| `VISIO_RECORDING_WEBHOOK_SECRET` | **oui** | Secret HMAC-SHA256 du webhook `recording-ready`. **Absent ⇒ 503 sur toute notification.** Doit être identique au secret porté par le script de finalisation côté Jibri. |
| `VISIO_RECORDING_WEBHOOK_MAX_AGE` | non (`300`) | Fenêtre d'acceptation de l'horodatage, en secondes. Sert aussi de durée de rétention du nonce anti-rejeu (+ 60 s). |
| `VISIO_RECORDINGS_ROOT` | non (aucun défaut) | Racine des enregistrements Jibri, montée en **lecture seule** depuis le serveur visio. **Sans défaut délibérément** : absente, la voie Jibri du webhook reste inactive, plutôt que de deviner un chemin serveur. |

> **Pourquoi aucun défaut sur `VISIO_RECORDINGS_ROOT`** : cette racine est concaténée à un
> identifiant de session pour localiser un fichier à importer. Un défaut deviné ferait lire un
> répertoire arbitraire au job d'import. Une fonctionnalité éteinte est préférable à un chemin
> supposé.

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
