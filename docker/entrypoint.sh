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

role="${CONTAINER_ROLE:-web}"

case "$role" in
  worker)
    # Les trois queues doivent etre nommees explicitement : le 1er argument
    # positionnel de queue:work est la CONNEXION, pas la queue. Sans --queue,
    # seule `default` serait drainee et tout ce qui vit sur `low` (conversion
    # de diapositives, rapports PDF, enregistrements visio, sync seances) ou
    # sur `high` (notifications visio urgentes) ne serait jamais traite.
    # L'ordre porte la priorite : high avant default avant low.
    exec php artisan queue:work database \
        --queue=high,default,low \
        --sleep=3 --tries=3 --timeout=120 --max-time=3600
    ;;
  scheduler)
    exec php artisan schedule:work
    ;;
  web|*)
    exec apache2-foreground
    ;;
esac
