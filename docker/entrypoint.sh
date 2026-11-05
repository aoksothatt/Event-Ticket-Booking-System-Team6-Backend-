#!/bin/sh
set -eu

# Always run Artisan/Nginx paths relative to the application root, regardless
# of the working directory Render starts the container with.
cd /var/www/html

: "${APP_KEY:?APP_KEY must be set in the Render environment}"
: "${JWT_SECRET:?JWT_SECRET must be set in the Render environment}"
: "${DB_URL:?DB_URL must be set to the pooled Neon URL}"
: "${APP_URL:?APP_URL must be set to the public backend URL}"

if [ "${APP_ENV:-production}" = "production" ] && [ "${APP_DEBUG:-false}" != "false" ]; then
    echo "Startup refused: APP_DEBUG must be false in production." >&2
    exit 1
fi

PORT="${PORT:-10000}"
case "$PORT" in
    ''|*[!0-9]*)
        echo "Startup refused: PORT must be a numeric TCP port." >&2
        exit 1
        ;;
esac
export PORT

envsubst '${PORT}' \
    < /etc/nginx/templates/default.conf.template \
    > /etc/nginx/conf.d/default.conf

nginx -t

mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown www-data:www-data \
    storage \
    storage/app \
    storage/app/public \
    storage/framework \
    storage/framework/cache \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chmod ug+rwX \
    storage \
    storage/app \
    storage/app/public \
    storage/framework \
    storage/framework/cache \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

echo "Preparing Laravel production caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Starting PHP-FPM and Nginx on 0.0.0.0:${PORT}."
exec "$@"
