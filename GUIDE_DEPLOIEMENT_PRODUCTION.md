# Guide de déploiement en production — LMS KLASSCI

> **Rôle de ce document** : première installation du backend sur un environnement de
> production **vierge**. Pour un déploiement de routine sur le cPanel existant
> (`git pull` + migrations), suivre le runbook
> [`docs/DEPLOIEMENT_CPANEL.md`](docs/DEPLOIEMENT_CPANEL.md) — c'est lui qui fait foi
> pour la prod actuelle.
>
> Chaque commande de ce guide a été vérifiée contre le repo réel
> (`composer.json`, `.cpanel.yml`, `routes/`, `config/`). Si une commande échoue,
> ouvrir une issue `documentation` plutôt que d'improviser.

---

## 1. Prérequis serveur

| Composant | Version / valeur | Preuve |
|---|---|---|
| PHP | **8.2 minimum** (la CI teste en 8.3) | `composer.json:9` → `"php": "^8.2"` |
| Composer | 2.x | `composer.lock` généré en Composer 2 |
| Base de données | **MySQL** (SQLite = dev local uniquement) | `docs/DEPLOIEMENT_CPANEL.md` |
| Serveur web | Apache (cPanel) ou Nginx, DocumentRoot sur `public/` | `public/.htaccess` versionné |
| Extensions PHP | pdo_mysql, mbstring, openssl, curl, gd/imagick | stack Laravel 12 |

Pour les logiciels annexes (LibreOffice, ImageMagick, Ghostscript — conversion de
documents), suivre [`docs/INSTALLATION_SERVEUR.md`](docs/INSTALLATION_SERVEUR.md).

---

## 2. Installation

### 2.1 Récupérer le code

En prod cPanel, le dépôt est déployé via **cPanel Git Version Control** branché sur la
branche `lms`. Le pipeline [`.cpanel.yml`](.cpanel.yml) copie les fichiers vers
`/home/c2569688c/public_html/lms-backend` puis vide les caches (`cache:clear`,
`config:clear`).

> ⚠️ `.cpanel.yml` ne fait **ni** `composer install` **ni** `php artisan migrate`.
> Ces étapes restent manuelles (ci-dessous) — les oublier est la cause de
> l'incident login 500 du 2026-06-20 (cf. runbook).

Sur un serveur hors cPanel :

```bash
git clone git@github.com:ouedraogoissouf2012/lms_backend.git
cd lms_backend
git checkout lms
```

### 2.2 Dépendances PHP

```bash
composer install --no-dev --optimize-autoloader --no-interaction
```

### 2.3 Configurer l'environnement (`.env`)

Il n'existe **pas encore** de `.env.example` dans le repo — sa création est suivie par
l'issue [#357](https://github.com/ouedraogoissouf2012/lms_backend/issues/357). Tant
qu'elle n'est pas livrée, créer le `.env` **à la main** en suivant la référence
complète [`docs/ENV_VARIABLES.md`](docs/ENV_VARIABLES.md).

Variables critiques minimales (toutes lues dans `config/*.php`) :

```dotenv
APP_NAME="KLASSCI LMS"
APP_ENV=production
APP_DEBUG=false        # OBLIGATOIRE en prod — sinon fuite de stack traces
APP_URL=https://api.klassci.com

# Base de données (MySQL en prod)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nom_de_votre_base
DB_USERNAME=utilisateur_dedie   # jamais root
DB_PASSWORD=mot_de_passe_fort

# Intégration KLASSCI (cf. config/services.php « klassci »)
KLASSCI_API_URL=https://...
KLASSCI_API_TOKEN=token_global_klassci
KLASSCI_SSL_VERIFY=true        # ne JAMAIS mettre false en prod (MITM)

# Sanctum
SANCTUM_TOKEN_EXPIRATION=10080   # minutes ; jamais 0 ni vide
SANCTUM_STATEFUL_DOMAINS=votre-frontend.tld
SESSION_ENCRYPT=true

# Compte supradmin (lu uniquement au seeding — retirer SUPRADMIN_PASSWORD après)
SUPRADMIN_EMAIL=admin@votre-domaine.tld
SUPRADMIN_PASSWORD=mot-de-passe-fort-aleatoire

# Queue / cache (database = défaut, cf. config/queue.php:16)
QUEUE_CONNECTION=database
CACHE_STORE=database             # redis si disponible

# Email
MAIL_MAILER=smtp
MAIL_HOST=smtp.votre-domaine.com
MAIL_PORT=587
MAIL_USERNAME=email@domaine.com
MAIL_PASSWORD=mot_de_passe_email
```

> ℹ️ Les tokens **par institution** (présentation, ESBTP…) ne sont **pas** des
> variables d'environnement : ils sont stockés chiffrés en base (table
> `institutions`) et gérés via l'application. Seul `KLASSCI_API_TOKEN` (token
> global) vit dans le `.env`.

### 2.4 Initialiser l'application

```bash
php artisan key:generate
php artisan migrate --force        # --force = pas de prompt en prod
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> ⚠️ Après `config:cache`, le `.env` n'est plus relu : toute modification du `.env`
> impose de relancer `php artisan config:cache`. De même, `env()` retourne `null`
> hors des fichiers `config/` — toujours passer par `config(...)` (ex. dans tinker).

### 2.5 Permissions fichiers

```bash
find /chemin/vers/lms-backend -type f -exec chmod 644 {} \;
find /chemin/vers/lms-backend -type d -exec chmod 755 {} \;
chmod -R 775 storage bootstrap/cache
# Propriétaire selon le serveur (mutualisé cPanel : déjà correct ; dédié :)
chown -R www-data:www-data /chemin/vers/lms-backend
```

### 2.6 Cron et worker de queue — OBLIGATOIRE

Sans cron, les 9 tâches planifiées (`routes/console.php`) ne tournent pas et, avec
`QUEUE_CONNECTION=database`, les jobs s'accumulent sans jamais être traités
(visios jamais fermées, présences jamais finalisées).

L'installation du scheduler et du worker en prod cPanel est spécifiée par l'issue
[#369](https://github.com/ouedraogoissouf2012/lms_backend/issues/369) et documentée
dans `docs/DEPLOYMENT_OPS.md` (livré par #369). **Suivre ce document** — ne pas
improviser une crontab.

---

## 3. Reprise de données existantes (optionnel)

Les anciens scripts `export_data.php` / `import_data.php` **n'existent plus** dans le
repo — toute procédure qui les cite est obsolète. Pour transférer des données d'un
environnement MySQL à un autre, utiliser les outils natifs :

```bash
# Sur la source
mysqldump -u <DB_USERNAME> -p <DB_DATABASE> > export-lms-$(date +%F).sql

# Sur la cible (APRÈS php artisan migrate --force)
mysql -u <DB_USERNAME> -p <DB_DATABASE> < export-lms-AAAA-MM-JJ.sql
```

La base SQLite locale (`database/database.sqlite`) est un artefact de dev : ne
jamais la copier en production.

---

## 4. Sécurité

- Vérifier que `.env`, `composer.json`, `composer.lock` et `artisan` ne sont **pas**
  servis par le web : le DocumentRoot doit pointer sur `public/` uniquement.
  Le [`public/.htaccess`](public/.htaccess) versionné gère la réécriture Apache —
  ne pas le remplacer par une version simplifiée.
- **Défense en profondeur indépendante du DocumentRoot** (issues #537, #577) : le
  [`.htaccess`](.htaccess) racine bloque les fichiers cachés (`.env`, `.git`, `.claude`)
  **et** les répertoires applicatifs (`app`, `bootstrap`, `config`, `database`, `resources`,
  `routes`, `vendor`, `tests`) ; [`storage/.htaccess`](storage/.htaccess) et
  [`bootstrap/cache/.htaccess`](bootstrap/cache/.htaccess) refusent tout accès HTTP direct
  (journaux, uploads privés, config compilée avec secrets). Seul `storage/app/public/`
  (diapositives/vidéos servies par le symlink `/storage`) reste public, via une exception
  dédiée. Ces protections sont actives même si le DocumentRoot est mal configuré — mais **ne
  remplacent pas** un DocumentRoot correct sur `public/`, qui reste la première ligne.
- **Limite d'upload vs configuration PHP** (issue #576) : la validation applicative plafonne
  les fichiers à **30 Mo** (`App\Support\Upload\UploadLimits`). Cette limite n'est effective
  que si `upload_max_filesize` **et** `post_max_size` (php.ini / `.user.ini`) valent **≥ 30 Mo** ;
  si PHP coupe en dessous, l'utilisateur reçoit une erreur serveur illisible avant la validation
  Laravel. Aligner les deux directives PHP sur 30 Mo (ou légèrement au-dessus).
- Équivalent Nginx :

  ```nginx
  location / {
      try_files $uri $uri/ /index.php?$query_string;
  }
  location ~ /\.(?!well-known).* {
      deny all;
  }
  ```

- `APP_DEBUG=false` et `KLASSCI_SSL_VERIFY=true` sont non négociables (issues #1, #357).
- HTTPS obligatoire (tokens Sanctum en transit).

---

## 5. Vérifications post-déploiement

```bash
# 1. Santé applicative (route health Laravel, cf. bootstrap/app.php:12)
curl -s -o /dev/null -w "health: %{http_code}\n" https://api.klassci.com/up
# Attendu : 200

# 2. Auth : un identifiant inconnu doit donner 401 (PAS 500 ni 503)
curl -s -X POST https://api.klassci.com/api/auth/login \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"username":"zzz_inexistant_test","password":"motdepassevalide"}' \
  -w "\nlogin inconnu: %{http_code}\n"
# Attendu : 401 (500 → migrations manquantes ; 503 → KLASSCI injoignable)

# 3. Migrations toutes passées
php artisan migrate:status    # aucune ligne « Pending »

# 4. Connexion DB
php artisan db:show

# 5. Webroot : storage/ et répertoires applicatifs NON exposés (issues #537, #577)
#    Remplacer <domaine> et le préfixe /lms-backend selon le vhost réel.
curl -s -o /dev/null -w "storage/logs:   %{http_code}\n" https://<domaine>/lms-backend/storage/logs/laravel.log
curl -s -o /dev/null -w "storage/private: %{http_code}\n" https://<domaine>/lms-backend/storage/app/private/
curl -s -o /dev/null -w "bootstrap/cache: %{http_code}\n" https://<domaine>/lms-backend/bootstrap/cache/config.php
curl -s -o /dev/null -w "config:          %{http_code}\n" https://<domaine>/lms-backend/config/app.php
# Attendu : 403 (ou 404) pour les quatre. Un 200 → protection inactive : vérifier
# AllowOverride du vhost, et corriger le DocumentRoot (doit pointer sur .../lms-backend/public).

# 5b. Variantes de contournement de la règle racine (encodage %XX, majuscules).
#     La règle racine (mod_rewrite) doit rester robuste ; les cibles à secrets
#     (bootstrap/cache, uploads privés) sont de toute façon protégées par un
#     .htaccess PHYSIQUE, insensible à l'encodage.
curl -s -o /dev/null -w "config encode:   %{http_code}\n" https://<domaine>/lms-backend/%63onfig/app.php
curl -s -o /dev/null -w "config MAJUSC:    %{http_code}\n" https://<domaine>/lms-backend/CONFIG/app.php
# Attendu : 403/404. Un 200 → signaler (durcir la règle) ; le contournement n'exposerait
# que du code source (aucun secret : ils sont en .env + bootstrap/cache, protégés à part).

# 6. Non-régression : les assets PUBLICS restent servis (symlink /storage → storage/app/public)
curl -s -o /dev/null -w "asset public:    %{http_code}\n" https://<domaine>/storage/<un-asset-public-existant>
# Attendu : 200 (un 403 signalerait que l'exception storage/app/public/.htaccess est cassée).
```

Compléter avec les tests réels du runbook (connexion supradmin + connexion d'un
compte KLASSCI) : [`docs/DEPLOIEMENT_CPANEL.md`](docs/DEPLOIEMENT_CPANEL.md) §6.

---

## 6. Dépannage

### Erreur 500

```bash
tail -50 storage/logs/laravel.log
ls -la storage/ bootstrap/cache/      # permissions
php artisan config:clear && php artisan cache:clear && php artisan view:clear
```

### Erreur de migration

```bash
php artisan migrate:status
php artisan migrate --force
# Rollback UNIQUEMENT en dernier recours (perte de données possible) :
php artisan migrate:rollback
```

### API KLASSCI injoignable

```bash
php artisan tinker
# Toujours config(), jamais env() (null après config:cache) :
>>> Http::withToken(config('services.klassci.token'))
       ->get(config('services.klassci.url') . '/classes');
```

---

## 7. Maintenance

```bash
# Backup MySQL quotidien (à planifier — cf. docs/DEPLOYMENT_OPS.md / #369)
mysqldump -u <DB_USERNAME> -p <DB_DATABASE> > ~/backup-lms-$(date +%F).sql

# Backup des fichiers uploadés
tar -czf backup-files-$(date +%F).tar.gz storage/app/public/

# Rotation des logs (> 30 jours)
find storage/logs/ -name "*.log" -mtime +30 -delete
```

Procédure de rollback complète : [`docs/DEPLOIEMENT_CPANEL.md`](docs/DEPLOIEMENT_CPANEL.md)
section « Rollback ».

---

## 8. Checklist finale

- [ ] PHP ≥ 8.2 (`php -v`)
- [ ] `composer install --no-dev` sans erreur
- [ ] `.env` complet (référence : `docs/ENV_VARIABLES.md`), `APP_DEBUG=false`
- [ ] `php artisan migrate:status` sans `Pending`
- [ ] Caches compilés (`config:cache`, `route:cache`, `view:cache`)
- [ ] `GET /up` → 200 ; login inconnu → 401
- [ ] Cron + worker installés selon `docs/DEPLOYMENT_OPS.md` (#369)
- [ ] HTTPS actif, DocumentRoot sur `public/`
- [ ] Backup DB automatique en place
- [ ] Connexions réelles testées (supradmin + compte KLASSCI)

---

**Version** : 2.0 — réécrit le 2026-07-03 (issue #370 : chaque commande vérifiée
contre le repo ; suppression des procédures mortes `.env.production.example`,
`export_data.php`, `import_data.php`)
