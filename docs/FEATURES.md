# Funcionalidades

## Autenticación

### JWT
- Login con email y contraseña en `/api/login_check`
- Respuesta incluye `token` y `refresh_token`
- Refresh con POST `/api/token/refresh` y body `{"refresh_token": "..."}`

### Login social (Firebase)
- POST `/api/social/login` con body `{"token": "<Firebase ID token>"}` (campo **`token`**, ID token devuelto por el SDK de Firebase tras Google/Apple)
- Verifica token con Firebase Admin SDK
- Crea usuario si no existe (firebaseUid, email)
- Devuelve JWT y refresh_token

### Roles
- ROLE_USER - Usuario base
- ROLE_PROFESSIONAL - Tiene perfil profesional
- ROLE_FREE - Marcador de faceta profesional gratuita (onboarding / marketing)
- ROLE_SOLVER / ROLE_PRO - Marcadores de tier contratado (pueden persistir aunque el pago haya caducado)
- ROLE_ADMIN

**Permisos operativos (pujas, HIGH, visitas por pricing_type, límite mensual):** el servidor usa **`professionalProfile.paidThroughAt`** (y la lógica en `ProfessionalSubscriptionService`), no solo el rol. Suscripción de pago **vigente** = `paidThroughAt` no nulo y **posterior a ahora**. `paidThroughAt === null` se trata como sin periodo de pago conocido (mismas restricciones que caducado para esas reglas).

### Recuperación de contraseña
- `POST /api/users/forgot-password` con `{"email": "..."}` — siempre responde éxito genérico (no filtra si el email existe); si hay usuario, envía enlace con token (`FRONTEND_URL`).
- `POST /api/users/reset-password` con `{"token": "...", "password": "..."}` — valida token y actualiza hash.
- Rutas **públicas** (sin JWT). Implementación: `PasswordResetService` + `VerificationToken`.

## Stripe (suscripciones)

### Tiers
- SOLVER - Plan básico (4,99 €/mes)
- PRO - Plan premium (12,99 €/mes)

### Flujo
1. Cliente llama POST `/api/stripe/checkout-session` con tier y professionalProfileId
2. Backend crea/recupera Stripe Customer (persiste `stripe_customer_id` en `User`) y sesión Checkout (`subscription_data.metadata` con perfil y tier)
3. Usuario paga en Stripe
4. Webhooks actualizan **`paidThroughAt`** (fin de periodo / trial según Stripe) y **`subscriptionCancelAtPeriodEnd`**; `checkout.session.completed` también asigna roles (PRO/SOLVER) vía `StripeCheckoutSessionHandler`

Periodo de prueba u ofertas: se configuran en el **dashboard de Stripe** (Productos / Precios), no en este backend.

### Webhooks
- Ruta pública `/api/stripe/webhook`; verificación de firma con `STRIPE_WEBHOOK_SECRET`
- **`StripeWebhookProcessor`**: tipos suscritos incluyen `checkout.session.completed`, `customer.subscription.*` (created/updated/deleted/paused/resumed), `invoice.paid`, `invoice.payment_failed`, `invoice.payment_succeeded`, `invoice.updated`
- **Idempotencia:** tabla `stripe_webhook_event` (un `evt_*` solo cuenta como procesado con éxito una vez); logs estructurados (`stripe.webhook.*`)

### Cliente tras Checkout
- POST `/api/stripe/sync-subscription` (JWT): fuerza lectura desde Stripe si el redirect llega antes que el webhook
- Operaciones: `php bin/console stripe:reconcile-subscriptions` (`--user-id=` opcional) para recuperar desajustes por webhooks perdidos

## IA (Gemini)

### Endpoint
- Flujo preferido: cliente sube media a Supabase (`upload-ticket`) → `POST /api/predict` con `imageUrl` / `audioUrl` / `videoUrl` (+ `description` / `location`). Crea `PredictTask` + `AnalyzePredictMessage` (Messenger **async**; worker Railway con `CONTAINER_ROLE=worker`). Polling: `GET /api/predict/tasks/{publicId}`.
- Límites de archivo (uniformes Wi‑Fi / datos; expuestos como `maxBytes` en el ticket): imagen **10 MB**, audio **12 MB**, vídeo **40 MB** (`PredictMediaLimits` / `PredictMediaFetcher`).
- Legacy: `image` / `audio` / `video` en base64 (payload grande; `docker/php/zz-quira.ini`). No usar en clientes nuevos.
- El texto de `description` en predict está limitado a **5000** caracteres; el mismo límite aplica a **`clientOriginalDescription`** en la entidad `Request` (texto original del cliente guardado al crear/actualizar la solicitud).

### Funcionalidad
- Diagnóstico preliminar del problema descrito
- Comprobación de seguridad (fraude de contacto, contenido ofensivo) integrada en `GeminiService::diagnose()` (Paso 1 de moderación). La respuesta incluye `safe` y `safety_reason`; si `safe=false`, el backend marca la request para revisión (`PENDING_APPROVAL`).
- Caché de contexto (GeminiCache) para tablas de precios y reglas (si falla la creación, la predicción sigue funcionando)

### Tabla de precios (BD) y Córdoba

- Fuente de verdad: tabla **`pricing_rate`** (céntimos). Los datos iniciales (tarifas históricas) se cargan en la migración Doctrine; **no hay CSV ni comando seed en runtime**.
- `PricingCatalogService` resuelve zonas según ubicación y genera el slice CSV **en memoria** inyectado en la caché Gemini:
  - Córdoba → `Córdoba`, `Andalucía`, `España`
  - Otras ciudades andaluzas → `Andalucía`, `España`
  - Madrid / Barcelona / Bilbao → `España`
  - Resto / desconocido → `España`, `Andalucía`, `Córdoba`
  - Sin ubicación (null/vacío) → mismo slice que Córdoba
- `GeminiCacheService` guarda en BD solo el **ID** remoto de `cachedContents` + `content_hash` + `model` + `zone_key`. Si Google falla, `diagnose` continúa **sin** `cachedContent`.
- **Híbrido A+C** (tras `diagnose`, en predict async y legacy):
  - `PricingClampService` busca fila por `sub_category` + zona (prioridad de zonas; fallback case-insensitive / sin exigir categoría exacta).
  - Si hay match: acota `estimated_price_min/max` a `[price_min, price_max]`; con `urgency = IMMEDIATE` el techo es `1.3 × price_max`.
  - `pricing_type = VISIT_REQUIRED` → fuerza `estimated_price_min/max = 0`.
  - Estimación ya en `0/0` (sin visita) o **sin match** de catálogo → no modifica los precios de Gemini.
  - Metadatos opcionales en el JSON: `pricing_clamped`, `catalog_price_min/max`, `catalog_zone`, `catalog_subcategory`.
- Tras `app:calibrate-pricing`, se actualizan filas en BD y se invalidan cachés locales.

### Calibración automática de precios

- Cada `Request` guarda en `aiDiagnosis`:
  - `estimated_price_min`, `estimated_price_max`, `category`, `sub_category`, `risk_level`, etc.
- Además, el rango estimado se persiste también a nivel de entidad en columnas `estimated_price_min/estimated_price_max` (céntimos), para que el API pueda devolverlo siempre y permitir ordenación por `estimatedPriceMin`.
- Cuando se **acepta** una `Bid`, se dispone del precio real (`priceQuote`) para ese trabajo concreto.
- El comando `app:calibrate-pricing`:
  - Agrupa por `sub_category` (no solo por categoría) y acumula también el `risk_level` dominante de esa subcategoría.
  - Calcula la desviación entre el rango medio de IA y el precio medio aceptado.
  - Propone y aplica un factor de ajuste por subcategoría (clamp entre 0.7 y 1.3) sobre filas de **`pricing_rate`**.
  - Si Gemini ha devuelto una `sub_category` que aún no existe:
    - Añade fila con `Zona = Córdoba`, rango ±20 % del aceptado, `Complejidad` según riesgo.
  - Invalida cachés Gemini locales para forzar recreación con el catálogo nuevo.
## Notificaciones

### Canales (variables de entorno)
- Valores **`PUSH`**, **`EMAIL`** o **`WHATSAPP`** (mayúsculas o minúsculas):
  - `NOTIFICATIONS_PRO` — envíos en **faceta profesional** cuando el usuario tiene `ROLE_PRO`.
  - `NOTIFICATIONS_SOLVER` — faceta profesional con `ROLE_SOLVER`.
  - `NOTIFICATIONS_FREE` — faceta profesional con `ROLE_FREE` (o `ProfessionalProfile` sin esos tres roles, caso raro).
  - `NOTIFICATIONS_CLIENT` — envíos en **faceta cliente** (ofertas recibidas en *sus* solicitudes, visitas a *su* pedido, dudas de pros sobre *su* request, reseña recibida como cliente, etc.).
- Un mismo `User` puede ser cliente y profesional: el **evento** pasa `NotificationAudience::Client` o `::Professional` a `NotificationService::send()`, así un Free recibe **email** por `NOTIFICATIONS_FREE` cuando actúa como pro y **push** por `NOTIFICATIONS_CLIENT` cuando la notificación es sobre su actividad como cliente.

### Preferencias por perfil (base de datos)
- `ClientProfile` / `ProfessionalProfile`: `notifyRequestActivity`, `notifyBidActivity`, `notifyReviews` — los listeners comprueban estos flags **antes** de llamar al servicio.
- Expuestos en `GET /api/users/{id}` (`user:read`) y editables con `PATCH` del perfil correspondiente (`client:write` / `pro:write`).
- `ProfessionalProfile`: usados según el tipo de aviso (nuevas solicitudes, aceptación de oferta, visitas, respuestas en Q&A, reseñas como pro).
- `ClientProfile`: dudas sobre solicitudes, nuevas ofertas y valoraciones recibidas como cliente.

### Eventos que disparan notificaciones
- Nueva bid en una request del cliente
- Bid aceptada (notifica al profesional)
- Nueva request (notifica a profesionales cercanos)
- Nueva pregunta en request (notifica cliente/profesional)
- Nueva reseña (notifica al valorado)
- Nueva solicitud de visita (pro → cliente)
- Visita aceptada/rechazada (cliente → profesional que la solicitó)

### NotificationService
- Elige canal según `NotificationAudience` + roles (faceta pro) o `NOTIFICATIONS_CLIENT` (faceta cliente).
- FCM para push; Twilio solo si el canal configurado es WhatsApp (nunca como fallback).
- Emails:
  - `BID_RECEIVED` y `NEW_REQUEST` usan plantillas HTML enriquecidas con bloques de contexto (solicitud, importe/rango).
  - El resto de tipos usan una plantilla HTML genérica con el mismo look & feel (header/footer de marca y tarjeta de contenido).

## Verificación

### Email
- VerificationToken con expiración
- EmailVerificationService envía enlace
- GET /api/verify/email?token=xxx

### Teléfono
- Verificación por **perfil**:
  - `ClientProfile.verifiedPhone` y `ProfessionalProfile.verifiedPhone` (independientes).
  - El `User` expone un `isVerifiedPhone()` calculado pero no se usa como fuente de verdad para permisos.
- Auto-verificación en `ProfessionalProfile` (POST/PATCH):
  - El backend no confía en `verifiedPhone` enviado por frontend: calcula y persiste el estado real.
  - En alta de profesional y cuando cambia el teléfono, autoverifica si coincide con el teléfono cliente verificado del mismo usuario (comparación normalizada).
  - Si no coincide (o el otro perfil no está verificado), guarda `verifiedPhone=false`.
  - Se aplica también en el flujo inverso al editar `ClientProfile`.
  - Regla de comparación: normaliza a dígitos y compara los últimos 9 (ES).
- PhoneVerificationService con SMS (Twilio) + modo sandbox:
  - OTP de 6 dígitos, TTL 5 minutos, almacenado en caché por usuario+número.
  - Si `TWILIO_SMS_FROM` está vacío en dev, no se llama a Twilio y el OTP se escribe en el log para pruebas.
- Endpoints:
  - POST `/api/verify/phone/send` con body `{"profile": "client" | "professional"}`:
    - Envía un solo SMS por combinación usuario+número.
    - Si el otro perfil ya tenía el mismo número verificado, devuelve éxito sin reenviar (y puede marcar ambos como verificados).
  - POST `/api/verify/phone/confirm` con body `{"code": "123456", "profile": "client" | "professional"}`:
    - Valida el OTP y marca `verifiedPhone` en el/los perfiles cuyo teléfono coincida tras normalización.

### CIF (solo profesionales PRO)
- `ProfessionalProfile.taxId` representa el CIF.
- Cuando el usuario guarda/actualiza su `ProfessionalProfile` desde la UI y envía `taxId`, el backend valida el CIF matemáticamente.
- Si el CIF es inválido: responde `400` y el perfil no se guarda (no se cambia `taxId` ni `verifiedTaxId`).
- Si el CIF es válido: se guarda `verifiedTaxId=true`.
- El flag `ProfessionalProfile.isVerified` se recalcula al guardar y también cuando se confirma email/teléfono:
  - Free/Solver: requiere email verificado + teléfono PRO verificado
  - Pro (`ROLE_PRO`): además requiere `verifiedTaxId=true`

## Límites y reglas de pujas (plan efectivo FREE)

- Aplica a quien **no** tiene suscripción activa según `paidThroughAt` (incluye `ROLE_FREE`, `ROLE_PRO`/`ROLE_SOLVER` con pago caducado o `paidThroughAt` null)
- Límite de **3 pujas por mes calendario** (conteo en `BidRepository`; las pujas retiradas no cuentan porque se eliminan de BD)
- **HIGH:** no se puede crear nueva puja sin suscripción activa; quien ya tiene puja `PENDING`/`ACCEPTED` o es el asignado sigue pudiendo ver el hilo según filtros de API
- POST `/api/bids` devuelve **422** con `violations[]` y códigos estables (`BID_HIGH_REQUIRES_PAID_SUBSCRIPTION`, `BID_MONTHLY_LIMIT_EXCEEDED`) para el cliente
- GET `/api/professionals/me/can-bid` → `canBidThisMonth` + `remainingBidsThisMonth` coherente con el plan efectivo (no solo `ROLE_FREE`)

## Filtrado de datos

### CurrentUserExtension
- **Request (colección):** según query (`is_market`, `my_jobs`, cliente, etc.); en mercado, profesionales sin suscripción activa no ven solicitudes **HIGH** salvo excepción (puja propia PENDING/ACCEPTED o asignado)
- **Request (ítem):** cliente, asignado, visita aceptada, puja propia activa (PENDING/ACCEPTED), o request `PENDING` en mercado:
  - si es **no-HIGH**, visible para profesionales;
  - si es **HIGH**, visible solo con suscripción activa (`paidThroughAt` vigente).
- **Bid:** del profesional o de requests del cliente según contexto (`my_bids`, etc.)
- **CalendarEvent:** solo eventos del `ProfessionalProfile` del usuario autenticado

### RequestAddressVoter
- Campo `preciseAddress` solo visible para:
  - El cliente dueño de la request.
  - El profesional asignado.
  - Un profesional con una `Bid` ACCEPTED.
  - Un profesional que tenga una `VisitRequest` ACCEPTED para esa request.

### Serialización y privacidad

- **RequestAssignedProfessionalNormalizer**: normalizer que decora el item normalizer de API Platform (JSON-LD y JSON). Al serializar una `Request`:
  - Inyecta `phoneNumber` **solo** en `assignedProfessional` (el profesional ganador ve su teléfono; las bids no exponen teléfono de pujantes).
  - Asegura en el nodo `client` los campos `avatar`, `rating` y `reviewCount`; **`phoneNumber`** del cliente solo se incluye si el usuario es el profesional asignado o tiene una visita de valoración aceptada para esa request, en caso contrario se elimina del payload.
  - Oculta `preciseAddress` si el usuario no tiene permiso (según RequestAddressVoter). La dirección exacta se conoce típicamente cuando se acepta una bid y solo es visible para quien corresponda (cliente, pro asignado, pro con bid aceptada o con visita aceptada).
- **Rating unificado**: tanto `ClientProfile` como `ProfessionalProfile` exponen el campo `rating` (y `reviewCount`) en la API; en cliente se eliminó el nombre legacy `ratingAsClient` en favor de `rating`.

### Filtros de colección
- is_market - Requests públicas para mercado
- my_requests - Requests del cliente
- my_jobs - Requests asignadas al profesional
- my_bids - Bids del profesional existentes
- history - Completadas (las canceladas se eliminan físicamente)

## Calendario profesional
- El profesional asignado puede crear un `CalendarEvent` por trabajo ganado (`ACCEPTED`/`COMPLETED`).
- Un único evento por par request + professional; se guarda la fecha y hora de **comienzo** (`startsAt`), sin hora de fin (varios trabajos el mismo día).
- Se puede editar el comienzo o eliminar el evento.
- La app móvil muestra vista mensual, FAB de alta y acceso desde perfil / detalle del trabajo.