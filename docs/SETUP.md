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
| `APP_URL` | URL base de la API (para enlaces de verificación) |

### JWT

Generar claves tras configurar `JWT_PASSPHRASE`:

```bash
php bin/console lexik:jwt:generate-keypair
```

### Firebase

| Variable | Descripción |
|----------|-------------|
| `FIREBASE_CREDENTIALS` | Ruta al JSON de credenciales del service account |

Usado para: Auth (verificación de token social), notificaciones push (FCM).

### Twilio

| Variable | Descripción |
|----------|-------------|
| `TWILIO_ACCOUNT_SID` | SID de cuenta Twilio |
| `TWILIO_AUTH_TOKEN` | Token de autenticación |
| `TWILIO_WHATSAPP_FROM` | Número/WhatsApp Business para WhatsApp |
| `TWILIO_SMS_FROM` | Número para SMS (OTP). Si está vacío en dev, PhoneVerificationService entra en modo sandbox: no se llama a Twilio y los códigos OTP se registran en el log (y en consola) para poder probar sin coste. |

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
