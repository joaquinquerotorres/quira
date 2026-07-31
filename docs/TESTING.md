# Guía de tests

## Análisis estático (PHPStan)

Nivel **5**, extensiones **Symfony** + **Doctrine**. Requiere el XML del contenedor en entorno **test** (mismo que PHPUnit):

```bash
APP_ENV=test php bin/console cache:warmup --env=test
composer phpstan
```

Regenerar baseline (solo cuando se asumen deuda técnica a propósito): `composer phpstan:baseline`.

## Ejecución

```bash
# Todos los tests (excluye grupo database por defecto)
php bin/phpunit

# Solo los tests de API/contrato (requieren DB + JWT keys)
php bin/phpunit tests/Api

# Un archivo concreto
php bin/phpunit tests/Dto/StripeCheckoutInputTest.php

# Incluir tests de base de datos
php bin/phpunit --group database
```

## Estructura de tests

| Directorio | Contenido |
|------------|-----------|
| `tests/Controller/` | StripeCheckoutControllerTest, StripeCancelSubscriptionControllerTest, SocialLoginControllerTest, PasswordResetControllerTest, VerificationControllerTest |
| `tests/Api/` | Contratos E2E contra `/api/*` (ver sección siguiente) |
| `tests/State/` | BidProfessional/Acceptance/Withdraw, RequestClient/Delete/Question, Review, UserRegistration, Professional/ClientProfileOwner, CalendarEventOwner |
| `tests/Service/` | StripeCheckoutSessionHandler, GeminiService (+ CreateCache), ContactInfoDetector, PricingCatalogService, PricingClampService, PredictMediaFetcher, PredictMediaLimits, NotificationService, EmailVerificationService, PasswordResetService |
| `tests/Command/` | CalibratePricingCommandTest (`#[Group('database')]`) |
| `tests/Repository/` | BidRepositoryTest |
| `tests/Entity/` | UserTest, ProfessionalProfileTest, ReviewTest, RequestClientOriginalDescriptionValidationTest |
| `tests/Doctrine/` | CurrentUserExtensionTest |
| `tests/Security/` | LoginSuccessHandlerTest, RequestAddressVoterTest |
| `tests/Validator/` | CleanTextValidatorTest, NoContactInfoValidatorTest, CifValidatorTest |
| `tests/Serializer/` | PointDenormalizerTest, RequestAssignedProfessionalNormalizerTest |
| `tests/Dto/` | PredictInputTest, StripeCheckoutInputTest |
| `tests/Enum/` | BidStatusTest, CategoryTest (22 casos + `label()` / `tryFromLabel`), RequestStatusTest, RiskLevelTest |

## Configuración

- `tests/bootstrap.php` - Carga .env y autoloader
- `phpunit.dist.xml` - Config PHPUnit
- Grupo `database` excluido por defecto (tests que requieren DB)
- Para tests con DB: marcar con `#[Group('database')]` y ejecutar con `--group database`
- Tras traer cambios que incluyan **migraciones Doctrine**, aplicar el esquema en `test`: `APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction`
- JWT en `test`:
  - `config/packages/lexik_jwt_authentication.yaml` lee `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY` y `JWT_PASSPHRASE`.
  - `phpunit.dist.xml` fuerza estos valores para `APP_ENV=test` (mismas rutas que CI).
  - Las claves viven en `config/jwt/*.pem` (gitignored). Si faltan:

```bash
JWT_PASSPHRASE='' php bin/console lexik:jwt:generate-keypair --env=test --skip-if-exists
```

## Tests de API / contrato (end-to-end)

Los tests en `tests/Api/` ejercitan endpoints reales y validan contratos críticos:

- **Base**: `tests/Api/ApiTestCase.php` — TRUNCATE entre tests; helpers de dominio; JWT Bearer.
- **RequestsContractTest** — privacidad/serialización en `GET /api/requests/{id}` (teléfonos, `preciseAddress`, `clientOriginalDescription`). Profesional ajeno sin relación puede recibir 404 (`CurrentUserExtension`).
- **BidPricingTypeContractTest** — `POST /api/bids`: FIXED|RANGE libre vs `Request.pricingType`; `comment` obligatorio solo en RANGE (`BID_RANGE_COMMENT_REQUIRED`).
- **CanBidTest** — `GET /api/professionals/me/can-bid`, límite mensual, `remainingBidsThisMonth`; `ROLE_PRO` con `paidThroughAt` caducado = FREE efectivo.
- **VisitRequestContractTest** — `POST .../visit-request` exige `pricingType` / `aiDiagnosis.pricing_type = VISIT_REQUIRED` y request `PENDING`; HIGH requiere PRO + `paidThroughAt` futuro; flujo aceptar visita + `preciseAddress`; notificaciones `VISIT_REQUEST_*`.
- **ReviewsContractTest** — `?target=` / `?author=` del perfil; privacidad (no listar ajenas); `?request=&author=` self-check; campos `targetName`, `requestTitle`, `authorIsProfessional`.
- **CalendarEventsContractTest** — `request` embebido con `id`; POST upsert; filtro `startsAt[after]/[before]`; PATCH y listado coherentes en `startsAt`.
- **PhoneVerificationApiTest** — send/confirm OTP; mismo número en ambos perfiles; `skipped: true`.
- **StripeCancelSubscriptionApiTest** — `POST /api/stripe/cancel-subscription` (fake Stripe en test).
- **ProfileNotificationPrefsApiTest** — PATCH preferencias `notify*` y lectura en `user:read`.
- **PasswordResetFlowTest** — forgot/reset password end-to-end.
- **RequestAndBidChoiceValidationTest** — validación de choices (p. ej. `desiredExecutionTime`).
- **RequestQuestionAnswerMediaUrlsTest** — tope de `answerMediaUrls` (máx. 3).

## Tests de comandos

- **CalibratePricingCommandTest** (`database`) — ejecuta `app:calibrate-pricing` con pujas aceptadas y comprueba que se crean/actualizan filas en **`pricing_rate`** (zona Córdoba, complejidad según riesgo dominante).

## Tests de Stripe

- StripeCheckoutInputTest, StripeCheckoutControllerTest, StripeCheckoutSessionHandlerTest
- StripeCancelSubscriptionControllerTest / ApiTest
- La reconciliación `stripe:reconcile-subscriptions` no tiene test automatizado dedicado (uso operativo; ver `docs/API.md`).

## Tests de State / procesadores

- **BidProfessionalProcessorTest**: reglas HIGH / límite mensual / teléfono → `ValidationException` (422).
- **BidAcceptanceProcessorTest** / **BidWithdrawProcessorTest**
- **ProfessionalProfileOwnerProcessorTest** / **ClientProfileOwnerProcessorTest**: autoverificación cruzada de teléfonos; obligatoriedad de `address` en pro.
- **RequestClientProcessorTest**, **RequestDeleteProcessorTest**, **RequestQuestionProcessorTest**, **ReviewProcessorTest**, **UserRegistrationProcessorTest**, **CalendarEventOwnerProcessorTest**

## Tests de IA / precios

- **GeminiServiceTest** / **GeminiServiceCreateCacheTest** — payload `diagnose` / `createCache`.
- **PricingCatalogServiceTest** — mapeo label/código (todas las `Category`), `resolveZones`.
- **PricingClampServiceTest** — híbrido A+C (VISIT_REQUIRED, IMMEDIATE +30 %, match por zona/subcategoría, sin match).
- **PredictMediaFetcherTest** / **PredictMediaLimitsTest** — anti-SSRF y topes de tamaño.

## Tests de serialización

- **PointDenormalizerTest**, **RequestAssignedProfessionalNormalizerTest** — `phoneNumber` / `preciseAddress` / avatar-rating según permisos.

## Notas

- PHPUnit y `symfony serve` usan el **php.ini local**, no `docker/php/zz-quira.ini` (imagen Docker). Los tests de `PredictInput` no ejercitan subidas multi‑megabyte.
- StripeService no es final para permitir mocks.
- Los tests del checkout usan subclase `TestableStripeCheckoutController` que inyecta el usuario de prueba.
