# Referencia de la API

Base URL: `/api`  
Formato: JSON, JSON-LD  
Autenticación: Bearer JWT en header Authorization

## Autenticación

- POST /api/login_check - Login email/password
- POST /api/token/refresh - Renovar JWT
- POST /api/social/login — body JSON `{"token":"..."}`: ID token de Firebase (SDK tras login Google/Apple). Sin `token` → 400; token inválido → 401.

## Recursos API Platform

### User
- GET/POST /api/users, GET/PUT/PATCH/DELETE /api/users/{id}
- En **`user:read`**, el nodo **`professionalProfile`** (si existe) incluye **`paidThroughAt`** (ISO 8601 o null) y **`subscriptionCancelAtPeriodEnd`** (boolean). Son la fuente de verdad para saber si la suscripción está vigente en el cliente; los roles `ROLE_PRO` / `ROLE_SOLVER` pueden persistir tras caducar el pago.

### ClientProfile
- GET/PATCH/PUT /api/client_profiles/{id}
- Campos de valoración: `rating`, `reviewCount` (unificados; el API expone `rating` en cliente y profesional).

### ProfessionalProfile
- GET/POST /api/professional_profiles, PATCH/PUT /api/professional_profiles/{id}
- Campos de valoración: `rating`, `reviewCount`; en lectura también `reviews[]` (array embebido con id, score, comment, authorName, createdAt).

### Request
- GET/POST /api/requests, GET/PATCH /api/requests/{id}
- Filtros: is_market, my_requests, my_jobs, my_bids, history
- Campos relevantes:
  - `description`: texto refinado / mostrado como descripción del trabajo (puede venir del flujo con IA).
  - `clientOriginalDescription` (opcional, nullable): texto libre que el cliente escribió **antes** de refinar con `/api/predict` u otra edición; se persiste para trazabilidad. Máximo **5000** caracteres (mismo límite que `description` en POST `/api/predict`). Lectura y escritura en los mismos grupos que `description`. Si no hay `description` pero sí `clientOriginalDescription` (y/o audio/vídeo), la validación “debe explicar el problema” sigue cumpliéndose. En la creación de solicitud, la comprobación de seguridad (IA) usa `description` si viene informada; si está vacía, usa `clientOriginalDescription`.
  - `estimatedPriceMin` (int, céntimos): mínimo estimado generado por IA (rango orientativo). La UI lo convierte a euros dividiendo entre 100.
  - `estimatedPriceMax` (int, céntimos): máximo estimado generado por IA (rango orientativo). La UI lo convierte a euros dividiendo entre 100.
  - `aiDiagnosis` (opcional): JSON del diagnóstico IA. Si llega como `{ "min": ..., "max": ... }` (céntimos), el backend lo normaliza a `estimated_price_min/estimated_price_max`. No afecta a la validación del rango.
  - `photoUrl`, `audioUrl`, `videoUrl`: media principal de la solicitud.
  - `extraPhotoUrls[]`, `extraAudioUrls[]`, `extraVideoUrls[]`: arrays opcionales de URLs de media adicional (máx. 3 elementos en total, gestionados por el frontend).
  - `desiredExecutionTime`: disponibilidad preferida para realizar el trabajo (sin fecha exacta). Valores permitidos:
    - `"Lo antes posible"`
    - `"Esta semana"`
    - `"La próxima semana"`
    - `"A convenir al aceptar la oferta"`
  - Ordenación en listados: `order[estimatedPriceMin]=asc|desc` (además de `createdAt` por defecto).
- **Serialización (GET /api/requests/{id})**:
  - `assignedProfessional`: objeto embebido `ProfessionalProfile` del profesional que ganó la puja. Incluye `fullName`, `address`, `skills`, `avatar`, `rating`, `reviewCount` y **`phoneNumber`** (inyectado por normalizer solo aquí; no se expone en las bids).
  - `client`: objeto embebido `ClientProfile` del cliente. Incluye `fullName`, `avatar`, `rating`, `reviewCount`. **`phoneNumber`** solo se incluye si el usuario actual es el profesional asignado o tiene una visita de valoración aceptada para esa request; en caso contrario se omite.
  - `preciseAddress`: solo presente si el usuario tiene permiso (cliente dueño, profesional asignado, pro con bid aceptada o con visita aceptada). Se rellena típicamente al aceptar una bid.
  - `bids[]`: cada bid incluye `professional` (User) con `professionalProfile` embebido: `avatar`, `rating`, `reviewCount` (sin teléfono).
  - `visitRequests[]`: cada elemento expone `professionalPhone` **solo cuando** `status === ACCEPTED`; en otros estados el teléfono no se devuelve.

### Bid
- GET/POST /api/bids, GET/PATCH /api/bids/{id}
- PATCH /api/bids/{id}/accept - Aceptar oferta
- **POST /api/bids** puede responder **422** con `violations[]` y **`code`** estable para el cliente:
  - `BID_HIGH_REQUIRES_PAID_SUBSCRIPTION` — solicitud HIGH sin suscripción activa (`paidThroughAt`)
  - `BID_MONTHLY_LIMIT_EXCEEDED` — límite mensual del plan efectivo FREE alcanzado
  - Otros errores de validación (teléfono no verificado, etc.) siguen el mismo formato de violaciones.
- Campos principales:
  - `priceQuote` (int, céntimos)
  - `comment` (texto opcional)
  - `estimatedExecutionTime` (opcional): uno de:
    - `"Hoy mismo"`
    - `"Mañana"`
    - `"Esta semana"`
    - `"La próxima semana"`
    - `"En dos semanas o más"`
    - `"A convenir al aceptar la oferta"`

### Review
- GET/POST /api/reviews, GET /api/reviews/{id}

### Notification
- GET/PATCH /api/notifications, GET /api/notifications/{id}

### RequestQuestion
- GET/POST /api/request_questions
- GET /api/requests/{id}/questions
  - Las respuestas (`answerText`) pueden incluir hasta **3** elementos de media (imagen o vídeo) en el campo `answerMediaUrls[]` (array de URLs). El backend valida que no se envíen más de 3 elementos.

## Controladores custom

### Stripe
- POST `/api/stripe/checkout-session` - Crea sesión Checkout (body: tier, professionalProfileId, successUrl, cancelUrl)
- POST `/api/stripe/webhook` - Webhook Stripe (sin auth). Idempotente por `evt_*`; eventos que sincronizan periodo: `checkout.session.completed`, `customer.subscription.*` (created/updated/deleted/paused/resumed), `invoice.paid`, `invoice.payment_failed`, `invoice.payment_succeeded`, `invoice.updated`. En el Dashboard deben estar suscritos (ver `docs/SETUP.md`).
- POST `/api/stripe/sync-subscription` - **JWT**. Tras el redirect de Checkout, si el webhook aún no actualizó la BD, fuerza lectura desde Stripe y devuelve `paidThroughAt` + `subscriptionCancelAtPeriodEnd` actualizados (el cliente puede llamar antes de `GET /users/{id}`).
- POST `/api/stripe/cancel-subscription` - Programa la cancelación al final de periodo en Stripe (cancel_at_period_end=true) para el `professionalProfileId` indicado.  
  - No cambia roles ni `paidThroughAt` inmediatamente.
  - Actualiza el flag `subscriptionCancelAtPeriodEnd` en el perfil profesional y en la respuesta de usuario (`user:read`).

**Reconciliación (operaciones / cron):** `php bin/console stripe:reconcile-subscriptions` (opción `--user-id=` para uno solo). Compara suscripciones en Stripe con la BD por si faltó un webhook.

### Predict (IA)
- POST `/api/predict` - Diagnóstico con Gemini (body: description, image, audio, video, location opcional)
  - El body puede ser **muy grande** (vídeo en base64). En **móvil / 4G** la subida tarda; el cliente debe usar **timeout HTTP generoso** (p. ej. ≥ 120 s). En producción Docker, los límites PHP ampliados están en `docker/php/zz-quira.ini` (ver `docs/DEPLOY.md`).
  - `image`, `audio`, `video` pueden enviarse como **Data URL** (`data:<mime>;base64,<data>`) o base64 “crudo”.
  - Mimes recomendados:
    - `image/jpeg`
    - `audio/mpeg` (si envías `audio/mp3` se normaliza)
    - `video/mp4`
  - Respuesta típica:
    - `title`, `description`, `summary_text`
    - `category` (PLUMBING, ELECTRICITY, etc.)
    - `sub_category` (alineada con la columna Subcategoria del CSV `config/gemini_pricing.csv`)
    - `risk_level` (LOW, MEDIUM, HIGH)
    - `estimated_price_min`, `estimated_price_max` (en céntimos)
    - `urgency`, `schedule_intent`

### Visitas de valoración

- POST `/api/requests/{id}/visit-request`
  - Solo usuarios con `ROLE_PRO`, suscripción **activa** (`paidThroughAt` futuro) y `ProfessionalProfile` asociado.
  - Solo se permiten visitas para solicitudes con `riskLevel = HIGH` y estado `PENDING`.
  - Crea (o reutiliza si ya existe) una `VisitRequest` con `status = PENDING` para la `Request` indicada y el profesional autenticado.
  - Body opcional: `{"note": "Texto opcional del profesional sobre la visita"}`.

- POST `/api/visit-requests/{id}/accept`
  - Solo el cliente dueño de la `Request` asociada (mismo `ClientProfile.user`).
  - Cambia `status` de la `VisitRequest` a `ACCEPTED`.

- POST `/api/visit-requests/{id}/reject`
  - Solo el cliente dueño de la `Request`.
  - Cambia `status` a `REJECTED`.

- POST `/api/requests/{id}/visit-quote`
  - Solo usuarios con `ROLE_PROFESSIONAL` y `ProfessionalProfile`.
  - Requisitos:
    - La `Request` debe estar en estado `PENDING`.
    - Debe existir una `VisitRequest` con `status = ACCEPTED` para esa Request y ese profesional.
  - Body:
    - `{"amount": 12000, "comment": "Presupuesto tras visita"}` (amount en céntimos).
  - Crea un `Bid` estándar (sin campo `origin` ni vínculo directo con la visita); la visita queda solo asociada a la Request.

### Verificación
#### Email
- GET `/api/verify/email` - Verificar email
- POST `/api/verify/email/resend` - Reenviar verificación

#### Teléfono
- POST `/api/verify/phone/send`
  - Body: `{"profile": "client" | "professional"}`
  - Usa el teléfono del perfil correspondiente:
    - `client` → `ClientProfile.phoneNumber`
    - `professional` → `ProfessionalProfile.phoneNumber`
  - Si el teléfono está vacío, devuelve 400.
  - En dev, si `TWILIO_SMS_FROM` está vacío, entra en modo sandbox: no envía SMS real y registra el OTP en el log (y en consola).
  - Si el otro perfil ya tiene el mismo número verificado, devuelve éxito con `skipped: true` y no reenvía SMS.
- POST `/api/verify/phone/confirm`
  - Body: `{"code": "123456", "profile": "client" | "professional"}`
  - Valida el OTP y marca como verificados los perfiles cuyo teléfono coincida tras normalización.

### Upload
- POST /api/upload-ticket/avatar - URL firmada avatar
- POST /api/upload-ticket/request-media - URL firmada media
- POST /api/users/avatar - Subir avatar directo

### Otros
- GET /api/professionals/me/can-bid - ¿Puede pujar? (límite mensual si plan efectivo FREE según `paidThroughAt`)

## Rutas públicas

POST /api/login_check, /api/social/login, /api/users  
POST /api/stripe/webhook  
GET /api/verify/email  
GET /api/docs, /api/contexts

## Swagger

Documentación interactiva: /api/docs
