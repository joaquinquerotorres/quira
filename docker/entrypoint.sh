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

# Firebase credentials file (optional):
# If FIREBASE_CREDENTIALS_B64 is present, write JSON to FIREBASE_CREDENTIALS path.
if [ -n "${FIREBASE_CREDENTIALS_B64:-}" ]; then
	FIREBASE_CREDENTIALS_PATH="${FIREBASE_CREDENTIALS:-config/secrets/firebase_credentials.json}"
	mkdir -p "$(dirname "/app/$FIREBASE_CREDENTIALS_PATH")"
	if printf '%s' "$FIREBASE_CREDENTIALS_B64" | base64 --decode > "/app/$FIREBASE_CREDENTIALS_PATH" 2>/dev/null; then
		:
	else
		# GNU coreutils usa --decode; algunas imágenes aceptan -d.
		printf '%s' "$FIREBASE_CREDENTIALS_B64" | base64 -d > "/app/$FIREBASE_CREDENTIALS_PATH"
	fi
	chmod 600 "/app/$FIREBASE_CREDENTIALS_PATH" || true
fi

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
# Importante: no dependemos de comandos Doctrine para el readiness check (pueden no estar disponibles).
DB_WAIT_RETRIES="${DB_WAIT_RETRIES:-20}"
DB_WAIT_SECONDS="${DB_WAIT_SECONDS:-2}"
RUN_MIGRATIONS="${RUN_MIGRATIONS:-1}"

i=1
while [ "$i" -le "$DB_WAIT_RETRIES" ]; do
	if php -r '
$url = getenv("DATABASE_URL") ?: "";
if ($url === "") { fwrite(STDERR, "DATABASE_URL empty\n"); exit(2); }
$parts = parse_url($url);
if (!$parts || ($parts["scheme"] ?? "") !== "mysql") { fwrite(STDERR, "DATABASE_URL not mysql\n"); exit(2); }
$host = $parts["host"] ?? "localhost";
$port = (int)($parts["port"] ?? 3306);
$user = $parts["user"] ?? "";
$pass = $parts["pass"] ?? "";
$db = ltrim($parts["path"] ?? "", "/");
$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 2,
    ]);
    $pdo->query("SELECT 1");
    exit(0);
} catch (Throwable $e) {
    // Silencio: el bucle imprime el contador y reintenta.
    exit(1);
}
' >/dev/null 2>&1; then
		break
	fi
	echo "DB not ready (attempt $i/$DB_WAIT_RETRIES). Waiting ${DB_WAIT_SECONDS}s..." >&2
	sleep "$DB_WAIT_SECONDS"
	i=$((i+1))
done

if ! php -r '
$url = getenv("DATABASE_URL") ?: "";
$parts = parse_url($url);
$host = $parts["host"] ?? "localhost";
$port = (int)($parts["port"] ?? 3306);
$user = $parts["user"] ?? "";
$pass = $parts["pass"] ?? "";
$db = ltrim($parts["path"] ?? "", "/");
$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 2,
    ]);
    $pdo->query("SELECT 1");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: DB connect failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
' >/dev/null; then
	echo "ERROR: no se pudo conectar a la base de datos tras $DB_WAIT_RETRIES intentos. Revisa DATABASE_URL/red/credenciales." >&2
	exit 1
fi

if [ "$RUN_MIGRATIONS" = "1" ]; then
	php bin/console doctrine:migrations:migrate --no-interaction --env=prod --no-ansi
fi

php bin/console cache:warmup --env=prod --no-debug --no-ansi

# Worker de Messenger (segundo servicio en Railway):
# CONTAINER_ROLE=worker → consume cola async (predict, Chat/SMS notifier).
# La API web deja CONTAINER_ROLE vacío o "web".
CONTAINER_ROLE="${CONTAINER_ROLE:-web}"
if [ "$CONTAINER_ROLE" = "worker" ]; then
	echo "Starting Messenger worker (async)…" >&2
	# time-limit: Railway reinicia el proceso periódicamente (evita fugas de memoria).
	exec php bin/console messenger:consume async \
		--time-limit="${MESSENGER_TIME_LIMIT:-3600}" \
		--memory-limit="${MESSENGER_MEMORY_LIMIT:-128M}" \
		--sleep="${MESSENGER_SLEEP:-1}" \
		-vv \
		--env=prod \
		--no-ansi
fi

exec frankenphp run --config /etc/caddy/Caddyfile
