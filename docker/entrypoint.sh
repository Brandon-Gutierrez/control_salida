#!/bin/sh
# Arranque del contenedor de la API: espera la base, migra, siembra y lanza php-fpm.
set -e

cd /var/www/html

if [ -z "$APP_KEY" ]; then
    echo "[entrypoint] APP_KEY está vacío. Defínelo en el .env de Docker." >&2
    exit 1
fi

# Marca de "listo" que usa el healthcheck: se crea al terminar migraciones y siembra.
rm -f /tmp/ready

# En desarrollo el código y vendor/ vienen montados: se instalan las dependencias si faltan.
if [ "$APP_ENV" = "local" ] && [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] Instalando dependencias de Composer (desarrollo)..."
    composer install --no-interaction --prefer-dist
fi

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

echo "[entrypoint] Esperando a PostgreSQL en ${DB_HOST}:${DB_PORT}..."
tries=0
until PGPASSWORD="$DB_PASSWORD" pg_isready -q -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE"; do
    tries=$((tries + 1))
    if [ "$tries" -ge 60 ]; then
        echo "[entrypoint] PostgreSQL no respondió a tiempo." >&2
        exit 1
    fi
    sleep 2
done

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "[entrypoint] Ejecutando migraciones..."
    php artisan migrate --force --no-interaction
fi

# Roles y administrador inicial: es idempotente, se puede repetir en cada arranque.
if [ "${RUN_SEEDERS:-true}" = "true" ]; then
    echo "[entrypoint] Sembrando roles y administrador inicial..."
    php artisan db:seed --force --no-interaction
fi

if [ "$APP_ENV" = "production" ]; then
    php artisan config:cache --no-interaction
    php artisan event:cache --no-interaction
else
    php artisan config:clear --no-interaction
fi

touch /tmp/ready
echo "[entrypoint] API lista."

exec "$@"
