#!/bin/bash
##############################################################################
# Issue #367 — Etape 0 du plan scalabilite : migration des donnees
# cPanel -> VPS (dump/restore + verification d'integrite)
#
# A executer DEPUIS LE VPS (ou tout poste ayant acces SSH aux deux cotes).
# Le dump est stream directement depuis cPanel vers ce poste (jamais
# ecrit en clair sur le disque cPanel), puis restaure localement, puis
# les deux cotes sont compares table par table (COUNT(*) exact, pas une
# estimation information_schema).
#
# PREREQUIS CRITIQUE — coherence des donnees :
#   Mettre l'application cPanel en mode maintenance AVANT de lancer ce
#   script, et l'y laisser jusqu'a la bascule DNS (etape 9 du runbook) :
#     ssh "$CPANEL_SSH" "cd $CPANEL_APP_DIR && php artisan down"
#   Sans cela, des ecritures entre le dump et la comparaison des comptes
#   de lignes produiront de FAUX ecarts (ou pire, une perte silencieuse
#   de donnees ecrites pendant la fenetre de bascule). Ce script VERIFIE
#   desormais ce prerequis lui-meme (etape [1/7]) et refuse de continuer
#   si le marqueur de maintenance Laravel est absent cote cPanel.
#
# Prerequis technique (une seule fois, cote cPanel, meme principe que
# /etc/lms-backup/.my.cnf sur le VPS — ne jamais passer le mot de passe
# en argument mysqldump, visible dans `ps`) :
#   cat > ~/.my-migration.cnf <<EOF
#   [client]
#   user=<utilisateur DB cPanel>
#   password=<mot de passe DB cPanel>
#   EOF
#   chmod 600 ~/.my-migration.cnf
#
# Usage:
#   CPANEL_SSH=c2569688c@serveur-cpanel.example.com \
#   CPANEL_APP_DIR=/home/c2569688c/public_html/lms-backend \
#   CPANEL_DB_NAME=c2569688c_lms \
#   CPANEL_MYSQL_DEFAULTS_FILE=~/.my-migration.cnf \
#   VPS_DB_NAME=lms_backend \
#   VPS_MYSQL_DEFAULTS_FILE=/etc/lms-backup/.my.cnf \
#   APP_DIR=/var/www/lms-backend \
#   ./07-migrate-data-from-cpanel.sh --confirm-restore
#
# Sans --confirm-restore : le script s'arrete apres le dump + verification
# de checksum, SANS toucher a la base VPS (mode "dry-run" par defaut —
# une restauration est destructive, elle ne doit jamais etre accidentelle).
##############################################################################
set -euo pipefail

CONFIRM_RESTORE="false"
for arg in "$@"; do
  [[ "${arg}" == "--confirm-restore" ]] && CONFIRM_RESTORE="true"
done

CPANEL_SSH="${CPANEL_SSH:?CPANEL_SSH requis (ex: c2569688c@serveur.example.com)}"
CPANEL_APP_DIR="${CPANEL_APP_DIR:?CPANEL_APP_DIR requis (ex: /home/c2569688c/public_html/lms-backend)}"
CPANEL_DB_NAME="${CPANEL_DB_NAME:?CPANEL_DB_NAME requis}"
CPANEL_MYSQL_DEFAULTS_FILE="${CPANEL_MYSQL_DEFAULTS_FILE:-~/.my-migration.cnf}"
VPS_DB_NAME="${VPS_DB_NAME:-lms_backend}"
VPS_MYSQL_DEFAULTS_FILE="${VPS_MYSQL_DEFAULTS_FILE:-/etc/lms-backup/.my.cnf}"
APP_DIR="${APP_DIR:-/var/www/lms-backend}"
WORKDIR="$(mktemp -d /tmp/lms-migration-XXXXXX)"
DUMP_FILE="${WORKDIR}/${CPANEL_DB_NAME}.sql.gz"
SOURCE_COUNTS="${WORKDIR}/source-counts.tsv"
DEST_COUNTS="${WORKDIR}/dest-counts.tsv"

cleanup() { rm -rf "${WORKDIR}"; }
trap cleanup EXIT

echo "==> [1/7] Verification : cPanel doit etre en mode maintenance (PREREQUIS CRITIQUE)"
if ! ssh "${CPANEL_SSH}" "test -f '${CPANEL_APP_DIR}/storage/framework/down'"; then
  echo "ERREUR : ${CPANEL_APP_DIR} n'est PAS en mode maintenance cote cPanel." >&2
  echo "Lancer d'abord : ssh ${CPANEL_SSH} \"cd ${CPANEL_APP_DIR} && php artisan down\"" >&2
  echo "Sans ce prerequis, le dump et la comparaison de comptes de lignes produiront de FAUX ecarts (ou pire, une perte silencieuse de donnees)." >&2
  exit 1
fi
echo "    OK : mode maintenance actif cote cPanel."

echo "==> [2/7] Dump distant de ${CPANEL_DB_NAME} via ${CPANEL_SSH} (stream, pas d'ecriture sur cPanel)"
ssh "${CPANEL_SSH}" \
  "mysqldump --defaults-extra-file='${CPANEL_MYSQL_DEFAULTS_FILE}' --single-transaction --routines --triggers --no-tablespaces '${CPANEL_DB_NAME}'" \
  | gzip >"${DUMP_FILE}"

if [[ ! -s "${DUMP_FILE}" ]]; then
  echo "ERREUR : dump distant vide ou echoue." >&2
  exit 1
fi
gzip -t "${DUMP_FILE}"
echo "    OK : $(du -h "${DUMP_FILE}" | cut -f1)"

echo "==> [3/7] Comptage des lignes cote source (cPanel)"
# Script distant envoye tel quel (heredoc entre guillemets simples : aucune
# expansion locale, donc aucun risque de double-echappement des backticks
# de quotage d'identifiant SQL). Les seules valeurs qui traversent la
# frontiere locale/distante passent en arguments positionnels ($1/$2),
# jamais interpolees dans le corps du script.
ssh "${CPANEL_SSH}" bash -s -- "${CPANEL_MYSQL_DEFAULTS_FILE}" "${CPANEL_DB_NAME}" <<'REMOTE_SCRIPT' | sort >"${SOURCE_COUNTS}"
set -euo pipefail
defaults_file="$1"
db="$2"
mysql --defaults-extra-file="${defaults_file}" -N -e \
  "SELECT table_name FROM information_schema.tables WHERE table_schema='${db}' AND table_type='BASE TABLE'" \
  "${db}" | while read -r table; do
    count=$(mysql --defaults-extra-file="${defaults_file}" -N -e "SELECT COUNT(*) FROM \`${table}\`" "${db}")
    printf '%s\t%s\n' "${table}" "${count}"
  done
REMOTE_SCRIPT
echo "    $(wc -l <"${SOURCE_COUNTS}") tables recensees cote source."

if [[ "${CONFIRM_RESTORE}" != "true" ]]; then
  cat <<EOF

==> Dump verifie, PAS de restauration (--confirm-restore absent).
    Dump conserve temporairement : ${DUMP_FILE}
    Comptes de lignes source    : ${SOURCE_COUNTS}

    Relancez avec --confirm-restore pour restaurer sur ${VPS_DB_NAME} et
    comparer les comptes de lignes. Assurez-vous d'abord que l'app cPanel
    est en mode maintenance (voir en-tete du script).
EOF
  cp "${SOURCE_COUNTS}" "/tmp/lms-migration-source-counts-$(date +%F-%H%M).tsv"
  exit 0
fi

echo "==> [4/7] Restauration dans ${VPS_DB_NAME} (VPS local) — DESTRUCTIF, ecrase la base cible"
gunzip -c "${DUMP_FILE}" | mysql --defaults-extra-file="${VPS_MYSQL_DEFAULTS_FILE}" "${VPS_DB_NAME}"

echo "==> [5/7] Comptage des lignes cote destination (VPS)"
mysql --defaults-extra-file="${VPS_MYSQL_DEFAULTS_FILE}" -N -e "
  SELECT table_name FROM information_schema.tables
  WHERE table_schema='${VPS_DB_NAME}' AND table_type='BASE TABLE'" "${VPS_DB_NAME}" \
  | while read -r table; do
      count=$(mysql --defaults-extra-file="${VPS_MYSQL_DEFAULTS_FILE}" -N -e "SELECT COUNT(*) FROM \`${table}\`" "${VPS_DB_NAME}")
      printf '%s\t%s\n' "${table}" "${count}"
    done | sort >"${DEST_COUNTS}"

echo "==> [6/7] Verification d'integrite (comptes de lignes source vs destination)"
if diff -u "${SOURCE_COUNTS}" "${DEST_COUNTS}"; then
  echo "    OK : comptes de lignes identiques sur toutes les tables ($(wc -l <"${SOURCE_COUNTS}") tables)."
else
  echo "ERREUR : ecart detecte entre source et destination (diff ci-dessus)." >&2
  echo "NE PAS basculer le DNS tant que cet ecart n'est pas explique et resolu." >&2
  exit 1
fi

echo "==> [7/7] Resynchronisation du schema avec le code deploye (php artisan migrate --force)"
# Le dump cPanel reflete la table `migrations` de cPanel au moment du dump,
# potentiellement plus ancienne que le code deja deploye sur le VPS (etape 5
# du runbook, executee AVANT cette restauration). La restauration ci-dessus
# vient d'ecraser cette table par l'etat cPanel : sans ce re-jeu, le VPS
# servirait du code recent sur un schema obsolete. migrate --force est
# idempotent (n'applique que les migrations manquantes), donc sans danger
# a rejouer a chaque migration/re-synchronisation (etape 9 du runbook).
(cd "${APP_DIR}" && php artisan migrate --force)

echo "==> Migration des donnees terminee, verifiee et schema resynchronise."
