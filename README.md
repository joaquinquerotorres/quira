# Quira

Plataforma de servicios profesionales que conecta clientes con profesionales (SOLVER/PRO) para tareas del hogar y reparaciones.

## Descripción

Quira es una API REST construida con **Symfony 8** y **API Platform** que permite:

- **Clientes**: crear solicitudes de trabajo, recibir ofertas (bids) de profesionales, aceptar ofertas y dejar reseñas
- **Profesionales**: crear perfil, pujar en solicitudes, gestionar suscripciones (SOLVER/PRO) vía Stripe
- **IA**: diagnóstico preliminar de problemas mediante Google Gemini
- **Notificaciones**: WhatsApp (Twilio), push (Firebase FCM) y email

## Tecnologías

| Componente | Tecnología |
|------------|------------|
| Backend | PHP 8.4, Symfony 8.0 |
| API | API Platform 4.2 |
| ORM | Doctrine ORM 3.x |
| Autenticación | Lexik JWT, gesdinet refresh token, Firebase social login |
| Pagos | Stripe (suscripciones) |
| IA | Google Gemini 2.5 Flash |
| Base de datos | MySQL 8 (con soporte espacial) |
| Storage | Supabase Storage, Firebase Storage |
| Notificaciones | Twilio (WhatsApp, SMS), Firebase Cloud Messaging |
| Tests | PHPUnit 12 |
| Análisis estático | PHPStan 2 (nivel 5, Symfony + Doctrine) |

## Inicio rápido

```bash
# Clonar e instalar dependencias
composer install

# Configurar .env.local (copiar desde .env y rellenar valores)
cp .env .env.local

# Crear base de datos y migraciones
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

# Cargar datos de prueba (opcional)
php bin/console doctrine:fixtures:load --append

# Generar claves JWT
php bin/console lexik:jwt:generate-keypair

# Servidor de desarrollo
symfony serve
```

Documentación de Swagger: `http://localhost:8000/api/docs`

### Contrato suscripción (profesional)

La **fuente de verdad operativa** del plan de pago es `professionalProfile.paidThroughAt` (ISO 8601 en JSON, anidado bajo el usuario en `GET /api/users/{id}` con grupos `user:read`), no solo `ROLE_PRO` / `ROLE_SOLVER`.

- **Vigente:** `paidThroughAt != null` y fecha **estrictamente posterior** a “ahora” → límites y permisos de plan de pago.
- **`paidThroughAt === null`:** el backend trata como **sin periodo de pago conocido** (mismo comportamiento que caducado para límites y HIGH).
- **`subscriptionCancelAtPeriodEnd`:** refleja `cancel_at_period_end` en Stripe (cancelación al final del periodo ya programada).

Tras Checkout, el webhook actualiza la BD; si el redirect llega antes que el webhook, se puede llamar `POST /api/stripe/sync-subscription` (JWT) y luego refrescar el usuario. Reconciliación batch: `php bin/console stripe:reconcile-subscriptions`.

### Operador admin (panel `/api/admin/*`)

One-off — **no** fixtures ni migraciones con passwords. Secrets solo en variables de entorno:

```bash
railway variables set ADMIN_EMAIL=admin@quira.app ADMIN_PASSWORD='***'
railway run php bin/console app:admin:ensure

# Rotar password del admin existente (lee ADMIN_PASSWORD):
railway run php bin/console app:admin:ensure --reset-password
```

Detalle: [docs/ADMIN.md](docs/ADMIN.md).

## Documentación

| Documento | Contenido |
|-----------|-----------|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Entidades, relaciones, estructura del código |
| [docs/API.md](docs/API.md) | Endpoints y recursos de la API |
| [docs/ADMIN.md](docs/ADMIN.md) | Panel admin (`ROLE_ADMIN`), stats overview, `app:admin:ensure` |
| [docs/SETUP.md](docs/SETUP.md) | Configuración completa y variables de entorno |
| [docs/FEATURES.md](docs/FEATURES.md) | Funcionalidades (auth, Stripe, IA, notificaciones, etc.) |
| [docs/TESTING.md](docs/TESTING.md) | Guía de tests |
| [docs/DEPLOY.md](docs/DEPLOY.md) | Deploy (Dockerfile FrankenPHP, Railway, variables) |

## Licencia

Propietaria.
