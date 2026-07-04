# scripts/vps — Provisioning VPS (issue #367)

Runbook complet : [`docs/VPS_MIGRATION.md`](../../docs/VPS_MIGRATION.md).
Ne pas executer ces scripts sans l'avoir lu — ils touchent SSH, le
pare-feu et des bases de donnees de production.

Ordre d'execution :

| # | Fichier | Ou | Quand |
|---|---|---|---|
| 1 | `01-harden-server.sh` | VPS, root | Juste apres la 1ere connexion sur le VPS neuf |
| 2 | `02-install-stack.sh` | VPS, root (meme session que 1) | Juste apres |
| 3 | `03-nginx-lms-backend.conf.template` | VPS | Avant TLS (bootstrap HTTP) |
| 4 | `04-setup-tls.sh` | VPS, root | Une fois le DNS pointe sur le VPS |
| 5 | `05-deploy.sh` | VPS, user `deploy` | A chaque deploiement (premier + suivants) |
| 6 | `06-backup-mysql.sh` | VPS, root, via cron | Quotidien (voir `crontab-lms`) |
| 7 | `07-migrate-data-from-cpanel.sh` | VPS, user `deploy` ou `root` | Une fois, avant la bascule DNS |
| - | `lms-queue-worker.service` | VPS, `/etc/systemd/system/` | Installe apres l'etape 5 |
| - | `crontab-lms` | VPS, `crontab -e` | Installe apres l'etape 5 |

Tous les scripts sont `set -euo pipefail`, s'arretent a la premiere
erreur et n'ecrivent jamais de secret dans un fichier journal.
