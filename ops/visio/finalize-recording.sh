#!/bin/bash
#
# #469 — script de finalisation Jibri : prévient le LMS qu'un enregistrement est prêt.
#
# Jibri l'invoque à la fin de CHAQUE enregistrement ayant produit un média, via
# `JIBRI_FINALIZE_RECORDING_SCRIPT_PATH`. L'image le rend exécutable toute seule
# si besoin (/etc/s6-overlay/scripts/config:23-26).
#
# CONTRAT D'APPEL — DETTE TRACÉE
#   Jibri passe le répertoire de la session en $1. C'est le contrat documenté,
#   mais il n'a PAS pu être vérifié empiriquement : le seul exemple fourni par
#   l'image (finalize_sip.sh) ne prend aucun argument, et la trace d'un appel
#   réel n'a pas pu être capturée avant un redémarrage du conteneur.
#   D'où le parti pris ci-dessous : on journalise TOUJOURS les arguments reçus,
#   et on échoue bruyamment si $1 n'est pas un répertoire lisible — plutôt que
#   de deviner le répertoire, ce qui masquerait une hypothèse fausse.
#   La première finalisation réelle tranchera, et elle le dira fort.
#
# CE QUE CE SCRIPT NE FAIT JAMAIS
#   Il ne supprime pas le média. Le LMS vient le lire lui-même sur un chemin
#   monté ; supprimer ici ferait de la première tentative la seule, et un LMS
#   momentanément injoignable coûterait un cours.
#
set -uo pipefail

LOG_FILE="${JIBRI_FINALIZE_LOG:-/storage/logs/finalize.log}"
LMS_URL="${LMS_WEBHOOK_URL:-}"
LMS_SECRET="${LMS_WEBHOOK_SECRET:-}"
ATTEMPT_DELAYS=(5 15 45)

log() {
    printf '%s  %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*" >> "$LOG_FILE" 2>/dev/null
    printf '%s\n' "$*"
}

# ---------------------------------------------------------------- arguments reçus
# Journalisé AVANT tout le reste : si le contrat d'appel diffère de l'hypothèse,
# c'est cette ligne qui le dira, même si le script échoue juste après.
log "finalize appelé avec $# argument(s) : $*"

RECORDING_DIR="${1:-}"

if [ -z "$RECORDING_DIR" ] || [ ! -d "$RECORDING_DIR" ] || [ ! -r "$RECORDING_DIR" ]; then
    log "ERREUR: \$1 n'est pas un répertoire lisible ('$RECORDING_DIR'). Contrat d'appel différent de l'hypothèse — voir l'en-tête."
    exit 1
fi

if [ -z "$LMS_URL" ] || [ -z "$LMS_SECRET" ]; then
    log "ERREUR: LMS_WEBHOOK_URL et LMS_WEBHOOK_SECRET doivent être définis. Média conservé dans $RECORDING_DIR."
    exit 1
fi

# ------------------------------------------------------------------- métadonnées
SESSION_ID="$(basename "$RECORDING_DIR")"
METADATA="$RECORDING_DIR/metadata.json"

if [ ! -r "$METADATA" ]; then
    log "ERREUR: metadata.json illisible dans $RECORDING_DIR. Média conservé."
    exit 1
fi

# `meeting_url` vaut https://domaine/salon ; le salon est le dernier segment.
# On coupe un éventuel fragment ou paramètre, qu'aucune version connue n'ajoute
# mais qui rendrait le nom de salon faux sans erreur.
MEETING_URL="$(sed -n 's/.*"meeting_url"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$METADATA" | head -1)"
ROOM="${MEETING_URL##*/}"
ROOM="${ROOM%%\?*}"
ROOM="${ROOM%%#*}"

if [ -z "$ROOM" ]; then
    log "ERREUR: salon introuvable dans $METADATA (meeting_url='$MEETING_URL'). Média conservé."
    exit 1
fi

if [ -z "$(find "$RECORDING_DIR" -maxdepth 1 -name '*.mp4' -print -quit)" ]; then
    log "ERREUR: aucun .mp4 dans $RECORDING_DIR — rien à signaler."
    exit 1
fi

# ---------------------------------------------------------------- corps et signature
# Le corps ne porte QUE des métadonnées : le média ne transite pas par HTTP, ce
# qui évite de calculer un HMAC sur plusieurs centaines de mégaoctets.
TIMESTAMP="$(date -u '+%s')"
NONCE="$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')"
BODY="{\"room\":\"${ROOM}\",\"session\":\"${SESSION_ID}\"}"

# Ordre imposé par le serveur : timestamp \n nonce \n corps brut.
SIGNATURE="$(printf '%s\n%s\n%s' "$TIMESTAMP" "$NONCE" "$BODY" \
    | openssl dgst -sha256 -hmac "$LMS_SECRET" -r | cut -d' ' -f1)"

log "notification: salon=$ROOM session=$SESSION_ID"

# ------------------------------------------------------------------------ envoi
for index in "${!ATTEMPT_DELAYS[@]}"; do
    HTTP_CODE="$(curl -sS -o /tmp/finalize-response.$$ -w '%{http_code}' \
        --max-time 30 -X POST "$LMS_URL" \
        -H 'Content-Type: application/json' \
        -H "X-Visio-Signature: sha256=${SIGNATURE}" \
        -H "X-Visio-Timestamp: ${TIMESTAMP}" \
        -H "X-Visio-Nonce: ${NONCE}" \
        --data-raw "$BODY" 2>>"$LOG_FILE")" || HTTP_CODE="000"

    RESPONSE="$(cat /tmp/finalize-response.$$ 2>/dev/null)"
    rm -f /tmp/finalize-response.$$

    if [ "$HTTP_CODE" = "202" ]; then
        log "accepté par le LMS (202)."
        exit 0
    fi

    # 4xx = refus définitif : rejouer à l'identique redonnerait le même verdict,
    # et le nonce serait de toute façon rejeté en 409. On s'arrête et on trace.
    case "$HTTP_CODE" in
        4*)
            log "REFUS DÉFINITIF du LMS (HTTP $HTTP_CODE) : $RESPONSE"
            log "Média CONSERVÉ dans $RECORDING_DIR — rattachement manuel possible, cf. docs/VISIO_JIBRI_FINALIZE.md"
            exit 1
            ;;
    esac

    DELAY="${ATTEMPT_DELAYS[$index]}"
    if [ "$index" -lt $(( ${#ATTEMPT_DELAYS[@]} - 1 )) ]; then
        log "échec transitoire (HTTP $HTTP_CODE), nouvelle tentative dans ${DELAY}s"
        sleep "$DELAY"
    fi
done

log "ÉCHEC après ${#ATTEMPT_DELAYS[@]} tentatives (dernier code HTTP $HTTP_CODE)."
log "Média CONSERVÉ dans $RECORDING_DIR — rejeu possible, cf. docs/VISIO_JIBRI_FINALIZE.md"
exit 1
