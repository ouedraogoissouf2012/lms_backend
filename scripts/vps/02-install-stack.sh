#!/bin/bash
##############################################################################
# Issue #367 — Etape 0 du plan scalabilite : installation de la stack VPS
#
# A executer en root, dans LA MEME session que 01-harden-server.sh (une
# fois celui-ci passe, une nouvelle connexion root n'est plus possible par
# design — voir docs/VPS_MIGRATION.md).
#
# Installe : PHP 8.3 + opcache + extensions Laravel, MySQL 8, Redis 7,
# Nginx, Composer, et les outils de conversion documentaire deja requis en
# prod cPanel (LibreOffice/Imagick/Ghostscript, cf. docs/INSTALLATION_SERVEUR.md)
# afin que le VPS offre une parite fonctionnelle complete.
#
# Usage:
#   DEPLOY_USER=deploy APP_DIR=/var/www/lms-backend DB_NAME=lms_backend \
#   DB_USER=lms_app ./02-install-stack.sh
#
# En sortie : affiche le mot de passe DB genere et le mot de passe Redis
# genere. Ils ne sont ecrits dans AUCUN fichier journal — copiez-les
# immediatement dans votre gestionnaire de secrets (ils serviront au .env
# de l'application, etape 05-deploy.sh).
##############################################################################
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "Ce script doit etre execute en root (meme session que 01-harden-server.sh)." >&2
  exit 1
fi

DEPLOY_USER="${DEPLOY_USER:-deploy}"
APP_DIR="${APP_DIR:-/var/www/lms-backend}"
DB_NAME="${DB_NAME:-lms_backend}"
DB_USER="${DB_USER:-lms_app}"
PHP_VERSION="8.3"

if ! id "${DEPLOY_USER}" &>/dev/null; then
  echo "L'utilisateur ${DEPLOY_USER} n'existe pas : lancez d'abord 01-harden-server.sh." >&2
  exit 1
fi

echo "==> Depots et mise a jour"
apt-get update -y
apt-get install -y software-properties-common curl gnupg2 ca-certificates lsb-release unzip git

echo "==> PHP ${PHP_VERSION} + extensions requises par l'application"
# Ubuntu 24.04 LTS (noble) fournit PHP 8.3 en depot officiel : pas besoin
# du PPA ondrej/php utilise sur les versions plus anciennes d'Ubuntu.
PHP_PACKAGES=(
  "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-common"
  "php${PHP_VERSION}-mysql" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl"
  "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-zip" "php${PHP_VERSION}-bcmath"
  "php${PHP_VERSION}-gd" "php${PHP_VERSION}-intl" "php${PHP_VERSION}-redis"
  "php${PHP_VERSION}-opcache" "php${PHP_VERSION}-readline"
)
apt-get install -y "${PHP_PACKAGES[@]}"

echo "==> Extension Imagick + LibreOffice + Ghostscript (parite conversion documentaire)"
if ! apt-get install -y php-imagick; then
  echo "    php-imagick absent des depots apt : installation via PECL (fallback)."
  apt-get install -y "php${PHP_VERSION}-dev" php-pear libmagickwand-dev
  pecl channel-update pecl.php.net
  yes '' | pecl install imagick
  echo "extension=imagick.so" >"/etc/php/${PHP_VERSION}/mods-available/imagick.ini"
  phpenmod -v "${PHP_VERSION}" imagick
fi
apt-get install -y libreoffice libreoffice-writer libreoffice-impress ghostscript

echo "==> opcache : reglages production (opcache.validate_timestamps=0, cf. recommandation officielle Laravel)"
cat >"/etc/php/${PHP_VERSION}/fpm/conf.d/99-opcache-prod.ini" <<'EOF'
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.revalidate_freq=0
EOF
echo "    /!\ opcache.validate_timestamps=0 : chaque deploiement DOIT recharger" \
     "php${PHP_VERSION}-fpm (voir 05-deploy.sh) pour prendre le nouveau code en compte."

# Limites d'upload alignees sur docs/INSTALLATION_SERVEUR.md
cat >"/etc/php/${PHP_VERSION}/fpm/conf.d/99-lms-limits.ini" <<'EOF'
upload_max_filesize = 100M
post_max_size = 100M
max_execution_time = 300
max_input_time = 300
memory_limit = 512M
EOF

echo "==> Pool PHP-FPM execute en tant que ${DEPLOY_USER} (pas www-data)"
# Le code est clone par ${DEPLOY_USER} (05-deploy.sh) et le worker de queue
# systemd tourne aussi en ${DEPLOY_USER} (lms-queue-worker.service). Faire
# tourner le pool FPM sous le meme utilisateur evite tout depareillage de
# permissions sur storage/ et bootstrap/cache/ (pas besoin d'ajouter
# www-data a un groupe ni d'ouvrir ces dossiers en 777). Nginx (www-data)
# garde l'acces au socket via son groupe, pas via l'utilisateur du pool.
POOL_CONF="/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"
# Chaque directive peut etre active OU seulement presente en commentaire
# dans le pool.d livre par le paquet Debian/Ubuntu : on remplace si une
# ligne active existe deja, sinon on ajoute la bonne valeur en fin de
# fichier (php-fpm ne lit que la derniere occurrence active d'une cle).
set_fpm_directive() {
  local key="$1" value="$2"
  if grep -qE "^${key} = " "${POOL_CONF}"; then
    sed -i "s|^${key} = .*|${key} = ${value}|" "${POOL_CONF}"
  else
    printf '%s = %s\n' "${key}" "${value}" >>"${POOL_CONF}"
  fi
}
set_fpm_directive "user" "${DEPLOY_USER}"
set_fpm_directive "group" "${DEPLOY_USER}"
set_fpm_directive "listen.owner" "www-data"
set_fpm_directive "listen.group" "www-data"
set_fpm_directive "listen.mode" "0660"

for check in "user = ${DEPLOY_USER}" "group = ${DEPLOY_USER}" "listen.owner = www-data" "listen.group = www-data" "listen.mode = 0660"; do
  grep -qF "${check}" "${POOL_CONF}" || {
    echo "ERREUR : directive '${check}' non appliquee dans ${POOL_CONF}." >&2
    exit 1
  }
done
php-fpm${PHP_VERSION} -t

systemctl enable --now "php${PHP_VERSION}-fpm"

echo "==> Secrets d'installation (DB_PASSWORD / REDIS_PASSWORD)"
# Idempotent par construction plutot que par chance : sans ce marqueur, un
# rerun du script (ex. reprise apres l'echec d'une etape plus loin) genere
# un NOUVEAU mot de passe a chaque fois. Pour MySQL, `CREATE USER IF NOT
# EXISTS` ne mettrait alors pas a jour le mot de passe reel (l'utilisateur
# existe deja) : le script afficherait un mot de passe qui ne correspond
# PLUS a celui actif en base. Pour Redis, le mot de passe serait au
# contraire ecrase silencieusement, cassant un .env deja en place. Reutiliser
# le meme secret sur chaque execution (au lieu d'un ALTER/sed inconditionnel
# a chaque run) garantit que la valeur affichee est TOUJOURS la valeur active.
SECRETS_FILE="/root/.lms-install-secrets"
if [[ -f "${SECRETS_FILE}" ]]; then
  echo "    Secrets deja generes lors d'une precedente execution : reutilisation (${SECRETS_FILE})."
  # shellcheck disable=SC1090
  source "${SECRETS_FILE}"
else
  DB_PASSWORD="$(openssl rand -base64 24)"
  REDIS_PASSWORD="$(openssl rand -base64 24)"
  install -m 600 /dev/null "${SECRETS_FILE}"
  cat >"${SECRETS_FILE}" <<EOF
DB_PASSWORD='${DB_PASSWORD}'
REDIS_PASSWORD='${REDIS_PASSWORD}'
EOF
fi

echo "==> MySQL 8"
apt-get install -y mysql-server
systemctl enable --now mysql

mysql --user=root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
-- Durcissement mysql_secure_installation, applique de facon non interactive :
DELETE FROM mysql.user WHERE User='' ;
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db LIKE 'test\\_%';
FLUSH PRIVILEGES;
SQL

echo "==> Redis 7 (cache local a l'app, requirepass genere)"
apt-get install -y redis-server

# Idempotent et verifie plutot que base sur un sed qui ne matcherait pas
# silencieusement si le libelle par defaut de redis.conf differe d'une
# version a l'autre (un Redis sans mot de passe qui demarre quand meme
# serait une regression de securite silencieuse).
if grep -qE '^requirepass ' /etc/redis/redis.conf; then
  sed -i "s|^requirepass .*|requirepass ${REDIS_PASSWORD}|" /etc/redis/redis.conf
else
  echo "requirepass ${REDIS_PASSWORD}" >>/etc/redis/redis.conf
fi
grep -qF "requirepass ${REDIS_PASSWORD}" /etc/redis/redis.conf || {
  echo "ERREUR : requirepass non applique dans /etc/redis/redis.conf." >&2
  exit 1
}

if grep -qE '^bind ' /etc/redis/redis.conf; then
  sed -i "s|^bind .*|bind 127.0.0.1 -::1|" /etc/redis/redis.conf
else
  echo "bind 127.0.0.1 -::1" >>/etc/redis/redis.conf
fi

if grep -qE '^protected-mode ' /etc/redis/redis.conf; then
  sed -i "s|^protected-mode .*|protected-mode yes|" /etc/redis/redis.conf
else
  echo "protected-mode yes" >>/etc/redis/redis.conf
fi

systemctl enable --now redis-server
systemctl restart redis-server

if ! redis-cli -a "${REDIS_PASSWORD}" --no-auth-warning ping | grep -q PONG; then
  echo "ERREUR : redis-cli ping a echoue apres configuration du mot de passe." >&2
  exit 1
fi

echo "==> Nginx"
apt-get install -y nginx
systemctl enable --now nginx

echo "==> Composer (installeur officiel, verifie par checksum SHA-384)"
EXPECTED_CHECKSUM="$(curl -fsSL https://composer.github.io/installer.sig)"
curl -fsSL -o /tmp/composer-setup.php https://getcomposer.org/installer
ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
if [[ "${EXPECTED_CHECKSUM}" != "${ACTUAL_CHECKSUM}" ]]; then
  echo "ERREUR : checksum de l'installeur Composer invalide. Abandon." >&2
  rm -f /tmp/composer-setup.php
  exit 1
fi
php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm -f /tmp/composer-setup.php

echo "==> Repertoire applicatif ${APP_DIR}"
install -d -m 755 -o "${DEPLOY_USER}" -g "${DEPLOY_USER}" "${APP_DIR}"

cat <<EOF

==> Installation de la stack terminee.

Verification rapide :
  php -v                      # doit afficher PHP ${PHP_VERSION}
  systemctl status php${PHP_VERSION}-fpm nginx mysql redis-server --no-pager
  redis-cli -a '${REDIS_PASSWORD}' ping     # doit repondre PONG
  mysql -u ${DB_USER} -p'${DB_PASSWORD}' -e "SELECT 1" ${DB_NAME}

A CONSERVER IMMEDIATEMENT dans le gestionnaire de secrets (non journalises) :
  DB_DATABASE=${DB_NAME}
  DB_USERNAME=${DB_USER}
  DB_PASSWORD=${DB_PASSWORD}
  REDIS_PASSWORD=${REDIS_PASSWORD}

Etape suivante : 03-nginx-lms-backend.conf.template puis 04-setup-tls.sh.
EOF
