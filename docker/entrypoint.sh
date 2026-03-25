#!/bin/sh
set -e
cd /app

# Claves JWT: el volumen del contenedor es efímero; generar si faltan (JWT_PASSPHRASE en env)
php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-ansi

# DB: Railway a menudo no ofrece "Release command" con Dockerfile; migraciones al arrancar
php bin/console doctrine:migrations:migrate --no-interaction --env=prod --no-ansi

php bin/console cache:warmup --env=prod --no-debug --no-ansi

exec frankenphp run --config /etc/caddy/Caddyfile
