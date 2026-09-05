#!/bin/sh
# KPI-PROJECT entrypoint — role-aware (app | queue | reverb)
set -e

ROLE="${CONTAINER_ROLE:-app}"
cd /var/www/html

echo "[entrypoint] role=${ROLE} — waiting for database..."
i=0
until php artisan db:show --no-interaction >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -gt 60 ]; then
        echo "[entrypoint] Database not ready after 120s — aborting."
        exit 1
    fi
    sleep 2
done
echo "[entrypoint] Database ready."

case "$ROLE" in
    app)
        echo "[entrypoint] Running migrations..."
        php artisan migrate --force --no-interaction

        echo "[entrypoint] Linking storage..."
        php artisan storage:link --no-interaction >/dev/null 2>&1 || true

        if [ "$APP_ENV" = "production" ]; then
            echo "[entrypoint] Caching config & views..."
            php artisan config:cache --no-interaction
            php artisan view:cache --no-interaction
            # NOTE: route:cache is intentionally skipped — routes/web.php contains closures.
        fi

        echo "[entrypoint] Starting nginx + php-fpm..."
        exec supervisord -c /etc/supervisor.d/supervisord.conf
        ;;
    queue)
        echo "[entrypoint] Starting queue worker..."
        exec php artisan queue:work database --sleep=3 --tries=3 --timeout=120 --max-time=3600 --no-interaction
        ;;
    reverb)
        if [ -z "$REVERB_APP_KEY" ] || [ "$REVERB_APP_KEY" = "CHANGE_ME" ]; then
            echo "[entrypoint] REVERB_APP_KEY not set — skipping Reverb."
            exit 0
        fi
        echo "[entrypoint] Starting Reverb..."
        exec php artisan reverb:start --host=0.0.0.0 --port="${REVERB_SERVER_PORT:-8080}" --no-interaction
        ;;
    *)
        echo "[entrypoint] Unknown CONTAINER_ROLE: ${ROLE}"
        exit 1
        ;;
esac
