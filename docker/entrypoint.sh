#!/bin/sh
set -e

php artisan package:discover --ansi >/dev/null 2>&1 || true
php artisan storage:link >/dev/null 2>&1 || true
mkdir -p storage/api-docs
if [ -f docs/openapi.yaml ]; then
    cp -f docs/openapi.yaml storage/api-docs/openapi.yaml
fi

if [ "${SKIP_CONFIG_CACHE:-0}" != "1" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi


# #673 - rien ne doit ecrire en root sous /var/www/html/storage.
#
# `ImportJibriRecordingMedia` copie l'enregistrement vers le disque PRIVE
# (#824). Le worker demarrant en root, Laravel creait son arborescence avec
# la visibilite privee par defaut de Flysystem :
#
#   drwx------ root root  storage/app/private/recordings/4
#
# Apache tourne sous www-data et ne peut pas traverser ce repertoire. Mesure
# en production le 2026-09-18, sur le premier enregistrement mene de bout en
# bout : le media etait bien la, 8 075 548 octets, et la route signee rendait
# 404. AUCUNE video ne fut lisible avant un chown manuel.
#
# Le repli non-root est le meme contrat que #831 pour les migrations : `su`
# echouerait si le conteneur tournait deja sous un utilisateur non privilegie.
sous_www_data() {
  if [ "$(id -u)" = "0" ]; then
    exec su -s /bin/sh www-data -c "$*"
  fi
  exec sh -c "$*"
}

role="${CONTAINER_ROLE:-web}"

case "$role" in
  worker)
    # Les trois queues doivent etre nommees explicitement : le 1er argument
    # positionnel de queue:work est la CONNEXION, pas la queue. Sans --queue,
    # seule `default` serait drainee et tout ce qui vit sur `low` (conversion
    # de diapositives, rapports PDF, enregistrements visio, sync seances) ou
    # sur `high` (notifications visio urgentes) ne serait jamais traite.
    # L'ordre porte la priorite : high avant default avant low.
    sous_www_data "php artisan queue:work database \
        --queue=high,default,low \
        --sleep=3 --tries=3 --timeout=120 --max-time=3600"
    ;;
  scheduler)
    sous_www_data "php artisan schedule:work"
    ;;
  web|*)
    # Dokploy deploie le code sans jouer les migrations (#831) : un correctif
    # de securite peut etre en production et inerte. Un seul role (web) les
    # joue, sinon worker + scheduler partiraient en concurrence. www-data :
    # un docker exec root cree des repertoires illisibles pour Apache.
    if [ "$(id -u)" = "0" ]; then
      su -s /bin/sh www-data -c "php artisan migrate --force --no-interaction"
    else
      php artisan migrate --force --no-interaction
    fi
    exec apache2-foreground
    ;;
esac
