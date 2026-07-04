#!/bin/bash
##############################################################################
# Issue #367 — Etape 0 du plan scalabilite : TLS (Let's Encrypt / certbot)
#
# Prerequis : le vhost 03-nginx-lms-backend.conf.template est deja deploye
# ET le DNS de DOMAIN pointe deja vers l'IP du VPS (certbot valide par
# HTTP-01 : sans DNS a jour, la validation echoue).
#
# Usage:
#   sudo DOMAIN=api.klassci.com EMAIL=ops@klassci.com ./04-setup-tls.sh
##############################################################################
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "Ce script doit etre execute avec sudo/root." >&2
  exit 1
fi

DOMAIN="${DOMAIN:-}"
EMAIL="${EMAIL:-}"

if [[ -z "${DOMAIN}" || -z "${EMAIL}" ]]; then
  echo "DOMAIN et EMAIL sont obligatoires." >&2
  echo 'Exemple: DOMAIN=api.klassci.com EMAIL=ops@klassci.com '"$0" >&2
  exit 1
fi

echo "==> Verification DNS avant de solliciter Let's Encrypt"
RESOLVED_IP="$(dig +short "${DOMAIN}" | tail -n1)"
PUBLIC_IP="$(curl -fsSL https://ifconfig.me)"
if [[ "${RESOLVED_IP}" != "${PUBLIC_IP}" ]]; then
  echo "ATTENTION : ${DOMAIN} resout vers ${RESOLVED_IP:-<rien>}, pas vers l'IP de ce VPS (${PUBLIC_IP})." >&2
  echo "La validation HTTP-01 de Let's Encrypt va tres probablement echouer. Corrigez le DNS puis relancez." >&2
  exit 1
fi

echo "==> Installation certbot (plugin nginx)"
apt-get update -y
apt-get install -y certbot python3-certbot-nginx

echo "==> Obtention du certificat + reecriture du vhost Nginx (redirection HTTPS incluse)"
certbot --nginx \
  -d "${DOMAIN}" \
  -m "${EMAIL}" \
  --agree-tos \
  --non-interactive \
  --redirect \
  --hsts

echo "==> Verification du renouvellement automatique"
systemctl list-timers | grep -i certbot || {
  echo "ERREUR : aucun timer de renouvellement certbot actif." >&2
  exit 1
}
certbot renew --dry-run

cat <<EOF

==> TLS operationnel sur https://${DOMAIN}

Verification manuelle :
  curl -I https://${DOMAIN}/up
  curl -I http://${DOMAIN}/up   # doit repondre 301 vers https

Le renouvellement est automatique (timer systemd certbot.timer, deux
tentatives par jour, marge de 30 jours avant expiration — aucune action
manuelle requise sauf alerte email de Let's Encrypt).
EOF
