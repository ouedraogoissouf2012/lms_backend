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
| `KLASSCI_RESYNC_BACKOFF` | Recul d'un compte, en secondes, après l'échec de sa re-synchronisation `auth/me` (défaut 3600) |
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

## 5. Mail (Brevo) et liens à usage unique

Le LMS envoie un seul courriel : le **lien de réinitialisation du mot de passe** d'un compte
d'école autonome (#808). Il part par le mailer SMTP de Laravel, relayé par **Brevo**. Les
valeurs se posent dans Dokploy ; **aucune n'est dans le dépôt**.

| Variable | Valeur attendue | Description |
|---|---|---|
| `MAIL_MAILER` | `smtp` | Défaut `log` : rien ne part (`array` en test) |
| `MAIL_HOST` | `smtp-relay.brevo.com` | Relais SMTP de Brevo |
| `MAIL_PORT` | `587` | STARTTLS |
| `MAIL_USERNAME` | identifiant SMTP Brevo | Brevo → *SMTP & API* → onglet *SMTP* |
| `MAIL_PASSWORD` | **clé SMTP Brevo** (secret) | Même page ; jamais dans le dépôt ni dans un ticket |
| `MAIL_FROM_ADDRESS` | ex. `ne-pas-repondre@<domaine>` | Expéditeur ; son domaine doit être **vérifié chez Brevo** (SPF, DKIM) |
| `MAIL_FROM_NAME` | ex. `KLASSCI LMS` | Nom affiché de l'expéditeur |
| `ACTIVATION_URL_FRONT` | ex. `https://lms.klassci.com` | URL du front, **sans slash final** : les liens mènent à `/activation/{jeton}` |
| `ACTIVATION_VALIDITE_JOURS` | `7` (défaut) | Durée d'un lien d'activation remis hors bande (#803) |
| `ACTIVATION_REINITIALISATION_MINUTES` | `60` (défaut) | Durée d'un lien de réinitialisation |
| `ACTIVATION_REINITIALISATION_INTERVALLE_MINUTES` | `2` (défaut) | Délai minimal entre deux liens pour un même compte |

**Le canal ne s'ouvre que s'il est complet** (`App\Services\Mail\CanalDeCourriel`) : un mailer
autre que `log` ou `array`, un expéditeur autre que `hello@example.com`, l'hôte et
l'identifiant SMTP, et `ACTIVATION_URL_FRONT`. S'il manque une seule de ces valeurs, « mot de
passe oublié » répond `reinitialisation_indisponible`, ne crée aucun jeton et n'envoie rien.
Une fois tout posé, il répond `lien_envoye`, et le courriel part par la file (`high`, conteneur
worker).

**État de la prod au 24/11/2026, 12:48Z** (mesuré en lecture seule, aucun courriel envoyé) : le
canal est **ouvert**, et Brevo **accepte** l'authentification SMTP. Il l'est depuis le 22/11 (#808).

Pour le remesurer sans rien envoyer : la commande ci-dessous vérifie que le canal est complet,
puis ouvre le transport SMTP (connexion, EHLO, authentification), sans message. Une erreur est
affichée masquée : ni jeton, ni adresse IP.

```bash
docker exec -i -u www-data <conteneur-worker> php artisan tinker --execute='
echo app(App\Services\Mail\CanalDeCourriel::class)->estDisponible() ? "canal: ouvert" : "canal: ferme";
try { Illuminate\Support\Facades\Mail::mailer("smtp")->getSymfonyTransport()->start(); echo " / smtp: authentification acceptee"; }
catch (Throwable $e) { echo " / smtp: ", preg_replace(["/\S{24,}/", "/\d{1,3}(\.\d{1,3}){3}/"], "***", $e->getMessage()); }'
```

Dokploy n'écrit les variables dans le `.env` du conteneur **qu'au déploiement** : en enregistrer
une sans redéployer ne change rien.

### 5.1 Brevo n'accepte que les IP autorisées

Brevo refuse l'authentification SMTP depuis une adresse qu'il ne connaît pas. Le refus est
**« 525 5.7.1 Unauthorized IP address »**, à l'authentification, et le lien de réinitialisation
ne part pas. La configuration ne le montre pas : toutes les variables sont justes, et le canal
se dit ouvert.

- **Où la régler** : Brevo → *Sécurité* → *IP autorisées*. On y ajoute l'IP **sortante** du
  serveur qui fait tourner le worker.
- **Quand** : à tout changement de serveur ou d'IP sortante, **avant** de basculer. Sinon, le
  courriel se casse sans autre indice que ce refus.
- **Comment relever l'IP sortante**, sur l'hôte :

  ```bash
  ip -4 route get 192.0.2.1 | awk '{print $7}'
  ```

  `192.0.2.1` est une adresse de documentation (RFC 5737). Le noyau calcule la route par défaut
  et rend l'adresse source, sans rien envoyer ni solliciter de service tiers. Si l'hôte sort par
  une NAT, l'adresse publique est différente : c'est alors celle du tableau de bord de
  l'hébergeur. La commande de vérification ci-dessus confirme le résultat.
- **L'IP ne s'écrit pas dans le dépôt**, ni dans un ticket public. `LaDocNeDitNiIpNiCleTest`
  refuse toute IPv4 publique et toute clé Brevo dans `docs/`.

`ACTIVATION_URL_FRONT` sert aussi à la validation d'une demande d'école (#803) : sans elle,
cette validation refuse en 503 plutôt que de remettre un lien mort.

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

La durée d'un jeton d'accès n'est pas une variable : il expire à la fin d'accès de SA visio
(`FenetreVisio::expirationDuJeton`, plafonnée à 12 h depuis l'entrée). `JITSI_TOKEN_LIFETIME` n'existe plus (#871).

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

### 6.3 Filets de sécurité des enregistrements bloqués

Commande `recordings:fail-stale`, planifiée toutes les 30 minutes. Un enregistrement figé dans un
statut **actif** conserve `active_lock_key` et **empêche tout nouvel enregistrement de la séance**.

| Variable | Obligatoire | Description |
|---|---|---|
| `RECORDINGS_STALE_PROCESSING_MINUTES` | non (`30`) | #514 — délai après `stopped_at` au-delà duquel un enregistrement resté en `Processing` (jamais finalisé faute de webhook) passe à `Failed`. |
| `RECORDINGS_STALE_RECORDING_GRACE_MINUTES` | non (`15`) | #680 — grâce après `seances.visio_ended_at` avant de refermer un enregistrement resté en `Recording`. Laisse à Jibri le temps de finaliser son fichier. |
| `RECORDINGS_MAX_RECORDING_HOURS` | non (`6`) | #680 — plafond absolu de durée d'un enregistrement, filet de dernier recours si la visio elle-même reste bloquée en `active`. |

> **Pourquoi trois seuils et non un seul** : `Processing` se date sur `stopped_at`, alors que
> `Recording` a `stopped_at = NULL` par construction. Surtout, appliquer les 30 minutes de
> `Processing` à un enregistrement en cours **couperait tout cours de plus d'une demi-heure**.
> `RECORDINGS_MAX_RECORDING_HOURS` doit donc rester nettement au-dessus de la durée d'un cours réel.

### 6.4 Rétention des chapitres mis à la corbeille

Commande `chapters:purge`, planifiée quotidiennement à 03:50 — **après** `recordings:purge` (03:45).
Détruit définitivement la ligne **et** ses fichiers, sur les deux disques.

| Variable | Obligatoire | Description |
|---|---|---|
| `CHAPTERS_RETENTION_DAYS` | non (`30`) | #674 — durée pendant laquelle un chapitre supprimé reste récupérable (#689). Au-delà, il est détruit définitivement. |
| `CHAPTERS_PURGE_CHUNK_SIZE` | non (`100`) | Taille des lots parcourus. Chaque chapitre coûte une transaction et deux suppressions de dossier. |

> **Cette durée est une déclaration, pas un réglage de confort.** Conserver au-delà de la durée
> déclarée est une infraction en soi (loi burkinabè 001-2021, qui la sanctionne explicitement).
> Ne renseignez ici que ce que `chapters:purge` sait réellement appliquer — et vérifiez-le par
> `php artisan chapters:purge --dry-run`, qui rend un inventaire sans rien détruire.

> **Pourquoi 30 jours et pas davantage.** La corbeille sert à rattraper une erreur, pas à archiver.
> Et la durée doit rester courte pour une raison qui n'est pas ergonomique : tant qu'un chapitre
> supprimé existe, ses diapositives restent énumérables sans authentification, leurs URL étant
> prédictibles (#598). Allonger cette durée allonge d'autant cette exposition.

> **Pourquoi après `recordings:purge`.** Un chapitre engendré par un enregistrement appartient à la
> rétention visio tant qu'une ligne `seance_recordings` le référence. La laisser passer en premier
> libère au même cycle les chapitres dont l'enregistrement vient d'expirer.

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
