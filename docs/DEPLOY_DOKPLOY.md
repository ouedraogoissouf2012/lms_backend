# Déployer le LMS sur Dokploy (Contabo)

> Même serveur et même plateforme que Wourri (ADR-0024) : **Dokploy + Traefik + Swarm**.
> Pas de `git clone` manuel, pas de `docker compose` lancé à la main sur le VPS.

---

## i. Le principe à comprendre d'abord

**Dokploy construit l'image depuis Git, sur le serveur.** On crée des ressources dans
l'interface ; Dokploy clone la branche, construit avec le `Dockerfile`, et lance les
conteneurs. Le `docker-compose.prod.yml` du dépôt **est** le mécanisme de déploiement
pour le backend — Dokploy le lit directement.

### Pourquoi un service « Compose » et pas trois « Application »

Le backend a besoin de **trois processus** issus de la **même image** : `web` (Apache),
`worker` (`queue:work`), `scheduler` (`schedule:work`).

| | 3 Applications séparées | 1 service Compose |
|---|---|---|
| Builds de la même image | **3** | **1** |
| Risque de saturation RAM au build | élevé | faible |
| Variables d'environnement | 3 jeux à synchroniser | 1 seul |
| Déploiement | désynchronisé — le worker peut tourner sur l'ancien code | **atomique** |
| Volume `storage/` partagé | complexe | trivial |

C'est exactement le modèle de **`klassci-college-prod`**, déjà en production sur ce
serveur : 7 conteneurs, une image commune à `backend`/`worker`/`beat`, un volume partagé.

**MySQL reste un service « Database » séparé** — on garde ainsi ses sauvegardes
`mysqldump` planifiées, et un redéploiement applicatif ne touche jamais à la base.

---

## ii. Carte du système cible

```
                          Traefik (HTTPS, Let's Encrypt)
                                     │
                          api.africandigitconsulting.com
                                     │
   ┌─────────────────────────────────▼──────────────────────────────────┐
   │  Projet Dokploy : lms                                              │
   │                                                                    │
   │  ┌──────────── service Compose « lms-backend » ─────────────────┐  │
   │  │                                                              │  │
   │  │   web          Apache + PHP 8.3      :80      ← seul exposé   │  │
   │  │   worker       queue:work high,default,low                    │  │
   │  │   scheduler    schedule:work                                  │  │
   │  │                                                              │  │
   │  │   image unique : lms-backend:prod                             │  │
   │  │   volume partagé : lms_storage → /var/www/html/storage/app    │  │
   │  └──────────────────────────────────────────────────────────────┘  │
   │                                     │                              │
   │  ┌──────────── service Database ────▼──────────────────────────┐   │
   │  │   MySQL 8 — interne uniquement, aucun port public           │   │
   │  └─────────────────────────────────────────────────────────────┘   │
   └────────────────────────────────────────────────────────────────────┘
```

Le frontend viendra ensuite comme **Application** distincte dans le même projet,
avec son propre domaine.

---

## iii. Accès

| Quoi | Valeur |
|---|---|
| Serveur | Contabo `vmi3499821` — `serveur.africandigitconsulting.com` |
| Utilisateur | `marcel` (membre du groupe `docker`, donc pas de `sudo`) |
| Clé SSH | `~/.ssh/wourri_deploy_ed25519` — dédiée, sans passphrase |
| Interface Dokploy | `dokploy.africandigitconsulting.com` |

```bash
ssh -i ~/.ssh/wourri_deploy_ed25519 marcel@serveur.africandigitconsulting.com
```

> **Ne pas toucher** aux projets `wourri`, `klassci-college` et `orch-keys`.

---

# Partie A — Première mise en place

## 1. Créer le projet et la base

1. Dokploy → **Projects** → **Create Project** → nom : `lms`
2. Dans le projet : **Create Service** → **Database** → **MySQL 8**
   - Database : `lms`
   - User : `lms`
   - Mot de passe **fort**, à conserver dans un gestionnaire — irrécupérable ensuite
   - **Ne pas** activer d'External Port : la base ne doit pas être joignable publiquement
3. **Deploy**, attendre l'état *running*
4. Ouvrir l'onglet **Internal Credentials** et **noter le nom d'hôte interne**.
   Il a la forme `lms-mysql-XXXXXX` — c'est la valeur de `DB_HOST`, **pas** `mysql`
   ni une adresse IP.

> Une fois la base peuplée, **ne la redéploie plus jamais** sans raison. Les mises à
> jour ne concernent que le service applicatif.

## 2. Créer le service backend

1. **Create Service** → **Compose**
2. Provider : **GitHub** → dépôt `ouedraogoissouf2012/lms_backend` → branche **`lms`**
3. **Compose Type : `Docker Compose`** *(et surtout pas `Stack` : le mode Stack ne
   sait pas construire depuis un Dockerfile)*
4. **Compose Path** : `docker-compose.prod.yml`
5. Onglet **Environment** : coller le bloc de la section **iv** ci-dessous
6. **Deploy**

Le premier build prend **8 à 15 minutes** — LibreOffice et Imagick sont installés dans
l'image pour la conversion des présentations en diapositives.

## 3. Domaine et HTTPS

Onglet **Domains** → **Add Domain** :

| Champ | Valeur |
|---|---|
| Host | `api.africandigitconsulting.com` |
| Service Name | `web` |
| Container Port | `80` |
| HTTPS | activé |
| Certificate | Let's Encrypt |

> L'enregistrement DNS `A` doit **déjà** pointer vers l'IP du serveur, sinon la
> validation du certificat échoue.

## 4. Post-déploiement — obligatoire

Ces deux étapes ne se font pas toutes seules : Dokploy n'a pas de hook post-deploy.

```bash
# trouver le conteneur web
docker ps --format '{{.Names}}' | grep -i 'lms.*web'

# 1. migrations
docker exec <conteneur-web> php artisan migrate --force

# 2. compte supradmin — SEUL seeder à lancer en production
docker exec <conteneur-web> php artisan db:seed --class=SupradminSeeder --force
```

> **Ne jamais lancer `php artisan db:seed` sans `--class`.** Le `DatabaseSeeder`
> appelle `InstitutionSeeder`, `TestUsersSeeder`, `DemoDataSeeder` et
> `EvaluationTestDataSeeder` : il injecterait des établissements et des comptes de
> démonstration dans la base de production.

---

## iv. Variables d'environnement

À coller dans l'onglet **Environment** du service Compose. Les valeurs entre `<>`
sont à remplacer.

```dotenv
APP_NAME="KLASSCI LMS"
APP_ENV=production
APP_DEBUG=false
APP_KEY=<php artisan key:generate --show>
APP_URL=https://api.africandigitconsulting.com

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=<lms-mysql-XXXXXX — onglet Internal Credentials>
DB_PORT=3306
DB_DATABASE=lms
DB_USERNAME=lms
DB_PASSWORD=<mot de passe MySQL>

CACHE_STORE=database
SESSION_DRIVER=database
SESSION_ENCRYPT=true
QUEUE_CONNECTION=database

SANCTUM_STATEFUL_DOMAINS=<domaine du frontend>
SANCTUM_TOKEN_PREFIX=klassci_lms_

SUPRADMIN_EMAIL=<email admin plateforme>
SUPRADMIN_PASSWORD=<mot de passe fort>

KLASSCI_API_URL=<url klassci>
KLASSCI_API_TOKEN=<token klassci>
KLASSCI_SSL_VERIFY=true

CONVERTAPI_SECRET=<optionnel — sinon LibreOffice prend le relais>
TZ=UTC
```

**Générer les deux secrets :**

```bash
php artisan key:generate --show          # APP_KEY
openssl rand -base64 24                  # SUPRADMIN_PASSWORD
```

Points de vigilance :

- `APP_DEBUG=false` — sinon toute erreur expose la trace complète
- `SESSION_ENCRYPT=true` — attendu par `config/session.php`
- `SUPRADMIN_EMAIL` et `SUPRADMIN_PASSWORD` sont **obligatoires** : le seeder lève
  une exception s'ils manquent, et c'est le seul moyen de créer le premier compte
- `CONTAINER_ROLE` **n'est pas** à définir ici : le `docker-compose.prod.yml` l'assigne
  déjà à chaque service

---

## v. Vérifications

```bash
# santé HTTP
curl -sf https://api.africandigitconsulting.com/up && echo OK

# les trois conteneurs tournent
docker ps --filter "name=lms" --format '{{.Names}}\t{{.Status}}'

# le worker consomme bien les TROIS queues
docker inspect $(docker ps -q -f name=lms-worker) --format '{{.Config.Cmd}}'
#   doit contenir : --queue=high,default,low

# la chaîne de conversion est présente
docker exec <conteneur-web> soffice --version
docker exec <conteneur-web> php -r 'var_dump(extension_loaded("imagick"));'

# le volume est bien partagé entre web et worker
docker exec <conteneur-web>    touch /var/www/html/storage/app/_probe
docker exec <conteneur-worker> ls   /var/www/html/storage/app/_probe   # doit exister
docker exec <conteneur-web>    rm   /var/www/html/storage/app/_probe
```

Puis, dans l'application : se connecter avec le compte supradmin, créer une
institution, téléverser une présentation et vérifier que les diapositives sont
générées — c'est le test qui valide toute la chaîne worker + volume + LibreOffice.

---

# Partie B — Exploitation

## vi. Redéployer

1. **Ne pas toucher** au service MySQL.
2. Service `lms-backend` → **Redeploy**. Dokploy récupère la branche `lms` et
   reconstruit (~8-15 min).
3. Migrations **seulement si le schéma a changé** :
   `docker exec <web> php artisan migrate --force`
4. Rejouer les vérifications de la section **v**.

## vii. Les pièges

| Symptôme | Cause | Correction |
|---|---|---|
| Build « No start command » | Build Type resté sur **Nixpacks** | Passer sur **Dockerfile** |
| 404 sur le domaine, déploiement pourtant « successful » | Healthcheck rouge → Traefik ne publie pas la route | `docker logs` du conteneur web ; vérifier `/up` |
| `SQLSTATE… Connection refused` | `DB_HOST` mis à `mysql` ou à une IP | Utiliser le nom complet `lms-mysql-XXXXXX` |
| Conteneur qui démarre sans configuration | Variables absentes du runtime | Le compose charge `env_file: [.env]` — vérifier avec `docker exec <c> env` |
| Diapositives jamais générées | Worker sur la mauvaise queue, ou volume non partagé | Voir les deux vérifications de la section **v** |
| Établissements de démo en production | `db:seed` lancé sans `--class` | Ne jamais omettre `--class=SupradminSeeder` |
| Serveur qui devient injoignable pendant un build | Deux `build:` dans le compose, ou Nixpacks | Un seul `build:` — c'est déjà le cas ici |
| Login impossible après déploiement | Migrations non lancées | `php artisan migrate --force` |

## viii. Sauvegardes et retour arrière

**Sauvegardes** — service MySQL → onglet **Backups** : `mysqldump` planifié vers S3.
Sauvegarder aussi le volume `lms_storage` (Volume Backups) : il contient les
présentations déposées et les diapositives générées.

**Retour arrière** — Dokploy conserve les déploiements précédents : bouton **Rollback**.
Pour une migration problématique : `docker exec <web> php artisan migrate:rollback`.

---

## ix. Références

- `docker-compose.prod.yml` — les trois services et le volume partagé
- `Dockerfile.prod` — image PHP 8.3 + Apache + LibreOffice + Imagick
- `docker/entrypoint.sh` — aiguillage par `CONTAINER_ROLE`
- `docs/ENV_VARIABLES.md` — référence complète des variables
- ADR-0024 (Wourri) — décision de plateforme, mêmes serveur et conventions

Aucun secret ne figure dans ce guide : ils vivent dans l'interface Dokploy et dans
un gestionnaire de mots de passe.
