#
# Imágenes de producción de la API. El contexto de build es la raíz de este
# repositorio; docker/docker-compose.yml construye las dos:
#   target nginx -> servidor web de la API (HTTP/HTTPS).
#   target prod  -> Laravel con php-fpm y el planificador (crond). Es la última
#                   etapa: la que sale con un `docker build .` sin target.

ARG PHP_VERSION=8.4

# ---------------------------------------------------------------- nginx
FROM nginx:1.27-alpine AS nginx

RUN apk add --no-cache openssl

COPY docker/nginx/api.conf.template /etc/nginx/templates/default.conf.template
COPY docker/nginx/api-locations.conf docker/nginx/fastcgi-laravel.conf /etc/nginx/
# Si se monta /etc/nginx/certs sin certificado, genera uno autofirmado (ver el script).
COPY --chmod=755 docker/nginx/10-selfsigned-cert.sh /docker-entrypoint.d/10-selfsigned-cert.sh

EXPOSE 80 443

# ---------------------------------------------------------------- API (php-fpm + crond)
FROM php:${PHP_VERSION}-fpm-alpine AS prod

RUN apk add --no-cache libpq icu-libs libzip tzdata unzip git \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev icu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql bcmath intl zip opcache \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Producción: sin errores en pantalla y OPcache sin revalidar archivos.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=10000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'expose_php=0'; \
        echo 'memory_limit=256M'; \
        echo 'date.timezone=America/La_Paz'; \
    } > "$PHP_INI_DIR/conf.d/zz-app.ini"

COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY --chmod=755 docker/healthcheck.sh /usr/local/bin/healthcheck.sh

# Planificador: el crond de BusyBox (ya viene en Alpine) llama a `schedule:run`
# cada minuto. Se quitan las tareas de root que trae la imagen: no se usan.
RUN rm -f /etc/crontabs/root
COPY --chmod=644 docker/crontab /etc/crontabs/www-data

WORKDIR /var/www/html
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

# Las dependencias van en su propia capa: solo se reinstalan si cambian.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
