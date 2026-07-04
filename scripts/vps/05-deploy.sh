#!/bin/bash
##############################################################################
# Issue #367 — Etape 0 du plan scalabilite : deploiement applicatif sur VPS
#
# A executer en tant qu'utilisateur de deploiement (JAMAIS root), depuis
# n'importe quel repertoire. Reprend le meme flux que
# docs/DEPLOIEMENT_CPANEL.md (git pull -> composer -> migrate -> cache),
# adapte a un VPS : le rechargement de PHP-FPM et du worker de queue se
# fait via les regles sudo NOPASSWD posees par 01-harden-server.sh (pas
# de mot de passe root necessaire).
#
# Usage:
#   APP_DIR=/var/www/lms-backend BRANCH=lms ./05-deploy.sh
#
# Regle d'or (identique cPanel) : JAMAIS d'edition manuelle du code sur le
# serveur. Tout passe par local -> push -> ce script.
##############################################################################
set -euo pipefail

if [[ "${EUID}" -eq 0 ]]; then
  echo "Ne pas executer ce script en root : utilisez l'utilisateur de deploiement." >&2
  exit 1
fi

APP_DIR="${APP_DIR:-/var/www/lms-backend}"
BRANCH="${BRANCH:-lms}"
REPO_URL="${REPO_URL:-https://github.com/ouedraogoissouf2012/lms_backend.git}"
HEALTHCHECK_URL="${HEALTHCHECK_URL:-http://127.0.0.1/up}"

cd "${APP_DIR}"

if [[ ! -d .git ]]; then
  echo "==> Premier deploiement : clonage de ${REPO_URL} (branche ${BRANCH})"
  git clone --branch "${BRANCH}" "${REPO_URL}" .
else
  echo "==> Recuperation du code (branche ${BRANCH})"
  if [[ -n "$(git status --porcelain)" ]]; then
    echo "ERREUR : modifications locales non commitees detectees sur le serveur." >&2
    echo "Regle d'or : jamais d'edition directe sur le serveur. git status ci-dessous :" >&2
    git status --short >&2
    exit 1
  fi
  git fetch origin "${BRANCH}"
  git checkout "${BRANCH}"
  git reset --hard "origin/${BRANCH}"
fi

echo "==> Verification .env de production (issue #1 — critique securite)"
if [[ ! -f .env ]]; then
  echo "ERREUR : .env absent. Le creer manuellement (jamais commit) avant de continuer." >&2
  exit 1
fi
if ! grep -qE '^APP_ENV=production$' .env; then
  echo "ERREUR : APP_ENV != production dans .env. Corriger avant de continuer." >&2
  exit 1
fi
if grep -qE '^APP_DEBUG=true$' .env; then
  echo "ERREUR : APP_DEBUG=true en production (fuite de stack traces). Corriger avant de continuer." >&2
  exit 1
fi

echo "==> Dependances PHP (production, sans dev)"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Migrations (etape critique — ne jamais sauter)"
php artisan migrate --status || true
php artisan migrate --force

echo "==> Recompilation des caches Laravel"
php artisan config:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Permissions storage/bootstrap-cache"
chmod -R ug+rwX storage bootstrap/cache

echo "==> Rechargement PHP-FPM (opcache.validate_timestamps=0 : obligatoire pour prendre le nouveau code)"
sudo /usr/bin/systemctl reload php8.3-fpm

echo "==> Redemarrage du worker de queue (code change -> ancien worker tournait sur l'ancien code)"
if systemctl list-unit-files lms-queue-worker.service &>/dev/null; then
  sudo /usr/bin/systemctl restart lms-queue-worker
  sleep 2
  sudo /usr/bin/systemctl status lms-queue-worker --no-pager | head -5
else
  echo "    AVERTISSEMENT : unit lms-queue-worker.service pas encore installee." >&2
  echo "    Normal uniquement au tout premier deploiement — installez-la juste apres (voir docs/VPS_MIGRATION.md etape 5)." >&2
fi

echo "==> Verification de sante"
# -L : suit la redirection HTTPS posee par 04-setup-tls.sh (certbot --redirect).
# Sans elle, un healthcheck HTTP recoit 301 (pas 200) des que TLS est actif,
# et ce script echouerait a CHAQUE deploiement alors que l'app repond bien.
HTTP_CODE="$(curl -s -L -o /dev/null -w '%{http_code}' "${HEALTHCHECK_URL}")"
if [[ "${HTTP_CODE}" != "200" ]]; then
  echo "ERREUR : healthcheck ${HEALTHCHECK_URL} a repondu ${HTTP_CODE} (attendu 200)." >&2
  echo "Ne PAS considerer le deploiement comme reussi. Voir storage/logs/laravel.log." >&2
  exit 1
fi

echo "==> Deploiement termine avec succes ($(git rev-parse --short HEAD))."
