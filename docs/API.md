# Referencia de la API

Base URL: `/api`  
Formato: JSON, JSON-LD  
Autenticación: Bearer JWT en header Authorization

## Autenticación

- POST /api/login_check - Login email/password
- POST /api/token/refresh - Renovar JWT
- POST /api/social/login - Login social Firebase

## Recursos API Platform

### User
- GET/POST /api/users, GET/PUT/PATCH/DELETE /api/users/{id}

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
  - `photoUrl`, `audioUrl`, `videoUrl`: media principal de la solicitud.
  - `extraPhotoUrls[]`, `extraAudioUrls[]`, `extraVideoUrls[]`: arrays opcionales de URLs de media adicional (máx. 3 elementos en total, gestionados por el frontend).
  - `desiredExecutionTime`: disponibilidad preferida para realizar el trabajo (sin fecha exacta). Valores permitidos:
    - `"Lo antes posible"`
    - `"Esta semana"`
    - `"La próxima semana"`
    - `"A convenir al aceptar la oferta"`
- **Serialización (GET /api/requests/{id})**:
  - `assignedProfessional`: objeto embebido `ProfessionalProfile` del profesional que ganó la puja. Incluye `fullName`, `address`, `skills`, `avatar`, `rating`, `reviewCount` y **`phoneNumber`** (inyectado por normalizer solo aquí; no se expone en las bids).
  - `client`: objeto embebido `ClientProfile` del cliente. Incluye `fullName`, `avatar`, `rating`, `reviewCount`. **`phoneNumber`** solo se incluye si el usuario actual es el profesional asignado o tiene una visita de valoración aceptada para esa request; en caso contrario se omite.
  - `preciseAddress`: solo presente si el usuario tiene permiso (cliente dueño, profesional asignado, pro con bid aceptada o con visita aceptada). Se rellena típicamente al aceptar una bid.
  - `bids[]`: cada bid incluye `professional` (User) con `professionalProfile` embebido: `avatar`, `rating`, `reviewCount` (sin teléfono).
  - `visitRequests[]`: cada elemento expone `professionalPhone` **solo cuando** `status === ACCEPTED`; en otros estados el teléfono no se devuelve.

### Bid
- GET/POST /api/bids, GET/PATCH /api/bids/{id}
- PATCH /api/bids/{id}/accept - Aceptar oferta
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
- POST `/api/stripe/webhook` - Webhook Stripe (sin auth)
- POST `/api/stripe/cancel-subscription` - Programa la cancelación al final de periodo en Stripe (cancel_at_period_end=true) para el `professionalProfileId` indicado.  
  - No cambia roles ni `paidThroughAt` inmediatamente.
  - Actualiza el flag `subscriptionCancelAtPeriodEnd` en el perfil profesional y en la respuesta de usuario (`user:read`).

### Predict (IA)
- POST `/api/predict` - Diagnóstico con Gemini (body: description, image, audio, video, location opcional)
  - Respuesta típica:
    - `title`, `description`, `summary_text`
    - `category` (PLUMBING, ELECTRICITY, etc.)
    - `sub_category` (alineada con la columna Subcategoria del CSV `config/gemini_pricing.csv`)
    - `risk_level` (LOW, MEDIUM, HIGH)
    - `estimated_price_min`, `estimated_price_max` (en céntimos)
    - `urgency`, `schedule_intent`

### Visitas de valoración

- POST `/api/requests/{id}/visit-request`
  - Solo usuarios con `ROLE_PRO` (suscripción PRO activa) y con `ProfessionalProfile` asociado.
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
- GET /api/professionals/me/can-bid - ¿Puede pujar? (límite ROLE_FREE)

## Rutas públicas

POST /api/login_check, /api/social/login, /api/users  
POST /api/stripe/webhook  
GET /api/verify/email  
GET /api/docs, /api/contexts

## Swagger

Documentación interactiva: /api/docs
