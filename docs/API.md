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
- En **`user:read`**, el nodo **`professionalProfile`** (si existe) incluye **`paidThroughAt`** (ISO 8601 o null), **`subscriptionCancelAtPeriodEnd`** (boolean) y las preferencias **`notifyRequestActivity`**, **`notifyBidActivity`**, **`notifyReviews`**. `paidThroughAt` / `subscriptionCancelAtPeriodEnd` son la fuente de verdad para saber si la suscripción está vigente en el cliente; los roles `ROLE_PRO` / `ROLE_SOLVER` pueden persistir tras caducar el pago. El nodo **`clientProfile`** también expone las mismas tres preferencias de notificación.

### ProfessionalProfile
- GET/POST /api/professional_profiles, PATCH/PUT /api/professional_profiles/{id}
- Campos de valoración: `rating`, `reviewCount`; en lectura también `reviews[]` (array embebido con id, score, comment, authorName, createdAt).
- `createdAt` (ISO 8601): alta del perfil profesional. La app lo muestra como “En Quira desde {mes} de {año}”.
- `skills`: array de códigos `Category` (los 22 valores del enum; ver predict/`Category` más abajo). Usado para matching de mercado y directorio.
- Preferencias de notificación (escritura en `pro:write`, lectura también en `user:read` cuando el perfil va embebido en `GET /api/users/{id}`):
  - `notifyRequestActivity`, `notifyBidActivity`, `notifyReviews` (boolean, default `true`).
  - `PATCH /api/professional_profiles/{id}` con `Content-Type: application/merge-patch+json` (o `application/json`) y body parcial; solo el dueño (`object.getUser() == user`).
- `address`:
  - Se admite en escritura (`POST` y `PATCH`).
  - Es obligatorio en backend para alta/edición del perfil profesional.
  - Se persiste en `professional_profile` y se devuelve en lectura (`pro:read` / `user:read`).
- Teléfono profesional:
  - En escritura admite `phoneNumber` y `verifiedPhone`.
  - El backend decide el valor final de `verifiedPhone` (no confía en el payload del cliente).
  - En `POST` y cuando cambia `phoneNumber` en `PATCH/PUT`, autoverifica (`verifiedPhone=true`) si coincide con `ClientProfile.phoneNumber` verificado del mismo usuario tras normalizar.
  - Si no coincide (o cliente no verificado), guarda `verifiedPhone=false`.
- `taxId` (CIF) y `verifiedTaxId`:
  - Si envías `taxId` desde la UI, el backend valida el CIF matemáticamente.
  - Si el CIF es inválido responde con `400` y el mensaje `El CIF no es correcto.` y no se guarda el perfil.
  - Tras una validación correcta, `verifiedTaxId` se guarda como `true`.

### ClientProfile
- GET/PATCH/PUT /api/client_profiles/{id}
- Campos de valoración: `rating`, `reviewCount` (unificados; el API expone `rating` en cliente y profesional).
- Preferencias de notificación (escritura en `client:write`, lectura también en `user:read`):
  - `notifyRequestActivity`, `notifyBidActivity`, `notifyReviews` (boolean, default `true`).
  - `PATCH /api/client_profiles/{id}` con merge-patch; solo el dueño.
- Teléfono cliente:
  - El backend decide el valor final de `verifiedPhone` (no confía en el payload).
  - Si cambia `phoneNumber` y coincide con `ProfessionalProfile.phoneNumber` verificado del mismo usuario, autoverifica (`verifiedPhone=true`).
  - Si no coincide (o el profesional no está verificado), guarda `verifiedPhone=false`.
  - La comparación normaliza teléfonos y usa los últimos 9 dígitos (casos `+34 600 111 222` y `600111222` se consideran equivalentes).

### Request
- GET/POST /api/requests, GET/PATCH /api/requests/{id}
- DELETE /api/requests/{id}/cancel - Cancelar solicitud (borrado físico)
  - Solo el cliente dueño de la request.
  - Solo si la request está `PENDING` o `PENDING_APPROVAL`.
  - El backend elimina datos dependientes (`Bid`, `VisitRequest`, `RequestQuestion`, `Review`) y borra ficheros de Supabase asociados a la request y a las respuestas de preguntas.
- Filtros: is_market, my_requests, my_jobs, my_bids, history
- Campos relevantes:
  - `description`: texto refinado / mostrado como descripción del trabajo (puede venir del flujo con IA).
  - `clientOriginalDescription` (opcional, nullable): texto libre que el cliente escribió **antes** de refinar con `/api/predict` u otra edición; se persiste para trazabilidad. Máximo **5000** caracteres (mismo límite que `description` en POST `/api/predict`). Lectura y escritura en los mismos grupos que `description`. Si no hay `description` pero sí `clientOriginalDescription` (y/o audio/vídeo), la validación “debe explicar el problema” sigue cumpliéndose. La moderación humana (`PENDING_APPROVAL`) se determina **solo** desde `aiDiagnosis.safe` / `aiDiagnosis.safety_reason`. El campo `in_scope` / `out_of_scope_reason` indica si Quira cubre el servicio (UX app); `in_scope=false` **no** marca la solicitud.
  - `estimatedPriceMin` (int, céntimos): mínimo estimado generado por IA (rango orientativo). La UI lo convierte a euros dividiendo entre 100.
  - `estimatedPriceMax` (int, céntimos): máximo estimado generado por IA (rango orientativo). La UI lo convierte a euros dividiendo entre 100.
  - `aiDiagnosis` (opcional): JSON del diagnóstico IA. Si llega como `{ "min": ..., "max": ... }` (céntimos), el backend lo normaliza a `estimated_price_min/estimated_price_max`.
  - `pricingType` (`FIXED`, `RANGE`, `VISIT_REQUIRED`): tipología de pricing sugerida por IA, persistida en la request para guiar pujas y visitas.
  - `category`: enum `Category` (22 códigos; mismos que predict / `skills` del profesional).
  - `photoUrl`, `audioUrl`, `videoUrl`: media principal de la solicitud.
  - `extraPhotoUrls[]`, `extraAudioUrls[]`, `extraVideoUrls[]`: arrays opcionales de URLs de media adicional (**sin tope en backend**; el frontend puede limitar a 3). En preguntas, `answerMediaUrls` sí tiene `Assert\Count` máx. 3.
  - `desiredExecutionTime`: disponibilidad preferida para realizar el trabajo (sin fecha exacta). Valores permitidos:
    - `"Lo antes posible"`
    - `"Esta semana"`
    - `"La próxima semana"`
    - `"A convenir al aceptar la oferta"`
  - Ordenación en listados: `order[estimatedPriceMin]=asc|desc` (además de `createdAt` por defecto).
- **Serialización (GET /api/requests/{id})**:
  - **Visibilidad del ítem para profesionales (mercado):** una request `PENDING` se puede abrir en detalle si:
    - `riskLevel != HIGH`, o
    - `riskLevel = HIGH` y el profesional tiene suscripción activa (`paidThroughAt` vigente), o
    - existe puja propia activa (`PENDING/ACCEPTED`), o visita aceptada, o está asignado.
  - `assignedProfessional`: objeto embebido `ProfessionalProfile` del profesional que ganó la puja. Incluye `fullName`, `address`, `skills`, `avatar`, `rating`, `reviewCount` y **`phoneNumber`** (inyectado por normalizer solo aquí; no se expone en las bids).
  - `client`: objeto embebido `ClientProfile` del cliente. Incluye `fullName`, `avatar`, `rating`, `reviewCount`. **`phoneNumber`** solo se incluye si el usuario actual es el profesional asignado o tiene una visita de valoración aceptada para esa request; en caso contrario se omite.
  - `preciseAddress`: solo presente si el usuario tiene permiso (cliente dueño, profesional asignado, pro con bid aceptada o con visita aceptada). Se rellena típicamente al aceptar una bid.
  - `bids[]`: cada bid incluye `professional` (User) con `professionalProfile` embebido: `avatar`, `rating`, `reviewCount` (sin teléfono).
  - `bidCount` (int, virtual): número de propuestas (`bids.length`); útil para chips en listados del cliente.
  - `visitRequests[]`: cada elemento expone `professionalPhone` **solo cuando** `status === ACCEPTED`; en otros estados el teléfono no se devuelve.

### CalendarEvent
- GET/POST `/api/calendar_events`, GET/PATCH/DELETE `/api/calendar_events/{id}`
- Calendario de trabajos del profesional asignado (**un único evento** por `request` + `professional`).
- Campo canónico: `startsAt` (datetime ISO): fecha y hora de **comienzo** del trabajo. No hay `endsAt` / `scheduledAt` / periodo.
- POST: fuerza el `professional` del usuario autenticado; la request debe estar `ACCEPTED` o `COMPLETED` y tenerle asignado; requiere `startsAt`. Si ya existe evento para ese trabajo → **upsert** (actualiza `startsAt`/`notes` del existente; no crea duplicado).
- PATCH: `startsAt`, `notes`.
- DELETE: elimina el evento del calendario.
- Colección: `request` viene **embebido** (`readableLink`) con al menos `id`, `title`, `status` (grupo `calendar:read`), más `@id` IRI.
- Filtros: `startsAt` (DateFilter `after`/`before`, rango de mes), `request` (exact IRI `/api/requests/{id}`).
- Visibilidad: `CurrentUserExtension` limita la colección/ítem al `ProfessionalProfile` del usuario.

### Bid
- GET/POST /api/bids, GET /api/bids/{id}
- PATCH /api/bids/{id}/accept - Aceptar oferta
- DELETE /api/bids/{id}/withdraw - Retirar oferta (elimina la bid de la base de datos)
  - Solo el profesional creador de la bid.
  - Solo si la bid está `PENDING`.
  - Solo si la `Request` sigue `PENDING`.
- En `GET /api/bids?my_bids=true` se listan las bids existentes del profesional. Las retiradas ya no aparecen porque se eliminan de BD.
- **POST /api/bids** puede responder **422** con `violations[]` y **`code`** estable para el cliente:
  - `BID_HIGH_REQUIRES_PAID_SUBSCRIPTION` — solicitud HIGH sin suscripción activa (`paidThroughAt`)
  - `BID_MONTHLY_LIMIT_EXCEEDED` — límite mensual del plan efectivo FREE alcanzado
  - Otros errores de validación (teléfono no verificado, etc.) siguen el mismo formato de violaciones.
- Campos principales:
  - `pricingType` (`FIXED` o `RANGE`)
  - `priceQuote` (int, céntimos): obligatorio en `FIXED`; en `RANGE` se mantiene por compatibilidad (normalmente igual a `priceQuoteMin`).
  - `priceQuoteMin`, `priceQuoteMax` (int, céntimos): obligatorios en `RANGE`.
  - `comment` (texto opcional)
  - `estimatedExecutionTime` (opcional): uno de:
    - `"Hoy mismo"`
    - `"Mañana"`
    - `"Esta semana"`
    - `"La próxima semana"`
    - `"En dos semanas o más"`
    - `"A convenir al aceptar la oferta"`

### Review
- GET/POST `/api/reviews`, GET `/api/reviews/{id}` (JWT `ROLE_USER`)
- Filtros SearchFilter: `request`, `author`, `target` (exact; IRI `/api/users/{id}` o `/api/requests/{id}`)
- Colección e ítem restringidos por `CurrentUserExtension`: solo reseñas donde el usuario es **author** o **target** (no dump global). Admin sin restricción.
- Uso típico app (Perfil → Valoraciones):
  - Recibidas: `GET /api/reviews?target=/api/users/{me}`
  - Hechas: `GET /api/reviews?author=/api/users/{me}`
  - ¿Ya valoré este trabajo?: `GET /api/reviews?request=/api/requests/{id}&author=/api/users/{me}`
- Campos `review:read` útiles: `id`, `score`/`rating`, `comment`/`text`, `createdAt`/`date`, `author` (nombre string), `targetName`, `requestTitle`, `authorIsProfessional` (bool; faceta pro vs cliente, misma regla que el recálculo de ratings), `target` (IRI), `request` (preview).

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
- POST `/api/predict` - Diagnóstico con Gemini. Preferido: body pequeño con URLs de Supabase.
  - Campos preferidos: `description`, `location`, `imageUrl`, `audioUrl`, `videoUrl` (HTTPS del bucket de requests).
  - Flujo: el cliente sube el fichero con `POST /api/upload-ticket/request-media` + PUT; luego llama a `/predict` solo con la `publicUrl`.
  - **Tamaños máximos** (mismo tope en Wi‑Fi y datos móviles; el ticket incluye `maxBytes`):
    - imagen / `photo`: **10 MB**
    - audio: **12 MB**
    - vídeo: **40 MB**
  - Crea una `PredictTask` y despacha `AnalyzePredictMessage` al transporte Messenger **`async`** (siempre). Responde **`202`** `{ taskId, status }` mientras el worker procesa; el cliente consulta `GET /api/predict/tasks/{publicId}`.
  - GET `/api/predict/tasks/{publicId}` — estado (`pending` | `processing` | `completed` | `failed`) y `result` / `error`. Solo el dueño de la tarea.
  - El handler descarga el media (`PredictMediaFetcher`, anti-SSRF), llama a Gemini (`GEMINI_MODEL`) **una sola vez**, aplica **`PricingClampService`** (híbrido A+C; se omite si `safe=false` o `in_scope=false`) y persiste el resultado. Moderación + alcance en el mismo JSON (`safe`, `safety_reason`, `in_scope`, `out_of_scope_reason`).
  - Legacy (evitar): `image` / `audio` / `video` en base64 o Data URL → análisis **síncrono** sin tarea (mismo clamp; payload enorme; ver `docs/DEPLOY.md`).
  - Respuesta típica en `result` (o cuerpo plano legacy):
    - `title`, `description`, `summary_text`
    - `category`: uno de los 22 códigos de `Category` —
      `PLUMBING`, `ELECTRICITY`, `MASONRY`, `HVAC`, `DIY`, `PAINTING`, `GARDENING`, `CLEANING`,
      `APPLIANCES`, `MOVING`, `LOCKSMITH`, `POOL`, `SEWING`, `BLINDS`, `GLAZING`, `FURNITURE`,
      `CLEAROUT`, `PEST_CONTROL`, `SMART_HOME`, `BEAUTY`, `PETS`, `CARE`
      (etiquetas ES en `Category::label()` / `PricingCatalogService`)
    - `sub_category` (alineada con subcategoría de `pricing_rate` cuando es posible)
    - `risk_level` (LOW, MEDIUM, HIGH)
    - `pricing_type` (`FIXED` | `RANGE` | `VISIT_REQUIRED`)
    - `safe`, `safety_reason` (abuso / fraude de contacto; si `safe=false` → `PENDING_APPROVAL` al crear Request)
    - `in_scope`, `out_of_scope_reason` (¿cubre Quira este servicio?; si `in_scope=false` la app debe informar sin marcar moderación)
    - `estimated_price_min`, `estimated_price_max` (céntimos; pueden venir ya acotados al catálogo)
    - `urgency`, `schedule_intent`
    - Si hubo match de catálogo: `pricing_clamped` (bool), `catalog_price_min` / `catalog_price_max`, `catalog_zone`, `catalog_subcategory`

### Visitas de valoración

- POST `/api/requests/{id}/visit-request`
  - Requiere `ROLE_PROFESSIONAL` y `ProfessionalProfile` asociado.
  - Solo se permiten visitas para solicitudes `PENDING` con `pricingType = VISIT_REQUIRED` (o `aiDiagnosis.pricing_type` / columna `pricingType`).
  - En solicitudes **`HIGH`**, además se exige `ROLE_PRO` **y** suscripción activa (`paidThroughAt` futuro).
  - En solicitudes no HIGH, basta con ser profesional con acceso al trabajo.
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

### Recuperación de contraseña
- POST `/api/users/forgot-password` — body `{"email": "..."}` (público; respuesta genérica).
- POST `/api/users/reset-password` — body `{"token": "...", "password": "..."}` (público).

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
- POST /api/upload-ticket/request-media - URL firmada media (`type`: `photo`|`audio`|`video`). Respuesta: `signedUrl`, `publicUrl`, `expiresIn`, **`maxBytes`** (10 MB / 12 MB / 40 MB). Usar el mismo tope en Wi‑Fi y datos móviles; comprimir si hace falta, no un hard-cap artificial distinto.
- POST /api/users/avatar - Subir avatar directo

### Otros
- GET /api/professionals/me/can-bid - ¿Puede pujar? (límite mensual si plan efectivo FREE según `paidThroughAt`)
  - Respuesta: `canBidThisMonth` (bool) y `remainingBidsThisMonth`:
    - número de pujas restantes para usuarios con límite FREE.
    - `null` para usuarios con suscripción activa (sin límite mensual).

## Rutas públicas

POST `/api/login_check`, `/api/social/login`, `/api/users`  
POST `/api/users/forgot-password`, `/api/users/reset-password`  
POST `/api/stripe/webhook`  
GET `/api/verify/email`  
GET `/api/docs`, `/api/contexts`

## Swagger

Documentación interactiva: /api/docs
