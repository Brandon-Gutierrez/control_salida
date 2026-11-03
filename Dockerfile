#
# API Laravel (php-fpm). El contexto de build es la raíz de este repositorio.
#
#   target prod -> código dentro de la imagen, dependencias sin dev.
#   target dev  -> sin código: docker-compose.dev.yml monta las carpetas del proyecto.

ARG PHP_VERSION=8.4

# ---------------------------------------------------------------- base
FROM php:${PHP_VERSION}-fpm-alpine AS base

RUN apk add --no-cache libpq icu-libs libzip tzdata postgresql-client unzip git \
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

WORKDIR /var/www/html
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

# ---------------------------------------------------------------- dev
FROM base AS dev

# El código se monta desde el host: OPcache debe ver los cambios.
RUN echo 'opcache.validate_timestamps=1' >> "$PHP_INI_DIR/conf.d/zz-app.ini" \
    && echo 'opcache.revalidate_freq=0' >> "$PHP_INI_DIR/conf.d/zz-app.ini"

# ---------------------------------------------------------------- prod
FROM base AS prod

# Las dependencias van en su propia capa: solo se reinstalan si cambian.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
