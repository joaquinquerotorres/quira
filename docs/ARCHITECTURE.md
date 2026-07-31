# Arquitectura

## Estructura del proyecto

- `config/` - Configuración Symfony
- `migrations/` - Migraciones Doctrine (incluyen datos iniciales de `pricing_rate`)
- `src/Command/` - Comandos consola
- `src/Controller/` - Controladores HTTP
- `src/DataFixtures/` - Datos de prueba
- `src/Doctrine/` - Extensiones Doctrine
- `src/Dto/` - Data Transfer Objects
- `src/Entity/` - Entidades Doctrine
- `src/Enum/` - Enums (`BidStatus`, `RequestStatus`, `Category`, `RiskLevel`, `NotificationAudience`)
- `src/EventListener/` - Listeners (notificaciones)
- `src/Message/` / `src/MessageHandler/` - Mensajes Messenger (`AnalyzePredictMessage`)
- `src/Repository/` - Repositorios
- `src/Security/` - LoginSuccessHandler, Voters
- `src/Serializer/` - PointDenormalizer, RequestAssignedProfessionalNormalizer (decora item normalizer; al serializar Request: inyecta `phoneNumber` en `assignedProfessional`, asegura `avatar`/`rating`/`reviewCount` en `client`, oculta `preciseAddress` o `client.phoneNumber` según permisos)
- `src/Service/` - Lógica de negocio
- `src/State/` - Procesadores API Platform
- `src/Validator/` - Validadores personalizados
- `tests/` - Tests PHPUnit
- `docker/` - Dockerfile FrankenPHP, `entrypoint.sh`, `php/zz-quira.ini`

## Entidades y relaciones

### Entidades principales

| Entidad | Descripción |
|---------|-------------|
| User | email, roles, FCM token, Firebase UID, Stripe customer ID, `isVerifiedPhone()` calculado a partir de los perfiles; en `user:read` el plan operativo va en el `professionalProfile` anidado (`paidThroughAt`, `subscriptionCancelAtPeriodEnd`) |
| ClientProfile | Perfil cliente: nombre, teléfono, avatar, `rating`, `reviewCount`, `verifiedPhone`, preferencias `notify*` |
| ProfessionalProfile | Perfil profesional: bio, skills, `paidThroughAt`, ubicación (`locationPoint` + `serviceRadiusKm`), `verifiedPhone`, `subscriptionCancelAtPeriodEnd`, `rating`, `reviewCount`, `createdAt` (alta en Quira), CIF/`verifiedTaxId`, preferencias `notify*` |
| Request | Solicitud: título, `description`, opcional `clientOriginalDescription`, `estimatedPriceMin/Max` (céntimos), estado, categoría, `riskLevel`, `pricingType`, `aiDiagnosis`, media, `VisitRequest` asociadas |
| Bid | Oferta de profesional: precio, comentario, estado (`PENDING`/`ACCEPTED`/`REJECTED`/`COMPLETED`). Sin `origin` ni FK a visita |
| VisitRequest | Visita de valoración: `Request` + `ProfessionalProfile`, `status` (PENDING/ACCEPTED/REJECTED), `note`, timestamps |
| CalendarEvent | Evento de agenda del profesional (dueño vía `CalendarEventOwnerProcessor`) |
| Review | Reseña (author/target User + Request); listado acotado a author\|target = yo; filtros request/author/target |
| RequestQuestion | Pregunta/respuesta en una solicitud (`answerMediaUrls` máx. 3) |
| Notification | Notificación in-app |
| GeminiCache | Registro local del `cachedContent` remoto de Gemini (`cacheId`, `model`, `contentHash`, `zoneKey`, `expiresAt`) |
| PricingRate | Tarifa de catálogo en BD: `categoryCode`/`categoryLabel`, subcategoría, zona, min/max céntimos, unidad, complejidad |
| PredictTask | Tarea de análisis IA: URLs de media, estado, resultado JSON (flujo híbrido async) |
| StripeWebhookEvent | Id de evento Stripe (`evt_*`) procesado (idempotencia) |
| VerificationToken | Token de verificación de email / reset de contraseña |
| RefreshToken | Token JWT refresh |

### Relaciones

- User 1:1 ClientProfile, 1:1 ProfessionalProfile
- ClientProfile 1:N Request
- Request N:1 ProfessionalProfile (asignado al aceptar bid)
- Request 1:N Bid, 1:N RequestQuestion, 1:N VisitRequest
- Bid N:1 Request, N:1 User (profesional)
- VisitRequest N:1 Request, N:1 ProfessionalProfile
- Review N:1 Request, N:1 User (autor y target)
- CalendarEvent N:1 Request, N:1 ProfessionalProfile (única por par request+pro)

### Enums

- **BidStatus:** `PENDING`, `ACCEPTED`, `REJECTED`, `COMPLETED`
- **RequestStatus:** `PENDING`, `PENDING_APPROVAL`, `ACCEPTED`, `COMPLETED`
- **Category (22):** `PLUMBING`, `ELECTRICITY`, `MASONRY`, `HVAC`, `DIY`, `PAINTING`, `GARDENING`, `CLEANING`, `APPLIANCES`, `MOVING`, `LOCKSMITH`, `POOL`, `SEWING`, `BLINDS`, `GLAZING`, `FURNITURE`, `CLEAROUT`, `PEST_CONTROL`, `SMART_HOME`, `BEAUTY`, `PETS`, `CARE` — etiquetas ES vía `Category::label()`
- **RiskLevel:** `LOW`, `MEDIUM`, `HIGH`
- **NotificationAudience:** `Client`, `Professional` (faceta del envío en `NotificationService`)

## Servicios

| Servicio | Responsabilidad |
|----------|-----------------|
| StripeService | Checkout, listado de suscripciones por customer, cancelación al final de periodo |
| StripeCheckoutSessionHandler | Webhook `checkout.session.completed` (roles + `paidThroughAt` inicial) |
| StripeWebhookProcessor | Orquesta webhooks Stripe con logs e idempotencia (`stripe_webhook_event`) |
| StripeSubscriptionSyncService | Alinea `paidThroughAt` y `subscriptionCancelAtPeriodEnd` con objetos Subscription / Invoice de Stripe |
| ProfessionalSubscriptionService | `hasActivePaidSubscription` / límites efectivos FREE según `paidThroughAt` |
| NotificationService | WhatsApp, push FCM, email (canal según audiencia/roles/preferencias, con fallback escalonado) |
| GeminiService | Diagnose/predict con `GEMINI_MODEL` + catálogo/cache; PASO 1A `safe`/`safety_reason` + PASO 1B `in_scope`/`out_of_scope_reason` (1 sola llamada); `createCache` |
| ContactInfoDetector | Heurística texto (email/teléfono ES); refuerza `safe=false` post-diagnose y alimenta `NoContactInfo` |
| GeminiCacheService | `cachedContents` Gemini: lookup por model+hash+zona, lock MySQL, degradación sin caché |
| PricingCatalogService | Catálogo BD, resolución de zonas, slice CSV en memoria, content hash |
| PricingClampService | Post-diagnose (híbrido A+C): acota `estimated_price_*` al rango `pricing_rate` |
| PredictMediaFetcher | Descarga media de URLs públicas Supabase (anti-SSRF) a Data URL para Gemini |
| PredictMediaLimits | Topes de tamaño (imagen 10 MB, audio 12 MB, vídeo 40 MB); expuestos en upload-ticket |
| SupabaseUploadTicketService | URLs firmadas Supabase |
| SocialAuthService | Verificación Firebase |
| EmailVerificationService | Emails de verificación |
| PasswordResetService | Forgot/reset password (tokens + email) |
| PhoneVerificationService | OTP por SMS/sandbox, verificación por perfil |
| PhoneComparisonService | Normalización/comparación de teléfonos (autoverificación cruzada de perfiles) |
| ProfessionalVerificationService | Recalcula `ProfessionalProfile.isVerified` (email + teléfono + CIF si PRO) |
| MediaService | Guardado de media (legacy local) |

## Messenger

- Transporte **`async`** (Doctrine `messenger_messages`): `AnalyzePredictMessage` → `AnalyzePredictMessageHandler` (worker Railway: `CONTAINER_ROLE=worker`).
- Transporte **`sync`**: `SendEmailMessage` (correo en la misma petición HTTP).

## Comandos consola relevantes

- `stripe:reconcile-subscriptions`: sincroniza desde la API de Stripe todos los usuarios con `stripe_customer_id` (o `--user-id=`), por si faltó un webhook.
- `app:calibrate-pricing`: analiza requests con diagnosis IA + pujas aceptadas y ajusta `pricing_rate` (invalida caché Gemini):
  - Reescala rangos por subcategoría (factor limitado a ±30 %).
  - Crea filas nuevas (zona Córdoba, complejidad según riesgo).
- `app:test-mail`: envía un correo de prueba (diagnóstico de `MAILER_DSN`).

## State Processors

- UserRegistrationProcessor: hash contraseña, roles
- RequestClientProcessor: asigna cliente; usa solo `aiDiagnosis.safe` (no `in_scope`) para `PENDING_APPROVAL`
- BidProfessionalProcessor: valida profesional, teléfono, límite mensual y HIGH según `paidThroughAt`; `pricingType` FIXED|RANGE libre (no acoplado a Request); `comment` obligatorio en RANGE (`BID_RANGE_COMMENT_REQUIRED`); reglas vía `ValidationException` (422) con códigos `BID_*`
- BidAcceptanceProcessor: acepta bid; rechaza (`REJECTED`) las pujas hermanas pendientes
- BidWithdrawProcessor: retira bid (elimina la fila)
- RequestDeleteProcessor: cancela request borrando datos dependientes y media
- ReviewProcessor: valida autor; recalcula `rating` y `reviewCount` en el perfil valorado
- RequestQuestionProcessor: notifica
- ProfessionalProfileOwnerProcessor / ClientProfileOwnerProcessor: dueño + autoverificación de teléfono / CIF
- CalendarEventOwnerProcessor: asigna dueño; POST hace upsert si ya existe evento para request+pro

## Event Listeners

- BidCreationNotifier, BidAcceptanceNotifier
- RequestCreationNotifier, RequestQuestionNotifier
- ReviewCreationNotifier
- VisitRequestNotifier (creación / aceptación / rechazo de visitas)

## Seguridad

- CurrentUserExtension: restringe Request/Bid por usuario; mercado `is_market` oculta HIGH a quien no tiene suscripción activa salvo puja PENDING/ACCEPTED, visita aceptada o asignación; ítem Request restringe acceso HIGH sin relación válida
- RequestAddressVoter: visibilidad de `preciseAddress`
- IsGranted en controladores
