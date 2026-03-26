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

# JWT keys:
# - Modo por defecto: compatibilidad (si faltan, se generan) para no romper deploys.
# - Modo estricto opcional: JWT_ENFORCE_STATIC_KEYS=1 para exigir claves persistentes.
JWT_SECRET_KEY_PATH="${JWT_SECRET_KEY:-/app/config/jwt/private.pem}"
JWT_PUBLIC_KEY_PATH="${JWT_PUBLIC_KEY:-/app/config/jwt/public.pem}"
JWT_ENFORCE_STATIC_KEYS="${JWT_ENFORCE_STATIC_KEYS:-0}"
JWT_GENERATE_KEYS="${JWT_GENERATE_KEYS:-1}"

if [ -f "$JWT_SECRET_KEY_PATH" ] && [ -f "$JWT_PUBLIC_KEY_PATH" ]; then
	: "JWT keys present"
else
	if [ "$JWT_ENFORCE_STATIC_KEYS" = "1" ]; then
		echo "ERROR: faltan claves JWT en $JWT_SECRET_KEY_PATH / $JWT_PUBLIC_KEY_PATH." >&2
		echo "JWT_ENFORCE_STATIC_KEYS=1 requiere claves persistentes (volumen en /app/config/jwt o PEM inyectados)." >&2
		exit 1
	fi
	if [ "$JWT_GENERATE_KEYS" = "1" ]; then
		echo "WARN: JWT keys no encontradas; generando un nuevo par (esto invalida tokens previos si existían)." >&2
		php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-ansi
	else
		echo "ERROR: faltan claves JWT en $JWT_SECRET_KEY_PATH / $JWT_PUBLIC_KEY_PATH." >&2
		echo "Solución: activa JWT_GENERATE_KEYS=1 o monta un volumen persistente en /app/config/jwt." >&2
		exit 1
	fi
fi

# DB readiness + migraciones:
# Railway puede tardar en exponer MySQL al arrancar. Hacemos retry/backoff.
DB_WAIT_RETRIES="${DB_WAIT_RETRIES:-20}"
DB_WAIT_SECONDS="${DB_WAIT_SECONDS:-2}"
RUN_MIGRATIONS="${RUN_MIGRATIONS:-1}"

i=1
while [ "$i" -le "$DB_WAIT_RETRIES" ]; do
	if php bin/console doctrine:query:sql "SELECT 1" --env=prod --no-interaction --no-ansi >/dev/null 2>&1; then
		break
	fi
	echo "DB not ready (attempt $i/$DB_WAIT_RETRIES). Waiting ${DB_WAIT_SECONDS}s..." >&2
	sleep "$DB_WAIT_SECONDS"
	i=$((i+1))
done

if ! php bin/console doctrine:query:sql "SELECT 1" --env=prod --no-interaction --no-ansi >/dev/null 2>&1; then
	echo "ERROR: no se pudo conectar a la base de datos tras $DB_WAIT_RETRIES intentos. Revisa DATABASE_URL." >&2
	echo "DEBUG: mostrando el error real de Doctrine (doctrine:query:sql -vvv):" >&2
	php bin/console doctrine:query:sql "SELECT 1" --env=prod --no-interaction --no-ansi -vvv 1>&2 || true
	exit 1
fi

if [ "$RUN_MIGRATIONS" = "1" ]; then
	php bin/console doctrine:migrations:migrate --no-interaction --env=prod --no-ansi
fi

php bin/console cache:warmup --env=prod --no-debug --no-ansi

exec frankenphp run --config /etc/caddy/Caddyfile
