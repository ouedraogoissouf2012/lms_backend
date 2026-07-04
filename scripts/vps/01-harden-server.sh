#!/bin/bash
##############################################################################
# Issue #367 — Etape 0 du plan scalabilite : durcissement initial du VPS
#
# A executer UNE SEULE FOIS, en root, juste apres la premiere connexion sur
# le VPS neuf (Ubuntu 24.04 LTS). Cree l'utilisateur de deploiement non-root,
# verrouille SSH sur l'authentification par cle, active UFW (22/80/443) et
# fail2ban.
#
# Usage:
#   DEPLOY_USER=deploy \
#   DEPLOY_SSH_PUBKEY="ssh-ed25519 AAAA... deploy@poste-local" \
#   ./01-harden-server.sh
#
# Variables :
#   DEPLOY_USER        Nom du compte non-root (defaut: deploy)
#   DEPLOY_SSH_PUBKEY  Cle publique SSH a autoriser pour ce compte (OBLIGATOIRE)
#
# /!\ SEQUENCE DE SECURITE — ne pas fermer le terminal root avant d'avoir
# verifie, DANS UN SECOND TERMINAL, que la connexion
# `ssh $DEPLOY_USER@<ip-vps>` fonctionne. Ce script desactive
# PasswordAuthentication et PermitRootLogin : une cle cassee = verrouillage
# hors du serveur (recuperable uniquement via la console web de l'hebergeur).
##############################################################################
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "Ce script doit etre execute en root (premiere connexion VPS)." >&2
  exit 1
fi

DEPLOY_USER="${DEPLOY_USER:-deploy}"
DEPLOY_SSH_PUBKEY="${DEPLOY_SSH_PUBKEY:-}"

if [[ -z "${DEPLOY_SSH_PUBKEY}" ]]; then
  echo "DEPLOY_SSH_PUBKEY est obligatoire (cle publique du poste qui deploiera)." >&2
  echo 'Exemple: DEPLOY_SSH_PUBKEY="ssh-ed25519 AAAA... deploy@local" '"$0" >&2
  exit 1
fi

echo "==> Mise a jour du systeme"
apt-get update -y
apt-get upgrade -y

echo "==> Utilisateur de deploiement non-root : ${DEPLOY_USER}"
if id "${DEPLOY_USER}" &>/dev/null; then
  echo "    Deja present, on passe."
else
  adduser --disabled-password --gecos "" "${DEPLOY_USER}"
fi

DEPLOY_HOME="/home/${DEPLOY_USER}"
install -d -m 700 -o "${DEPLOY_USER}" -g "${DEPLOY_USER}" "${DEPLOY_HOME}/.ssh"
AUTH_KEYS="${DEPLOY_HOME}/.ssh/authorized_keys"
touch "${AUTH_KEYS}"
grep -qxF "${DEPLOY_SSH_PUBKEY}" "${AUTH_KEYS}" || echo "${DEPLOY_SSH_PUBKEY}" >>"${AUTH_KEYS}"
chmod 600 "${AUTH_KEYS}"
chown "${DEPLOY_USER}:${DEPLOY_USER}" "${AUTH_KEYS}"

# Sudo strictement limite : ${DEPLOY_USER} ne doit jamais avoir besoin d'un mot
# de passe root pour deployer. On n'autorise sans mot de passe QUE les
# commandes necessaires au cycle de deploiement (reload PHP-FPM/Nginx,
# redemarrage du worker de queue). Toute autre action reste hors de portee.
SUDOERS_FILE="/etc/sudoers.d/${DEPLOY_USER}-deploy"
SUDOERS_TMP="$(mktemp)"
cat >"${SUDOERS_TMP}" <<EOF
${DEPLOY_USER} ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm
${DEPLOY_USER} ALL=(root) NOPASSWD: /usr/bin/systemctl reload nginx
${DEPLOY_USER} ALL=(root) NOPASSWD: /usr/bin/systemctl restart lms-queue-worker
${DEPLOY_USER} ALL=(root) NOPASSWD: /usr/bin/systemctl status lms-queue-worker
EOF
# Valider AVANT d'installer : un sudoers.d invalide casse sudo pour TOUT
# le systeme (le fichier principal inclut le dossier entier). On ne
# l'installe donc jamais tant qu'il n'est pas prouve syntaxiquement correct.
if ! visudo -cf "${SUDOERS_TMP}"; then
  echo "ERREUR : fichier sudoers genere invalide, abandon avant installation." >&2
  rm -f "${SUDOERS_TMP}"
  exit 1
fi
install -m 440 -o root -g root "${SUDOERS_TMP}" "${SUDOERS_FILE}"
rm -f "${SUDOERS_TMP}"

echo "==> SSH : authentification par cle uniquement"
SSHD_CONFIG="/etc/ssh/sshd_config.d/99-lms-hardening.conf"
cat >"${SSHD_CONFIG}" <<'EOF'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin no
X11Forwarding no
MaxAuthTries 4
EOF
sshd -t
systemctl reload ssh

# `sshd -t` valide seulement la SYNTAXE du fichier ecrit ci-dessus, pas la
# precedence effective : un autre fichier inclus avant ce drop-in dans
# /etc/ssh/sshd_config.d/ (ordre alphabetique) ou une directive concurrente
# du sshd_config principal pourrait l'emporter silencieusement. On verifie
# donc la configuration EFFECTIVEMENT appliquee (sshd -T), pas seulement
# le fichier qu'on vient d'ecrire.
EFFECTIVE_SSHD_CONFIG="$(sshd -T)"
for directive in "passwordauthentication no" "permitrootlogin no" "kbdinteractiveauthentication no"; do
  if ! grep -qiF "${directive}" <<<"${EFFECTIVE_SSHD_CONFIG}"; then
    echo "ERREUR : la configuration SSH effective ne reflete pas '${directive}' apres reload." >&2
    echo "sshd -t a valide la syntaxe mais un autre fichier/directive prend le pas -- NE PAS fermer cette session root." >&2
    exit 1
  fi
done
echo "    Verifie : PasswordAuthentication/PermitRootLogin/KbdInteractiveAuthentication effectifs = no."

echo "==> Pare-feu UFW (22/80/443 uniquement)"
apt-get install -y ufw
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw default deny incoming
ufw default allow outgoing
ufw --force enable

echo "==> fail2ban (protection brute-force SSH)"
apt-get install -y fail2ban
cat >/etc/fail2ban/jail.d/sshd.local <<'EOF'
[sshd]
enabled = true
maxretry = 5
bantime = 1h
findtime = 10m
EOF
systemctl enable --now fail2ban
systemctl restart fail2ban

cat <<EOF

==> Durcissement termine.

VERIFICATION OBLIGATOIRE avant de fermer cette session root :
  1. Ouvrir un NOUVEAU terminal.
  2. ssh ${DEPLOY_USER}@<ip-du-vps>
  3. Confirmer que la connexion reussit ET que 'sudo -n systemctl status lms-queue-worker' repond
     (meme si le service n'existe pas encore, l'erreur doit venir du service manquant,
     pas d'un refus sudo).

Ne fermez pas ce terminal root tant que l'etape 2 n'est pas validee.
EOF
