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

# Varios archivos
php bin/phpunit tests/Dto/StripeCheckoutInputTest.php tests/Service/StripeCheckoutSessionHandlerTest.php tests/Controller/StripeCheckoutControllerTest.php

# Incluir tests de base de datos
php bin/phpunit --group database
```

## Estructura de tests

| Directorio | Contenido |
|------------|-----------|
| tests/Controller/ | StripeCheckoutControllerTest, SocialLoginControllerTest |
| tests/Api/ | Tests de contrato/E2E contra endpoints reales (`/api/*`) |
| tests/State/ | BidProfessionalProcessor, BidAcceptanceProcessor, RequestClientProcessor, etc. |
| tests/Service/ | StripeCheckoutSessionHandlerTest, GeminiServiceTest (`diagnose`, campos de seguridad y payload hacia la API de Gemini), PredictMediaFetcherTest (anti-SSRF + descarga a Data URL) |
| tests/Repository/ | BidRepositoryTest |
| tests/Entity/ | UserTest, ProfessionalProfileTest, ReviewTest, RequestClientOriginalDescriptionValidationTest |
| tests/Doctrine/ | CurrentUserExtensionTest |
| tests/Security/ | LoginSuccessHandlerTest, RequestAddressVoterTest |
| tests/Validator/ | CleanTextValidatorTest, NoContactInfoValidatorTest |
| tests/Serializer/ | PointDenormalizerTest, RequestAssignedProfessionalNormalizerTest |
| tests/Dto/ | PredictInputTest, StripeCheckoutInputTest |
| tests/Enum/ | BidStatusTest, CategoryTest, RequestStatusTest, RiskLevelTest |

## Configuración

- `tests/bootstrap.php` - Carga .env y autoloader
- `phpunit.dist.xml` - Config PHPUnit
- Grupo `database` excluido por defecto (tests que requieren DB)
- Para tests con DB: marcar con `#[Group('database')]` y ejecutar con `--group database`
- Tras traer cambios que incluyan **migraciones Doctrine**, aplicar el esquema en `test` (misma base que usa PHPUnit): `APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction`. Sin esto, los tests que persisten entidades pueden fallar con columnas desconocidas.
- JWT en `test`:
  - `config/packages/lexik_jwt_authentication.yaml` lee `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY` y `JWT_PASSPHRASE`.
  - `phpunit.dist.xml` fuerza estos valores para `APP_ENV=test` (mismas rutas que CI), para que los tests que llaman endpoints protegidos puedan generar tokens.
  - Las claves viven en `config/jwt/*.pem` y están gitignored. Si cambias el passphrase local o faltan, regénéralas con:

```bash
JWT_PASSPHRASE='' php bin/console lexik:jwt:generate-keypair --env=test --skip-if-exists
```

## Tests de API / contrato (end-to-end)

Los tests en `tests/Api/` ejercitan endpoints reales (API Platform / controllers) y validan “contratos” críticos:

- **Base**: `tests/Api/ApiTestCase.php`
  - Limpia tablas entre tests (TRUNCATE) para evitar colisiones por claves únicas.
  - Helpers de creación de dominio: `createClientUser`, `createProfessionalUser`, `createRequest`, `createBid`, `createVisitRequest`.
  - Autenticación: genera JWT y lo envía como `Authorization: Bearer <token>` en cada request.

- **RequestsContractTest**
  - Verifica privacidad/serialización en `GET /api/requests/{id}` (teléfonos y `preciseAddress`).
  - Verifica que `clientOriginalDescription` aparece en la respuesta JSON cuando está persistido.
  - Nota: por el filtrado de `CurrentUserExtension`, un profesional ajeno sin bid/visita aceptada puede recibir 404 (esperado).

- **CanBidTest**
  - Verifica `GET /api/professionals/me/can-bid` y el cómputo del límite mensual (las retiradas se excluyen porque la bid se elimina de BD).
  - Verifica también `remainingBidsThisMonth` (entero para plan efectivo FREE, `null` para planes de pago activos).
  - Incluye **`testCanBidFalseWhenRoleProButPaidThroughExpired`**: `ROLE_PRO` con `paidThroughAt` en el pasado se trata como plan efectivo FREE para el límite mensual.

- **VisitRequestContractTest**
  - `POST .../visit-request` exige `aiDiagnosis.pricing_type = VISIT_REQUIRED` y request `PENDING`.
  - Si la request es `HIGH`, se requiere además profesional `ROLE_PRO` con `paidThroughAt` futuro.
  - Verifica el flujo de visita: solicitar visita, aceptar visita, y que tras la aceptación el pro puede ver `preciseAddress`.
  - Verifica que se crean notificaciones (`VISIT_REQUEST_CREATED`, `VISIT_REQUEST_ACCEPTED`) cuando `notifyRequestActivity` está activado.

- **PhoneVerificationApiTest**
  - Verifica `POST /api/verify/phone/send` + `POST /api/verify/phone/confirm`, incluyendo el caso “mismo número en cliente y profesional” (al confirmar se verifican ambos perfiles).
  - Verifica el caso `skipped: true` cuando el otro perfil ya tiene el mismo número verificado.

- **StripeCancelSubscriptionApiTest**
  - Verifica `POST /api/stripe/cancel-subscription` (marca `subscriptionCancelAtPeriodEnd` sin cambiar `paidThroughAt` ni roles).
  - En `test` se usa un fake de Stripe para evitar red.

- **ProfileNotificationPrefsApiTest**
  - Verifica `PATCH /api/professional_profiles/{id}` con preferencias `notify*` (merge-patch, dueño).
  - Verifica que `GET /api/users/{id}` incluye `professionalProfile.notifyRequestActivity|notifyBidActivity|notifyReviews` en `user:read`.

## Tests de comandos

- **CalibratePricingCommandTest**
  - Ejecuta `app:calibrate-pricing` con datos en BD y valida que el CSV añade nuevas subcategorías con `Zona = Córdoba` y `Complejidad` derivada del riesgo predominante.

## Tests de Stripe

- StripeCheckoutInputTest: validación del DTO
- StripeCheckoutControllerTest: controller con TestableStripeCheckoutController (mock de usuario)
- StripeCheckoutSessionHandlerTest: lógica del webhook `checkout.session.completed`
- La reconciliación **`stripe:reconcile-subscriptions`** no tiene test automatizado dedicado; usar en operaciones o tras incidencias de webhooks (ver `docs/API.md`).

## Tests de State / procesadores

- **BidProfessionalProcessorTest**: reglas de negocio (HIGH, límite mensual, teléfono) vía **`ValidationException`** coherente con el **422** de API Platform.
- **ProfessionalProfileOwnerProcessorTest**:
  - valida autoverificación de teléfono profesional cuando coincide con el cliente verificado (incluyendo formatos distintos como `+34 600 111 222` y `600111222`);
  - valida que, si no coincide o el cliente no está verificado, queda `verifiedPhone=false`;
  - valida desverificación automática al cambiar teléfono profesional a uno distinto;
  - valida obligatoriedad de `address` en alta/edición de perfil profesional.
- **ClientProfileOwnerProcessorTest**:
  - valida la lógica inversa al editar cliente: autoverificación cuando coincide con teléfono profesional verificado y no autoverificación cuando no cumple la regla.

## Tests de serialización

- **PointDenormalizerTest**: deserialización de puntos GeoJSON.
- **RequestAssignedProfessionalNormalizerTest**: normalizer de Request (GET /api/requests/{id}):
  - Inyecta `phoneNumber` en `assignedProfessional`; inyecta `avatar`, `rating`, `reviewCount` en el nodo `client`.
  - Oculta `preciseAddress` cuando el usuario no tiene permiso (VIEW_PRECISE_ADDRESS); oculta `client.phoneNumber` cuando el usuario no es el pro asignado ni tiene visita aceptada.
  - Tests de delegación: normalize, denormalize, supportsNormalization, supportsDenormalization, setSerializer, getSupportedTypes.

## Notas

- PHPUnit y `symfony serve` usan el **php.ini del entorno local**, no `docker/php/zz-quira.ini` (solo la imagen Docker de producción). Los tests de `PredictInput` no ejercitan subidas multi‑megabyte ni timeouts de red.
- StripeService no es final para permitir mocks
- Los tests del controller usan subclase TestableStripeCheckoutController que inyecta el usuario de prueba
