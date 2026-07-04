# VPS_MIGRATION — Migration cPanel mutualisé → VPS

> **Issue #367** — étape 0 du plan scalabilité (épique #381). Bloquant :
> aucune des étapes suivantes (#372 k6, #374 Redis applicatif, #378 Octane,
> #379 workers, #380 backpressure) n'est possible tant que la prod reste
> sur cPanel mutualisé (pas de démon long-running, pas de Redis, CPU/RAM
> throttlés — plafond réaliste : quelques dizaines de req/s).
>
> **Règle d'or, identique à `DEPLOIEMENT_CPANEL.md`** : le cPanel actuel
> reste le fallback de production **intact** jusqu'à validation complète
> et **bascule DNS explicitement décidée** — jamais automatique, jamais
> déclenchée par un script de ce dossier.

Scripts associés : [`scripts/vps/`](../scripts/vps/README.md). Ce document
est la référence versionnée ; les scripts sont les commandes exactes.

---

## 0. Prérequis avant de commencer

- [ ] Un compte chez un hébergeur VPS, dimensionné **4 vCPU / 8 Go RAM minimum**,
      image **Ubuntu 24.04 LTS**. Le choix du fournisseur (OVH, Hetzner,
      Scaleway, DigitalOcean…) est une décision de coût/région propre à
      l'utilisateur — non automatisable depuis ce dépôt, aucune préférence
      imposée ici.
- [ ] Une paire de clés SSH générée sur le poste qui déploiera
      (`ssh-keygen -t ed25519`), la **clé publique** disponible pour
      `DEPLOY_SSH_PUBKEY`.
- [ ] Accès DNS du domaine (`api.klassci.com` ou équivalent) pour créer
      l'enregistrement A vers l'IP du VPS, **et** pour abaisser son TTL
      à 300s quelques heures avant la bascule finale (étape 9).
- [ ] Accès SSH à l'hébergement cPanel actuel (déjà utilisé pour les
      déploiements existants, voir `DEPLOIEMENT_CPANEL.md`) — nécessaire
      à l'étape 8 (migration des données).

---

## 1. Provisionner le VPS

Action manuelle chez l'hébergeur choisi : commander la machine (specs
ci-dessus), noter son IP publique, activer l'accès SSH root initial
fourni par l'hébergeur.

✅ Vérif : `ssh root@<ip-vps>` fonctionne (mot de passe ou clé fournie par
l'hébergeur — ce sera la **dernière fois**, l'étape 2 verrouille cet accès).

---

## 2. Sécuriser le serveur

```bash
scp scripts/vps/01-harden-server.sh root@<ip-vps>:/root/
ssh root@<ip-vps>
DEPLOY_USER=deploy DEPLOY_SSH_PUBKEY="$(cat ~/.ssh/id_ed25519.pub)" \
  /root/01-harden-server.sh
```

✅ Vérif (**dans un second terminal, sans fermer le premier**) :
```bash
ssh deploy@<ip-vps>
sudo -n systemctl status lms-queue-worker   # erreur "unit not found" attendue, PAS "sudo: a password is required"
```
Si la connexion `deploy@` échoue, **ne pas fermer** la session root — corriger
`~/.ssh/authorized_keys` de `deploy` avant de continuer.

Ce que fait le script : création de l'utilisateur `deploy` (non-root),
SSH par clé uniquement (`PasswordAuthentication no`, `PermitRootLogin no`),
UFW (22/80/443 uniquement), fail2ban sur sshd, sudoers limité à 4 commandes
`systemctl` précises (aucun accès root large accordé à `deploy`).

---

## 3. Installer la stack applicative

**Dans la même session root** que l'étape 2 (une nouvelle connexion root
n'est plus possible après l'étape 2 — c'est voulu) :

```bash
scp scripts/vps/02-install-stack.sh root@<ip-vps>:/root/
ssh root@<ip-vps>   # session déjà ouverte si vous avez suivi l'étape 2
DEPLOY_USER=deploy APP_DIR=/var/www/lms-backend \
DB_NAME=lms_backend DB_USER=lms_app \
  /root/02-install-stack.sh
```

Installe : PHP 8.3 + extensions Laravel + opcache (réglages production),
MySQL 8, Redis 7 (mot de passe généré, écoute localhost uniquement),
Nginx, Composer (installeur vérifié par checksum SHA-384), et les
dépendances de conversion documentaire déjà requises en prod cPanel
(LibreOffice, Imagick, Ghostscript — voir `INSTALLATION_SERVEUR.md`), pour
une parité fonctionnelle complète avec l'existant.

✅ Vérif (critère d'acceptation de l'issue) :
```bash
redis-cli -a '<REDIS_PASSWORD affiché en sortie du script>' ping   # -> PONG
systemctl status php8.3-fpm nginx mysql redis-server --no-pager
```

**Copier immédiatement** `DB_PASSWORD` et `REDIS_PASSWORD` (affichés une
seule fois, jamais journalisés) dans le gestionnaire de secrets — ils
serviront au `.env` de l'étape 5.

---

## 4. Nginx + TLS

```bash
# Sur le VPS, en root :
export DOMAIN=api.klassci.com APP_DIR=/var/www/lms-backend
envsubst '${DOMAIN} ${APP_DIR}' \
  < scripts/vps/03-nginx-lms-backend.conf.template \
  | tee /etc/nginx/sites-available/lms-backend.conf
ln -s /etc/nginx/sites-available/lms-backend.conf /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

Puis, **une fois le DNS de `$DOMAIN` pointé sur l'IP du VPS** (enregistrement
A, propagation vérifiable via `dig +short api.klassci.com`) :

```bash
DOMAIN=api.klassci.com EMAIL=ops@klassci.com scripts/vps/04-setup-tls.sh
```

✅ Vérif :
```bash
curl -I https://api.klassci.com/up    # 200
curl -I http://api.klassci.com/up     # 301 -> https
```

---

## 5. Premier déploiement applicatif

Créer manuellement le `.env` de production sur le VPS (jamais commité,
jamais copié tel quel depuis cPanel sans relecture — adapter
`DB_DATABASE=lms_backend`, `DB_USERNAME=lms_app`, `DB_PASSWORD=<étape 3>`,
`REDIS_PASSWORD=<étape 3>`, `APP_ENV=production`, `APP_DEBUG=false`), puis :

```bash
# En tant qu'utilisateur deploy (jamais root) :
APP_DIR=/var/www/lms-backend BRANCH=lms scripts/vps/05-deploy.sh
```

Reprend exactement le flux de `DEPLOIEMENT_CPANEL.md` (git pull → composer
`--no-dev` → `migrate --force` → `config:cache`), avec en plus le
rechargement de PHP-FPM et le redémarrage du worker de queue via les
règles sudo posées à l'étape 2 (nécessaire car `opcache.validate_timestamps=0`
en prod — sans ce reload, l'ancien code resterait servi).

Puis installer le worker de queue et le cron du scheduler (les deux fichiers
sont des templates : `${DEPLOY_USER}`/`${APP_DIR}` doivent être substitués,
jamais un `cp`/copier-coller brut des valeurs par défaut) :

```bash
export DEPLOY_USER=deploy APP_DIR=/var/www/lms-backend
envsubst '${DEPLOY_USER} ${APP_DIR}' \
  < scripts/vps/lms-queue-worker.service \
  | sudo tee /etc/systemd/system/lms-queue-worker.service
sudo systemctl daemon-reload
sudo systemctl enable --now lms-queue-worker

envsubst '${APP_DIR}' < scripts/vps/crontab-lms
# Coller la ligne "scheduler" ci-dessus dans : crontab -u deploy -e
# Coller la ligne "backup" ci-dessus dans    : sudo crontab -u root -e
```

✅ Vérif (critère d'acceptation) :
```bash
sudo systemctl status lms-queue-worker --no-pager   # active (running), en continu
php artisan schedule:list                            # les tâches de routes/console.php apparaissent
```

> **Suivi hors périmètre #367** : sur cPanel, le scheduler pilote aussi le
> drainage de la queue (`queue:work --stop-when-empty`, tâche #12 de
> `DEPLOYMENT_OPS.md` §5) faute de démon possible. Sur VPS, cette tâche
> devient redondante (le worker systemd tourne déjà en continu) mais pas
> nuisible (`withoutOverlapping` protège des collisions). Retirer cette
> tâche de `routes/console.php` est un nettoyage à part, à ouvrir en issue
> de suivi — ne pas le faire dans le cadre de #367 pour ne pas mélanger
> infra et code applicatif dans la même PR.

---

## 6. Sauvegardes automatiques

```bash
sudo install -d -m 700 /etc/lms-backup
sudo tee /etc/lms-backup/.my.cnf <<EOF
[client]
user=lms_app
password=<DB_PASSWORD de l'étape 3>
EOF
sudo chmod 600 /etc/lms-backup/.my.cnf

# Test manuel avant de compter sur le cron :
sudo DB_NAME=lms_backend BACKUP_REMOTE_DEST=backup-user@backup-host:/srv/backups/lms \
  scripts/vps/06-backup-mysql.sh
```

`BACKUP_REMOTE_DEST` est **obligatoire** pour satisfaire le critère
« hors serveur » de l'issue — le script échoue explicitement (exit 2) tant
qu'il n'est pas configuré, plutôt que de laisser croire à une sauvegarde
hors-site qui n'existe pas. Provisionner la destination (autre VPS, stockage
objet monté en SFTP, etc.) est une décision d'infra propre à l'utilisateur,
non automatisée ici.

✅ Vérif : `ls -lh /var/backups/lms/` (dump non vide) et présence du fichier
sur la destination distante.

---

## 7. Migration des données depuis cPanel

**Avant de lancer cette étape**, mettre cPanel en mode maintenance pour
garantir la cohérence des données :

```bash
ssh <user>@<serveur-cpanel> "cd /home/c2569688c/public_html/lms-backend && php artisan down"
```

Le script **vérifie lui-même** ce prérequis (étape `[1/7]`, via
`CPANEL_APP_DIR`) et refuse de continuer si le marqueur de maintenance
Laravel est absent — il ne s'agit plus d'une simple consigne manuelle.

Puis, depuis le VPS :

```bash
# 1er passage : dump + vérification seule (aucune écriture sur le VPS)
CPANEL_SSH=<user>@<serveur-cpanel> \
CPANEL_APP_DIR=/home/c2569688c/public_html/lms-backend \
CPANEL_DB_NAME=<db_cpanel> \
CPANEL_MYSQL_DEFAULTS_FILE=~/.my-migration.cnf \
  scripts/vps/07-migrate-data-from-cpanel.sh

# Une fois rassuré par la sortie du dry-run : restauration + vérification d'intégrité
CPANEL_SSH=<user>@<serveur-cpanel> \
CPANEL_APP_DIR=/home/c2569688c/public_html/lms-backend \
CPANEL_DB_NAME=<db_cpanel> \
CPANEL_MYSQL_DEFAULTS_FILE=~/.my-migration.cnf \
VPS_DB_NAME=lms_backend \
VPS_MYSQL_DEFAULTS_FILE=/etc/lms-backup/.my.cnf \
APP_DIR=/var/www/lms-backend \
  scripts/vps/07-migrate-data-from-cpanel.sh --confirm-restore
```

✅ Vérif (critère d'acceptation « vérification d'intégrité ») : le script
compare un `COUNT(*)` exact table par table entre cPanel et VPS et **échoue
bruyamment** en cas d'écart — ne pas continuer tant que le diff n'est pas
expliqué. En dernière étape (`[7/7]`), il rejoue aussi `php artisan
migrate --force` (idempotent) pour resynchroniser le schéma restauré avec le
code déjà déployé à l'étape 5 — sans quoi la restauration écraserait la table
`migrations` par un état cPanel potentiellement plus ancien que le code servi.

---

## 8. Validation en parallèle (avant toute bascule)

Le VPS tourne en parallèle de cPanel, données migrées, DNS **pas encore**
basculé (accès de test via l'IP du VPS ou un sous-domaine temporaire type
`vps-test.klassci.com`) :

```bash
# Suite de fumée des 5 flux E2E de l'issue #211, contre les données migrées
php artisan test --filter=E2E
```

✅ Vérif (critère d'acceptation) : les 5 tests
(`StudentQuizFlowTest`, `TeacherLessonPublicationFlowTest`,
`StudentChapterProgressionFlowTest`, `ForumDiscussionFlowTest`,
`MultiTenantIsolationFlowTest`) passent au vert sur le VPS.

- [ ] Test manuel de connexion (compte supradmin local + compte KLASSCI),
      identique §6 de `DEPLOIEMENT_CPANEL.md`.
- [ ] Comparaison des temps de réponse VPS vs cPanel sur 2-3 endpoints
      représentatifs (attendu : nettement meilleur, sans quoi la migration
      n'apporte rien — si ce n'est pas le cas, investiguer avant de basculer).

---

## 9. Bascule DNS

**Seulement après validation explicite de l'utilisateur — jamais automatique.**

1. Abaisser le TTL du DNS à 300s, au moins 1h avant la bascule (limite la
   fenêtre de propagation).
2. Dernier `07-migrate-data-from-cpanel.sh --confirm-restore` pour rattraper
   les écritures survenues depuis la première migration (cPanel toujours en
   mode maintenance depuis l'étape 7).
3. Changer l'enregistrement A du domaine vers l'IP du VPS.
4. Surveiller la propagation (`dig +short api.klassci.com` depuis plusieurs
   réseaux) et les logs applicatifs des deux côtés pendant au moins 24h.
5. **cPanel reste en place, éteint mais non supprimé**, comme filet de
   secours, jusqu'à ce que l'utilisateur donne l'ordre explicite de
   décommissionner (règle projet : aucune bascule ni suppression sans ordre —
   voir mémoire `feedback_no_cpanel_deploy_until_refactoring_done`).

---

## Rollback

Si la bascule révèle un problème dans les 24h :

1. Remettre l'enregistrement A du DNS sur l'IP cPanel (le TTL abaissé à
   l'étape 9 rend ce retour rapide).
2. Sortir cPanel du mode maintenance : `php artisan up`.
3. Les écritures faites sur le VPS pendant la fenêtre de bascule sont
   **perdues au rollback** (cPanel n'a pas ces données) — accepter ce risque
   consciemment ou, si inacceptable, rejouer manuellement les écritures
   critiques depuis les logs applicatifs du VPS avant de rebasculer.

---

## Critères d'acceptation (issue #367) — statut

| Critère | Étape | Vérification |
|---|---|---|
| App répond en HTTPS, données migrées, 5 flux E2E verts | 4, 7, 8 | `curl -I https://…/up` + `php artisan test --filter=E2E` |
| `redis-cli ping` → PONG | 3 | `redis-cli -a … ping` |
| `queue:work` en continu | 5 | `systemctl status lms-queue-worker` |
| cPanel intact en fallback jusqu'à validation explicite | 9 | Aucune action de décommissionnement dans ce dossier |

---

## Dette explicitement tracée

Ces scripts sont écrits en suivant la documentation officielle
(Ubuntu Server, Let's Encrypt/Certbot, Composer, Laravel deployment) et le
même style que les runbooks cPanel existants du dépôt, mais **n'ont pas pu
être exécutés de bout en bout sur un vrai VPS depuis cet environnement**
(pas d'accès à un hébergeur ni à des identifiants SSH serveur ici). Chaque
script échoue tôt et bruyamment (`set -euo pipefail`, vérifications
explicites) plutôt que de continuer sur une hypothèse fausse, mais un essai
complet sur un VPS jetable avant la bascule réelle est fortement recommandé.
