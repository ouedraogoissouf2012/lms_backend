#!/bin/bash
##############################################################################
# Issue #372 (harnais k6) — capture CPU/RAM cote serveur pendant un tir de
# charge, en PARALLELE et INDEPENDAMMENT du process k6 (Requirement 8).
#
# Cible : Linux / VPS (futur #367). Contrepartie Windows dev local :
# tests/load/monitoring/capture-server-metrics.ps1 — meme schema CSV en
# sortie, pour que l'analyse en aval soit independante de l'OS (design.md
# §6 "Capture CPU/RAM").
#
# Mecanisme (Requirement 8.1) :
#   - Prioritaire : `pidstat -h -u -r -p <PID> <interval>`, cible sur le PID
#     du process PHP-FPM/Octane (auto-detecte ou fourni via TARGET_PID).
#   - Fallback (Requirement 8.3) : si `pidstat` est absent, `vmstat -S M
#     <interval>` (metriques SYSTEME globales, pas par-processus — degradation
#     documentee, pas un blocage).
#   - Si ni l'un ni l'autre n'est disponible : message explicite sur stderr,
#     la capture est ignoree pour ce contexte (Requirement 8.3).
#
# Decouplage strict (Requirement 8.4) : ce script est un PROCESSUS SEPARE du
# tir k6 (a demarrer avant `k6 run ...`, a arreter apres). Rien dans le
# harnais k6 n'attend ni ne verifie son statut — un echec de demarrage ici
# n'empeche JAMAIS le tir k6 de s'executer.
#
# Usage :
#   tests/load/monitoring/capture-server-metrics.sh
#   TARGET_PID=1234 tests/load/monitoring/capture-server-metrics.sh
#   METRICS_INTERVAL_SECONDS=2 LOAD_TEST_RUN_LABEL=baseline-pre-374 \
#     tests/load/monitoring/capture-server-metrics.sh
#
# Variables d'environnement :
#   TARGET_PID                PID du process a surveiller (sinon auto-detecte :
#                              Octane, puis PHP-FPM master ; sinon fallback
#                              systeme global via vmstat)
#   METRICS_INTERVAL_SECONDS  Intervalle d'echantillonnage en secondes (defaut 1)
#   LOAD_TEST_RUN_LABEL       Suffixe optionnel du nom de fichier (ex. baseline-pre-374),
#                              meme convention que les resultats k6 (design.md §Data Models)
#   RESULTS_DIR                Repertoire de sortie (defaut tests/load/results)
#
# Sortie (Requirement 8.2) — CSV, schema IDENTIQUE a capture-server-metrics.ps1 :
#   tests/load/results/server-metrics__<YYYYMMDDTHHmmssZ>[__<run-label>].csv
#   timestamp_iso8601,cpu_percent,ram_used_mb,ram_total_mb
#
# Arret propre : Ctrl+C (SIGINT) ou `kill -TERM <pid_de_ce_script>`.
##############################################################################
# Pas de -e : les erreurs de detection d'outils/PID sont gerees explicitement
# pour degrader proprement (Requirement 8.3/8.4) plutot que de crasher.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RESULTS_DIR="${RESULTS_DIR:-${SCRIPT_DIR}/../results}"
INTERVAL_SECONDS="${METRICS_INTERVAL_SECONDS:-1}"
RUN_LABEL="${LOAD_TEST_RUN_LABEL:-}"
TARGET_PID="${TARGET_PID:-}"

mkdir -p "${RESULTS_DIR}"

RUN_TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"
if [[ -n "${RUN_LABEL}" ]]; then
  OUTPUT_FILE="${RESULTS_DIR}/server-metrics__${RUN_TIMESTAMP}__${RUN_LABEL}.csv"
else
  OUTPUT_FILE="${RESULTS_DIR}/server-metrics__${RUN_TIMESTAMP}.csv"
fi

cleanup() {
  echo "" >&2
  echo "==> Capture arretee. Fichier ecrit : ${OUTPUT_FILE}" >&2
  exit 0
}
trap cleanup INT TERM

echo "timestamp_iso8601,cpu_percent,ram_used_mb,ram_total_mb" >"${OUTPUT_FILE}"

# --- Resolution du PID cible (si aucun TARGET_PID explicite) ---------------
if [[ -n "${TARGET_PID}" ]]; then
  if ! kill -0 "${TARGET_PID}" 2>/dev/null; then
    echo "ERREUR : TARGET_PID=${TARGET_PID} ne correspond a aucun process actif." >&2
    exit 1
  fi
elif command -v pgrep >/dev/null 2>&1; then
  # Priorite : Laravel Octane (Swoole/RoadRunner), sinon PHP-FPM master.
  TARGET_PID="$(pgrep -f 'artisan octane:' | head -n1 || true)"
  if [[ -z "${TARGET_PID}" ]]; then
    TARGET_PID="$(pgrep -f 'php-fpm: master process' | head -n1 || true)"
  fi
fi
# Si `pgrep` est absent, TARGET_PID reste vide et le script degrade vers le
# fallback vmstat (systeme global) plus bas — jamais d'erreur bloquante ici.

# --- Strategie 1 (prioritaire) : pidstat cible sur le process applicatif ---
if [[ -n "${TARGET_PID}" ]] && command -v pidstat >/dev/null 2>&1; then
  echo "==> Capture CPU/RAM du PID ${TARGET_PID} via pidstat (intervalle ${INTERVAL_SECONDS}s) -> ${OUTPUT_FILE}" >&2
  echo "==> Ctrl+C pour arreter proprement." >&2

  header_seen=0
  cpu_col=0
  rss_col=0

  # `-h` : une ligne par echantillon avec en-tete etendu, adaptee au parsing
  # (design.md §6). On genere notre propre horodatage ISO8601 plutot que de
  # dependre du format locale de la colonne Time de pidstat.
  while IFS= read -r line; do
    [[ -z "${line}" ]] && continue

    if [[ "${header_seen}" -eq 0 ]]; then
      # pidstat imprime d'abord une banniere systeme ("Linux 5.15.0 (host)
      # 07/04/2026 _x86_64_ (4 CPU)") avant la vraie ligne d'en-tete : on
      # l'ignore et on continue de chercher la ligne contenant le marqueur
      # "%CPU" plutot que de supposer que la 1re ligne non vide est l'en-tete.
      [[ "${line}" != *%CPU* ]] && continue
      header_seen=1
      clean_header="${line#\#}"
      read -ra cols <<<"${clean_header}"
      for i in "${!cols[@]}"; do
        case "${cols[$i]}" in
          %CPU) cpu_col=$((i + 1)) ;;
          RSS) rss_col=$((i + 1)) ;;
        esac
      done
      if [[ "${cpu_col}" -eq 0 || "${rss_col}" -eq 0 ]]; then
        echo "ERREUR : format de sortie pidstat inattendu (colonnes %CPU/RSS introuvables)." >&2
        exit 1
      fi
      continue
    fi

    # Ligne recapitulative finale emise par pidstat a l'arret — ignoree.
    [[ "${line}" == Average:* ]] && continue

    cpu_percent="$(awk -v c="${cpu_col}" '{print $c}' <<<"${line}")"
    rss_kb="$(awk -v c="${rss_col}" '{print $c}' <<<"${line}")"
    [[ -z "${cpu_percent}" || -z "${rss_kb}" ]] && continue

    ram_used_mb="$(awk -v r="${rss_kb}" 'BEGIN { printf "%.1f", r / 1024 }')"
    ram_total_mb="$(free -m | awk '/^Mem:/{print $2}')"
    timestamp_iso8601="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

    echo "${timestamp_iso8601},${cpu_percent},${ram_used_mb},${ram_total_mb}" >>"${OUTPUT_FILE}"
  done < <(LC_ALL=C pidstat -h -u -r -p "${TARGET_PID}" "${INTERVAL_SECONDS}")

  exit 0
fi

# --- Strategie 2 (fallback, Requirement 8.3) : vmstat systeme global -------
if command -v vmstat >/dev/null 2>&1; then
  if [[ -z "${TARGET_PID}" ]]; then
    echo "AVERTISSEMENT : aucun process Octane/PHP-FPM detecte automatiquement — fallback vmstat (metriques systeme globales, pas par-processus). Precisez TARGET_PID pour cibler un process." >&2
  else
    echo "AVERTISSEMENT : pidstat absent — fallback vmstat (metriques systeme globales, pas par-processus). Installez sysstat pour une mesure par-processus." >&2
  fi
  echo "==> Capture CPU/RAM systeme via vmstat (intervalle ${INTERVAL_SECONDS}s) -> ${OUTPUT_FILE}" >&2
  echo "==> Ctrl+C pour arreter proprement." >&2

  ram_total_mb="$(free -m | awk '/^Mem:/{print $2}')"

  # vmstat -S M : colonnes en Mo. Deux lignes d'en-tete a ignorer ("procs
  # -----memory-----..." puis "r b swpd free ..."), reperees par contenu
  # (marqueur "swpd") plutot que par position, pour rester robuste aux
  # variantes de banniere selon la distribution/version de procps.
  while IFS= read -r line; do
    [[ -z "${line}" ]] && continue
    [[ "${line}" == procs* || "${line}" == *swpd* ]] && continue

    free_mb="$(awk '{print $4}' <<<"${line}")"
    idle_percent="$(awk '{print $15}' <<<"${line}")"
    [[ -z "${free_mb}" || -z "${idle_percent}" ]] && continue

    cpu_percent="$(awk -v idle="${idle_percent}" 'BEGIN { printf "%.1f", 100 - idle }')"
    ram_used_mb="$(awk -v total="${ram_total_mb}" -v free="${free_mb}" 'BEGIN { printf "%.1f", total - free }')"
    timestamp_iso8601="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

    echo "${timestamp_iso8601},${cpu_percent},${ram_used_mb},${ram_total_mb}" >>"${OUTPUT_FILE}"
  done < <(vmstat -S M "${INTERVAL_SECONDS}")

  exit 0
fi

# --- Ni pidstat ni vmstat disponibles (Requirement 8.3) --------------------
echo "ERREUR : ni 'pidstat' (paquet sysstat) ni 'vmstat' (paquet procps) ne sont disponibles sur ce systeme." >&2
echo "La capture CPU/RAM est ignoree pour ce contexte. Installez l'un des deux paquets (ex. 'apt install sysstat procps')" >&2
echo "ou consultez docs/LOAD_TESTING.md pour une alternative (node_exporter). Le tir k6 lui-meme n'est PAS affecte" >&2
echo "par cette absence : ce script est un processus independant (Requirement 8.4)." >&2
rm -f "${OUTPUT_FILE}"
exit 1
