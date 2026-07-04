#!/bin/bash
##############################################################################
# Issue #367 — Etape 0 du plan scalabilite : sauvegarde quotidienne MySQL
#
# Concu pour etre lance par cron (root), une fois par jour — voir
# scripts/vps/crontab-lms. Dump complet + gzip + rotation locale 14
# jours + copie hors serveur optionnelle (rsync).
#
# Prerequis (une seule fois, en root) :
#   install -d -m 700 /etc/lms-backup
#   cat >/etc/lms-backup/.my.cnf <<EOF
#   [client]
#   user=lms_app
#   password=<DB_PASSWORD genere par 02-install-stack.sh>
#   EOF
#   chmod 600 /etc/lms-backup/.my.cnf
#
# Usage (appele par cron, voir crontab-lms) :
#   DB_NAME=lms_backend BACKUP_REMOTE_DEST=backup-user@backup-host:/srv/backups/lms \
#   ./06-backup-mysql.sh
#
# BACKUP_REMOTE_DEST est le mecanisme "hors serveur" exige par les criteres
# d'acceptation de l'issue #367. Sans lui, les dumps restent UNIQUEMENT sur
# le VPS : en cas de perte du VPS, ils sont perdus avec le reste. Ce script
# le signale bruyamment (exit 2) tant que la variable n'est pas configuree,
# plutot que de laisser croire a une sauvegarde hors-site inexistante.
##############################################################################
set -euo pipefail

DB_NAME="${DB_NAME:-lms_backend}"
MYSQL_DEFAULTS_FILE="${MYSQL_DEFAULTS_FILE:-/etc/lms-backup/.my.cnf}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/lms}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
BACKUP_REMOTE_DEST="${BACKUP_REMOTE_DEST:-}"

if [[ "${EUID}" -ne 0 ]]; then
  echo "Ce script doit etre execute en root (lecture de ${MYSQL_DEFAULTS_FILE})." >&2
  exit 1
fi

if [[ ! -f "${MYSQL_DEFAULTS_FILE}" ]]; then
  echo "ERREUR : ${MYSQL_DEFAULTS_FILE} absent. Voir l'en-tete de ce script pour le creer." >&2
  exit 1
fi

install -d -m 700 "${BACKUP_DIR}"

TIMESTAMP="$(date +%F-%H%M)"
DUMP_FILE="${BACKUP_DIR}/lms-${DB_NAME}-${TIMESTAMP}.sql.gz"

echo "==> Dump de ${DB_NAME} vers ${DUMP_FILE}"
# --single-transaction : dump coherent sans verrouiller les tables InnoDB
# (aucune interruption de service pendant le backup).
mysqldump \
  --defaults-extra-file="${MYSQL_DEFAULTS_FILE}" \
  --single-transaction \
  --routines \
  --triggers \
  --no-tablespaces \
  "${DB_NAME}" | gzip >"${DUMP_FILE}"

if [[ ! -s "${DUMP_FILE}" ]]; then
  echo "ERREUR : dump vide ou echoue (${DUMP_FILE})." >&2
  rm -f "${DUMP_FILE}"
  exit 1
fi
gzip -t "${DUMP_FILE}"
echo "    OK : $(du -h "${DUMP_FILE}" | cut -f1)"

echo "==> Rotation locale (retention ${RETENTION_DAYS} jours)"
find "${BACKUP_DIR}" -name "lms-${DB_NAME}-*.sql.gz" -mtime "+${RETENTION_DAYS}" -print -delete

if [[ -z "${BACKUP_REMOTE_DEST}" ]]; then
  echo "ERREUR : BACKUP_REMOTE_DEST non configure — le dump reste UNIQUEMENT sur ce VPS." >&2
  echo "Critere d'acceptation issue #367 non rempli tant que ceci n'est pas corrige." >&2
  exit 2
fi

echo "==> Copie hors serveur vers ${BACKUP_REMOTE_DEST}"
rsync -az --timeout=60 "${DUMP_FILE}" "${BACKUP_REMOTE_DEST}/"

echo "==> Sauvegarde terminee : ${DUMP_FILE}"
