#!/bin/sh
set -e
cd /app

if [ -z "${DATABASE_URL:-}" ]; then
	echo 'ERROR: DATABASE_URL no está definida. En Railway: Variables → referencia al servicio MySQL (p. ej. ${{NombreServicio.MYSQL_URL}}).' >&2
	exit 1
fi

# PHP PDO con host "localhost" usa socket Unix (/var/run/mysqld/...), inexistente en el contenedor → SQLSTATE[2002] "No such file or directory".
# Sustituir por 127.0.0.1 fuerza TCP (solo aplica si MySQL escucha ahí; entre servicios Railway usa otro hostname y no toca esto).
export DATABASE_URL=$(printf '%s' "$DATABASE_URL" | sed -e 's/@localhost:/@127.0.0.1:/g' -e 's|@localhost/|@127.0.0.1/|g')

# Claves JWT: el volumen del contenedor es efímero; generar si faltan (JWT_PASSPHRASE en env)
php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-ansi

# DB: Railway a menudo no ofrece "Release command" con Dockerfile; migraciones al arrancar
php bin/console doctrine:migrations:migrate --no-interaction --env=prod --no-ansi

php bin/console cache:warmup --env=prod --no-debug --no-ansi

exec frankenphp run --config /etc/caddy/Caddyfile
