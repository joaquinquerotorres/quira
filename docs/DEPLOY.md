# Deploy con GitHub Actions

El workflow de CI/CD incluye un job de deploy que se ejecuta al hacer push a `main`, tras pasar los tests.

## Requisitos

- Servidor con PHP 8.4, MySQL y SSH
- Usuario SSH con acceso al directorio de la aplicación
- Clave SSH privada sin passphrase (o usa ssh-agent)

## Secrets en GitHub

Añade estos secrets en **Settings → Secrets and variables → Actions** (o en el environment `production`):

### Deploy (obligatorios)

| Secret | Descripción |
|--------|-------------|
| `DEPLOY_HOST` | IP o hostname del servidor |
| `DEPLOY_USER` | Usuario SSH |
| `DEPLOY_PATH` | Ruta donde está la app (ej. `/var/www/quira`) |
| `SSH_PRIVATE_KEY` | Clave privada SSH del deploy user |

### Aplicación (producción)

| Secret | Descripción |
|--------|-------------|
| `APP_SECRET` | Cadena aleatoria |
| `APP_URL` | URL de la API (ej. `https://api.quira.app`) |
| `FRONTEND_URL` | URL del frontend para enlaces (ej. `https://quira.app`) |
| `DATABASE_URL` | URL MySQL de producción |
| `JWT_PASSPHRASE` | Frase para las claves JWT |
| `MAILER_DSN` | DSN del mailer en producción |
| `FIREBASE_CREDENTIALS_B64` | JSON de Firebase en base64 (recomendado) |
| `TWILIO_ACCOUNT_SID` | |
| `TWILIO_AUTH_TOKEN` | |
| `TWILIO_WHATSAPP_FROM` | |
| `TWILIO_SMS_FROM` | |
| `GEMINI_API_KEY` | |
| `STRIPE_SECRET_KEY` | Clave live de Stripe |
| `STRIPE_WEBHOOK_SECRET` | Secret del webhook en producción |
| `STRIPE_PRICE_SOLVER` | Price ID live SOLVER |
| `STRIPE_PRICE_PRO` | Price ID live PRO |
| `SUPABASE_URL` | |
| `SUPABASE_SERVICE_ROLE_KEY` | |
| `SUPABASE_BUCKET_AVATARS` | |
| `SUPABASE_BUCKET_REQUESTS` | |
| `CORS_ALLOW_ORIGIN` | (Opcional) Regex de orígenes permitidos |

## Environment `production`

El job de deploy usa `environment: production`. Crea el environment en:

**Settings → Environments → New environment** → nombre: `production`

Aquí puedes:
- Añadir approval antes del deploy
- Asignar secrets solo a este environment

## Preparación del servidor

1. PHP 8.4 con extensiones: ctype, iconv, intl, json, mbstring, pdo_mysql, openssl, curl, xml
2. Composer (no obligatorio si despliegas con `vendor` incluido)
3. MySQL accesible
4. Directorio de deploy con permisos para `DEPLOY_USER`
5. Clave pública del deploy user en `~/.ssh/authorized_keys` del servidor

## Flujo del deploy

1. Tests pasan
2. `composer install --no-dev`
3. Generación de claves JWT
4. Creación de `.env.local` con secrets
5. Escritura de `config/secrets/firebase_credentials.json`
6. rsync al servidor (excluye .git, .github, var/)
7. SSH: `doctrine:migrations:migrate` y `cache:clear`

## Cambiar la rama de deploy

Por defecto solo se despliega en push a `main`. Para usar otra rama, edita el `if` del job `deploy` en `.github/workflows/ci.yml`:

```yaml
if: github.event_name == 'push' && github.ref == 'refs/heads/main'
```

## Notas

- Las claves JWT se generan en cada deploy; usa el mismo `JWT_PASSPHRASE` siempre.
- `FIREBASE_CREDENTIALS_B64`: crea el base64 así (macOS):

```bash
base64 -i config/secrets/firebase_credentials.json | pbcopy
```

- Ajusta `CORS_ALLOW_ORIGIN` para permitir el dominio de tu frontend (ej. `^https://(www\.)?quira\.app$`).

## Dominio y DNS (quira.app)

Recomendación:
- Frontend en `https://quira.app`
- API en `https://api.quira.app`

Pasos (a alto nivel):
- **A/AAAA**: apunta `api.quira.app` a la IP del servidor (y `quira.app` al hosting del frontend).
- **TLS/HTTPS**: en el servidor usa Let’s Encrypt (Nginx/Caddy). La API debe servir siempre por HTTPS.
