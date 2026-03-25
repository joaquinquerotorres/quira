# Despliegue

## Railway + Docker (esta API)

El repo incluye un **`Dockerfile`** (FrankenPHP + PHP 8.4). El **CI en GitHub Actions** solo ejecuta tests (`.github/workflows/ci.yml`).

### 1. Crear el servicio

1. **New project** → **Deploy from GitHub** → repo y rama (p. ej. `main`).
2. Railway detecta el **Dockerfile** y construye la imagen.
3. Añade **MySQL** (plugin) y enlaza la variable `DATABASE_URL` que expone Railway al servicio de la API.

### 2. Variables de entorno obligatorias (resumen)

Define en el servicio las mismas claves que usarías en `.env.local` en un VPS: `APP_SECRET`, `APP_URL`, `FRONTEND_URL`, `DATABASE_URL`, `JWT_PASSPHRASE`, integraciones (`GEMINI_API_KEY`, Stripe, Twilio, Supabase, Firebase, etc.), `CORS_ALLOW_ORIGIN`, `SENTRY_DSN` si aplica.

**Proxy / HTTPS (importante):** detrás del proxy de Railway, configura al menos:

| Variable | Valor típico |
|----------|----------------|
| `SYMFONY_TRUSTED_PROXIES` | `REMOTE_ADDR` |

Symfony 8 ya lee por defecto `SYMFONY_TRUSTED_*` vía `framework.yaml` (ver referencia del bundle). Sin esto, URLs y esquemas (`https`) pueden salir mal.

### 3. Migraciones

Railway **no siempre** muestra **Release command** cuando el despliegue es solo por **Dockerfile**; solo ver **Custom start command** es normal. En este proyecto las migraciones se ejecutan en **`docker/entrypoint.sh`** al arrancar el contenedor (`doctrine:migrations:migrate` antes del `cache:warmup`). No hace falta configurarlas a mano en el panel si dejas el **start command vacío** (usa el `ENTRYPOINT` del Dockerfile).

Si en el futuro activas un **Release command** en Railway **y** mantienes el migrate en el entrypoint, las migraciones podrían ejecutarse dos veces por despliegue (la segunda suele ser no-op); en ese caso puede interesar quitar el `migrate` del entrypoint y dejar solo el release.

### 4. Arranque del contenedor

El **`docker/entrypoint.sh`**:

1. Genera el par JWT con Lexik si faltan (`lexik:jwt:generate-keypair --skip-if-exists`).
2. Ejecuta `doctrine:migrations:migrate` en `prod`.
3. Ejecuta `cache:warmup` en `prod`.
4. Arranca **FrankenPHP** escuchando en **`PORT`** (Railway lo inyecta; por defecto el Dockerfile usa `8080`).

El **`Caddyfile`** sirve `public/` como document root (equivalente a Nginx + PHP-FPM).

### 5. JWT y despliegues

El sistema de archivos del contenedor es **efímero**. Si en cada despliegue **no** persistes `config/jwt/*.pem`, el entrypoint generará un par nuevo cuando falten y **invalidará** los tokens ya emitidos.

Opciones recomendadas:

- **Volumen** en Railway montado en `config/jwt` (misma ruta que en local), **o**
- Inyectar los PEM como variables/secret y escribirlos en un script de arranque (avanzado; Lexik está configurado con rutas a ficheros).

Mantén el mismo `JWT_PASSPHRASE` entre despliegues si reutilizas claves.

### 6. Firebase u otros ficheros secretos

Si `FIREBASE_CREDENTIALS` apunta a un path (p. ej. `config/secrets/firebase_credentials.json`), ese archivo debe existir en runtime: variable multilínea, base64 + decode en un script de inicio, o volumen. No lo subas al repositorio.

### 7. Prueba local de la imagen

```bash
docker build -t quira-api:local .
docker run --rm -p 8080:8080 -e PORT=8080 -e APP_SECRET=changeme -e DATABASE_URL="mysql://..." quira-api:local
```

(Ajusta el resto de variables que necesite `cache:warmup` y la app.)

---

## Qué **no** hace falta desde GitHub

Secrets tipo `DEPLOY_HOST`, `SSH_PRIVATE_KEY`, `DEPLOY_PATH`, rsync ni SSH: eso era para un **VPS** desplegado desde Actions.

---

## Alternativa: VPS propio

Mismas variables que en Railway, normalmente en `.env.local`. Tabla de referencia:

| Variable | Uso |
|----------|-----|
| `APP_SECRET` | Obligatorio en prod |
| `APP_URL` | URL pública de la API |
| `FRONTEND_URL` | Enlaces (p. ej. recuperar contraseña) |
| `DATABASE_URL` | MySQL |
| `JWT_PASSPHRASE` | Frase del par de claves JWT |
| `MAILER_DSN` | Correo |
| Firebase / Twilio / Stripe / Supabase / `GEMINI_API_KEY` | Según funciones |
| `CORS_ALLOW_ORIGIN` | Regex de orígenes permitidos |
| `SENTRY_DSN` | (Opcional) [Sentry](https://sentry.io) |
| `SYMFONY_TRUSTED_PROXIES` | Tras proxy/Ingress (p. ej. `REMOTE_ADDR`) |

---

## Dominio y DNS

- Ajusta `CORS_ALLOW_ORIGIN` y `FRONTEND_URL` al dominio real del frontend.
- En Railway, hasta añadir dominio propio suele usarse `*.up.railway.app`.

### MySQL: `SQLSTATE[HY000] [2002] No such file or directory`

Suele ocurrir cuando el host de la URL es `localhost`: en Linux, PHP/PDO intenta un **socket Unix** y en el contenedor no existe. El `docker/entrypoint.sh` sustituye `localhost` por `127.0.0.1` para forzar TCP.

Si MySQL es **otro servicio** en Railway, **no** uses `localhost` (ni siquiera con ese truco): la API debe usar la URL interna del plugin MySQL, p. ej. `${{NombreDelServicio.MYSQL_URL}}` o `MYSQLHOST` + `MYSQLPORT` del mismo [proyecto](https://docs.railway.com/guides/mysql).
