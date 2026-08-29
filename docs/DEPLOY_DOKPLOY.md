# Déploiement LMS sur Dokploy (Contabo)

Même modèle que Wourri (ADR-0024) : **Dokploy + Traefik + Swarm**.  
Pas de `git clone` manuel, pas de `docker compose` sur le VPS, pas de Nixpacks.

## 0. Répartition

- **Toi** : clics Dokploy (projet, Git, env, Deploy).
- **Moi** : fichiers `Dockerfile.prod` + `docker/entrypoint.sh` (déjà dans le repo).

## 1. Prérequis

- SSH OK : `ssh -i "$env:USERPROFILE\.ssh\wourri_deploy_ed25519" marcel@serveur.africandigitconsulting.com`
- UI Dokploy ouverte (Projects).
- **Ne pas** toucher aux projets `wourri`, `klassci-college`, `orch-keys`.

## 2. Toi — Dokploy UI

### 2.1 Projet

1. **+ Create Project** → nom : `lms`
2. Dans le projet : **Create Service** → **Database** → MySQL 8  
   - Database : `lms`  
   - User : `lms`  
   - Mot de passe fort (à garder).  
   - **Ne pas exposer le port 3306** en public.

### 2.2 Application web

1. **Create Service** → **Application**
2. Source : GitHub `ouedraogoissouf2012/lms_backend`
3. Branch : **`lms`**
4. **Build Type = Dockerfile** (pas Nixpacks — incident Wourri #1)
5. Dockerfile : `Dockerfile.prod`
6. Domain Traefik : ex. `api.africandigitconsulting.com` (HTTPS auto)
7. Env (minimum) :

```
APP_NAME=KLASSCI LMS
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...          # php artisan key:generate --show
APP_URL=https://api.africandigitconsulting.com
CONTAINER_ROLE=web

DB_CONNECTION=mysql
DB_HOST=<hostname interne du service MySQL Dokploy>
DB_PORT=3306
DB_DATABASE=lms
DB_USERNAME=lms
DB_PASSWORD=<le mot de passe MySQL>

QUEUE_CONNECTION=database
CACHE_STORE=database

SANCTUM_STATEFUL_DOMAINS=ton-frontend.tld
SESSION_ENCRYPT=true
KLASSCI_API_URL=https://...
KLASSCI_API_TOKEN=...
KLASSCI_SSL_VERIFY=true
```

8. Volume persistant : `/var/www/html/storage/app`
9. **Deploy**. Build Type Dockerfile, attendre healthy (`/up`).

### 2.3 Worker + scheduler

Deux autres **Application** avec **le même** Git / `Dockerfile.prod` :

| Service    | Env en plus            | Rôle                         |
|------------|------------------------|------------------------------|
| lms-worker | `CONTAINER_ROLE=worker`    | `queue:work`                 |
| lms-scheduler | `CONTAINER_ROLE=scheduler` | `schedule:work` (cron Laravel) |

Mêmes `DB_*` et `APP_KEY` que le web. **Pas de domaine** public.

## 3. Moi / toi en SSH — une fois le 1er deploy vert

Trouver le conteneur web :

```bash
docker ps --format '{{.Names}}' | grep -i lms
```

Migrations (une fois) :

```bash
docker exec $(docker ps -q -f name=lms | head -1) php artisan migrate --force
```

Smoke :

```bash
curl -sf https://api.africandigitconsulting.com/up
```

## 4. Pièges (déjà vus sur Wourri)

- Build Type resté sur Nixpacks → « No start command ». **Dockerfile**.
- `APP_DEBUG=true` en prod → fuite de stack.
- Oublier `migrate` → login 500.
- Recréer le service MySQL → perte de données. On **redeploy** web/worker, pas mysql.
- Ne pas coller `APP_KEY` du repo / de la CI.

## 5. Rollback

Dokploy → application web → **Rollback** vers le déploiement précédent.
