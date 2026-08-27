#!/bin/sh
set -e

php artisan package:discover --ansi >/dev/null 2>&1 || true
php artisan storage:link >/dev/null 2>&1 || true

if [ "${SKIP_CONFIG_CACHE:-0}" != "1" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

role="${CONTAINER_ROLE:-web}"

case "$role" in
  worker)
    exec php artisan queue:work database --sleep=3 --tries=3 --timeout=120 --max-time=3600
    ;;
  scheduler)
    exec php artisan schedule:work
    ;;
  web|*)
    exec apache2-foreground
    ;;
esac
