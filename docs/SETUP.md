# Configuración y despliegue

## Requisitos

- PHP >= 8.4
- Composer
- MySQL 8+ (con soporte spatial)
- Extensions PHP: ctype, iconv, json, mbstring, pdo_mysql, openssl, curl, intl, xml

## Instalación

```bash
git clone <repo>
cd quira
composer install
```

## Variables de entorno

Copia `.env` a `.env.local` y configura los valores:

```bash
cp .env .env.local
```

### Obligatorias

| Variable | Descripción | Ejemplo |
|----------|-------------|---------|
| `DATABASE_URL` | Conexión MySQL | `mysql://user:pass@127.0.0.1:3306/quira_db?serverVersion=8.0&charset=utf8mb4` |
| `APP_SECRET` | Clave secreta Symfony | Cadena aleatoria |
| `JWT_PASSPHRASE` | Frase para claves JWT | Cadena (puede estar vacía en dev) |

### Autenticación y usuarios

| Variable | Descripción |
|----------|-------------|
| `FRONTEND_URL` | URL del frontend para enlaces de verificación y recuperación de contraseña |

Operador admin (panel `/api/admin/*`):

```bash
php bin/console app:create-admin admin@quira.app --password='***'
# o promocionar: php bin/console app:create-admin existing@quira.app --promote-only
```

Ver `docs/ADMIN.md`.

### JWT

Generar claves tras configurar `JWT_PASSPHRASE`:

```bash
php bin/console lexik:jwt:generate-keypair
```

### Firebase

| Variable | Descripción |
|----------|-------------|
| `FIREBASE_CREDENTIALS` | Ruta al JSON de credenciales del service account |
| `FIREBASE_CREDENTIALS_B64` | (Opcional, deploy) mismo JSON en base64; el entrypoint lo materializa en disco |

Usado para: Auth (verificación de token social), notificaciones push (FCM).

### Twilio

| Variable | Descripción |
|----------|-------------|
| `TWILIO_ACCOUNT_SID` | SID de cuenta Twilio |
| `TWILIO_AUTH_TOKEN` | Token de autenticación |
| `TWILIO_WHATSAPP_FROM` | Número/WhatsApp Business para WhatsApp |
| `TWILIO_SMS_FROM` | Número **SMS** de Twilio (E.164, p. ej. `+1xxx`), no el remitente de WhatsApp. En **cuenta trial** solo puedes enviar a números verificados en la consola Twilio. Si está vacío: modo sandbox (OTP solo en logs, sin llamada a la API). Si `/api/verify/phone/send` devuelve 500, revisa credenciales en Railway y el mensaje de error de Twilio en los logs del servidor. |

### Stripe

| Variable | Descripción |
|----------|-------------|
| `STRIPE_SECRET_KEY` | Clave secreta Stripe |
| `STRIPE_WEBHOOK_SECRET` | Secreto del webhook (Stripe CLI o Dashboard) |
| `STRIPE_PRICE_SOLVER` | Price ID suscripción SOLVER (ej: `price_xxx`) |
| `STRIPE_PRICE_PRO` | Price ID suscripción PRO |

### Gemini (IA)

| Variable | Descripción |
|----------|-------------|
| `GEMINI_API_KEY` | API key de Google AI Studio |
| `GEMINI_MODEL` | Modelo usado por `diagnose`/`POST /api/predict` (default `gemini-2.5-flash`). Si falla, el backend reintenta con un flash de respaldo. Una sola llamada: `safe`/`safety_reason` + `in_scope`/`out_of_scope_reason`. Preferir predict por URL (`imageUrl`/`audioUrl`/`videoUrl`) tras subir a Supabase. |

### Notificaciones (canal por faceta)

Valores: `PUSH`, `EMAIL` o `WHATSAPP` (mayúsculas o minúsculas).

| Variable | Descripción |
|----------|-------------|
| `NOTIFICATIONS_PRO` | Canal en faceta profesional con `ROLE_PRO` |
| `NOTIFICATIONS_SOLVER` | Faceta profesional con `ROLE_SOLVER` |
| `NOTIFICATIONS_FREE` | Faceta profesional con `ROLE_FREE` (u otros sin PRO/SOLVER) |
| `NOTIFICATIONS_CLIENT` | Faceta cliente |

### Messenger / async

| Variable | Descripción |
|----------|-------------|
| `MESSENGER_TRANSPORT_DSN` | Transporte Doctrine por defecto (`doctrine://default?auto_setup=0`). En producción Railway el worker consume el transporte `async` (ver `docs/DEPLOY.md`). |

### Observabilidad / URLs

| Variable | Descripción |
|----------|-------------|
| `DEFAULT_URI` | URI base Symfony (dev) |
| `SENTRY_DSN` | DSN Sentry (opcional; vacío = desactivado) |
| `EMAIL_LOGO_URL` | Logo en plantillas de email (opcional) |

Variables solo de contenedor Railway (`CONTAINER_ROLE`, `RUN_MIGRATIONS`, `JWT_GENERATE_KEYS`, `MESSENGER_TIME_LIMIT`, …): ver `docs/DEPLOY.md`.

### Supabase Storage

| Variable | Descripción |
|----------|-------------|
| `SUPABASE_URL` | URL del proyecto Supabase |
| `SUPABASE_SERVICE_ROLE_KEY` | Service role key (¡no exponer en frontend!) |
| `SUPABASE_BUCKET_AVATARS` | Nombre bucket avatares |
| `SUPABASE_BUCKET_REQUESTS` | Nombre bucket media de requests |

### Mailer

| Variable | Descripción |
|----------|-------------|
| `MAILER_DSN` | DSN del mailer. En dev: `null://null` o `smtp://localhost:1025` (MailHog) |

#### Probar emails en local

- **Opción recomendada**: MailHog.
- Arranque rápido con Docker:

```bash
docker run --rm -d \
  -p 1025:1025 \
  -p 8025:8025 \
  mailhog/mailhog
```

- Configura en `.env.local`:

```env
MAILER_DSN=smtp://localhost:1025
```

- Todos los correos (verificación email, reset password, notificaciones de fallback) aparecerán en la UI de MailHog:
  - `http://localhost:8025`

### CORS

| Variable | Descripción |
|----------|-------------|
| `CORS_ALLOW_ORIGIN` | Regex de orígenes permitidos. Por defecto: localhost/127.0.0.1 |

## Base de datos

```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
```

### Fixtures (opcional)

```bash
php bin/console doctrine:fixtures:load --append
```

## Servidor de desarrollo

```bash
symfony serve
# o
php -S localhost:8000 -t public
```

API: `http://localhost:8000`  
Swagger: `http://localhost:8000/api/docs`

## Docker (compose)

Si usas `compose.yaml`, arranca los servicios:

```bash
docker compose up -d
```

Ajusta `DATABASE_URL` en `.env.local` si la DB corre en Docker.

## Webhook de Stripe (desarrollo)

Para recibir eventos en local:

```bash
stripe listen --forward-to localhost:8000/api/stripe/webhook
```

Usa el webhook secret que muestra el comando en `STRIPE_WEBHOOK_SECRET`.

### Producción / Dashboard

En el endpoint `https://TU_API/api/stripe/webhook` suscribe al menos:

`checkout.session.completed`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `customer.subscription.paused`, `customer.subscription.resumed`, `invoice.paid`, `invoice.payment_failed`, `invoice.payment_succeeded`, `invoice.updated`.

Los eventos se registran como procesados (tabla `stripe_webhook_event`) para **idempotencia** ante reintentos de Stripe.

### Reconciliación

Si hubo caídas de red, 5xx o `STRIPE_WEBHOOK_SECRET` incorrecto durante un tiempo:

```bash
php bin/console stripe:reconcile-subscriptions
# o un solo usuario:
php bin/console stripe:reconcile-subscriptions --user-id=42
```
