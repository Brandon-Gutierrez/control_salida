#!/bin/sh
# Arranque del contenedor de la API: espera a PostgreSQL y Redis (servidores
# externos), inicia el planificador (crond) y lanza php-fpm. No ejecuta
# migraciones ni datos iniciales: se corren a mano (ver README.md).
set -e

cd /var/www/html

# Comandos sueltos (`docker compose run --rm app php artisan ...`): se ejecutan tal cual.
if [ "$1" != "php-fpm" ]; then
    exec "$@"
fi

if [ -z "$APP_KEY" ]; then
    echo "[entrypoint] APP_KEY está vacío. Defínelo en el .env de Docker." >&2
    exit 1
fi

# Marca de "listo" que usa el healthcheck.
rm -f /tmp/ready

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

php artisan config:cache --no-interaction
php artisan event:cache --no-interaction

# Con `set -e`, si alguno no responde el contenedor se detiene y Docker lo reintenta.
php docker/wait-for-services.php

# Planificador. crond corre en primer plano dentro de un bucle: sus trabajos
# heredan el entorno y la salida de este script (así se ven en `docker logs`) y,
# si se cae, vuelve a levantarse. Al detener el contenedor, php-fpm (PID 1)
# termina y el resto con él.

# Margen hasta el primer minuto. El latido lo actualiza www-data: debe ser su dueño.
touch /tmp/scheduler.heartbeat
chown www-data:www-data /tmp/scheduler.heartbeat

(
    while true; do
        # -d 9: errores a stderr, sin una línea de log por cada minuto.
        crond -f -d 9 -c /etc/crontabs || true
        echo "[scheduler] crond se detuvo; se reinicia en 5 segundos." >&2
        sleep 5
    done
) &
echo "[entrypoint] Planificador (crond) iniciado."

touch /tmp/ready
echo "[entrypoint] API lista."

exec "$@"
