# syntax=docker/dockerfile:1

# --- Dependencias PHP (Composer) ---
FROM composer:2 AS vendor
WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --prefer-dist \
    --no-interaction \
    --optimize-autoloader

# --- Runtime: FrankenPHP (Caddy + PHP) ---
FROM dunglas/frankenphp:1-php8.4-bookworm

WORKDIR /app

ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV PORT=8080
# Solo build (assets:install); Railway sobreescribe en runtime
ENV APP_SECRET=build-time-placeholder

# Extensiones usadas por la API (MySQL, intl, zip, opcache…)
RUN install-php-extensions \
    pdo_mysql \
    intl \
    zip \
    opcache

COPY --from=vendor /app/vendor ./vendor
COPY . .

# Sin conexión a DB en build: solo assets estáticos
RUN php bin/console assets:install public --env=prod --no-ansi --no-debug

RUN mkdir -p var/cache var/log config/jwt public/uploads \
    && chown -R www-data:www-data var config/jwt public/uploads

COPY docker/frankenphp/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
