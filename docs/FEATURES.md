# Funcionalidades

## Autenticación

### JWT
- Login con email y contraseña en `/api/login_check`
- Respuesta incluye `token` y `refresh_token`
- Refresh con POST `/api/token/refresh` y body `{"refresh_token": "..."}`

### Login social (Firebase)
- POST `/api/social/login` con body `{"firebaseToken": "..."}`
- Verifica token con Firebase Admin SDK
- Crea usuario si no existe (firebaseUid, email)
- Devuelve JWT y refresh_token

### Roles
- ROLE_USER - Usuario base
- ROLE_PROFESSIONAL - Tiene perfil profesional
- ROLE_FREE - Profesional gratuito (límite de bids/mes)
- ROLE_SOLVER - Suscripción SOLVER
- ROLE_PRO - Suscripción PRO
- ROLE_ADMIN

## Stripe (suscripciones)

### Tiers
- SOLVER - Plan básico (4,99 €/mes)
- PRO - Plan premium (12,99 €/mes)

### Flujo
1. Cliente llama POST `/api/stripe/checkout-session` con tier y professionalProfileId
2. Backend crea/recupera Stripe Customer y sesión Checkout
3. Usuario paga en Stripe
4. Webhook `checkout.session.completed` actualiza paidThroughAt y roles

### Webhook
- Ruta pública `/api/stripe/webhook`
- Verificación de firma con STRIPE_WEBHOOK_SECRET
- StripeCheckoutSessionHandler procesa el evento

## IA (Gemini)

### Endpoint
- POST `/api/predict` con description (texto) y opcionalmente image, audio, video

### Funcionalidad
- Diagnóstico preliminar del problema descrito
- Comprobación de seguridad (fraude de contacto, contenido ofensivo)
- Caché de contexto (GeminiCache) para tablas de precios y reglas

### Tabla de precios (CSV) y Córdoba

- La tabla maestra de precios se define en `config/gemini_pricing.csv` con columnas:
  - `Categoria`, `Subcategoria`, `Zona`, `Precio_Min`, `Precio_Max`, `Unidad`, `Complejidad`.
- `GeminiService::createCache()` lee este CSV y lo inyecta como contexto persistente en Gemini.
- Por defecto, si no se indica ubicación, el diagnóstico asume **Córdoba, Andalucía, España** y:
  - Prioriza filas de zona `Andalucía` si existen.
  - Usa filas `Nacional` como fallback sin sobrecostes de gran ciudad.

### Calibración automática de precios

- Cada `Request` guarda en `aiDiagnosis`:
  - `estimated_price_min`, `estimated_price_max`, `category`, `sub_category`, `risk_level`, etc.
- Cuando se **acepta** una `Bid`, se dispone del precio real (`priceQuote`) para ese trabajo concreto.
- El comando `app:calibrate-pricing`:
  - Agrupa por `sub_category` (no solo por categoría) y acumula también el `risk_level` dominante de esa subcategoría.
  - Calcula la desviación entre el rango medio de IA y el precio medio aceptado.
  - Propone y aplica un factor de ajuste por subcategoría (clamp entre 0.7 y 1.3) directamente sobre `config/gemini_pricing.csv` para las filas existentes.
  - Si Gemini ha devuelto una `sub_category` que aún no existe en el CSV:
    - El comando añade una nueva línea con:
      - `Zona = "Córdoba"` (enfoque actual de lanzamiento).
      - `Precio_Min` / `Precio_Max` generados alrededor del precio medio aceptado (±20 %).
      - `Complejidad` derivada del riesgo predominante (`LOW` → Baja, `MEDIUM` → Media, `HIGH` → Alta).
- De esta forma, la tabla se va afinando y ampliando automáticamente con nuevas subcategorías basadas en los precios reales aceptados en la plataforma, empezando por Córdoba y pudiendo extenderse al resto de España añadiendo filas/zona según se escale.

## Notificaciones

### Canales
- WhatsApp (Twilio)
- Push (Firebase Cloud Messaging)
- Email (Symfony Mailer)

### Eventos que disparan notificaciones
- Nueva bid en una request del cliente
- Bid aceptada (notifica al profesional)
- Nueva request (notifica a profesionales cercanos)
- Nueva pregunta en request (notifica cliente/profesional)
- Nueva reseña (notifica al valorado)
- Nueva solicitud de visita (pro → cliente)
- Visita aceptada/rechazada (cliente → profesional que la solicitó)

### NotificationService
- Decide canal según preferencias del usuario
- Usa FCM token para push

## Verificación

### Email
- VerificationToken con expiración
- EmailVerificationService envía enlace
- GET /api/verify/email?token=xxx

### Teléfono
- Verificación por **perfil**:
  - `ClientProfile.verifiedPhone` y `ProfessionalProfile.verifiedPhone` (independientes).
  - El `User` expone un `isVerifiedPhone()` calculado pero no se usa como fuente de verdad para permisos.
- PhoneVerificationService con SMS (Twilio) + modo sandbox:
  - OTP de 6 dígitos, TTL 5 minutos, almacenado en caché por usuario+número.
  - Si `TWILIO_SMS_FROM` está vacío en dev, no se llama a Twilio y el OTP se escribe en el log para pruebas.
- Endpoints:
  - POST `/api/verify/phone/send` con body `{"profile": "client" | "professional"}`:
    - Envía un solo SMS por combinación usuario+número.
    - Si el otro perfil ya tenía el mismo número verificado, devuelve éxito sin reenviar (y puede marcar ambos como verificados).
  - POST `/api/verify/phone/confirm` con body `{"code": "123456", "profile": "client" | "professional"}`:
    - Valida el OTP y marca `verifiedPhone` en el/los perfiles cuyo teléfono coincida tras normalización.

## Límites ROLE_FREE

- Profesionales sin suscripción tienen límite de bids por mes
- GET /api/professionals/me/can-bid devuelve si puede pujar

## Filtrado de datos

### CurrentUserExtension
- Request: solo las del cliente o donde el usuario tiene bids
- Bid: solo las del profesional o de requests del cliente

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
- my_bids - Bids del profesional
- history - Completadas/canceladas
