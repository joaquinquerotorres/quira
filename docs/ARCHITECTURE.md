# Arquitectura

## Estructura del proyecto

- `config/` - Configuración Symfony
- `src/Command/` - Comandos consola
- `src/Controller/` - Controladores HTTP
- `src/DataFixtures/` - Datos de prueba
- `src/Doctrine/` - Extensiones Doctrine
- `src/Dto/` - Data Transfer Objects
- `src/Entity/` - Entidades Doctrine
- `src/Enum/` - Enums (BidStatus, RequestStatus, Category, RiskLevel)
- `src/EventListener/` - Listeners (notificaciones)
- `src/Repository/` - Repositorios
- `src/Security/` - LoginSuccessHandler, Voters
- `src/Serializer/` - PointDenormalizer, RequestAssignedProfessionalNormalizer (decora item normalizer; al serializar Request: inyecta `phoneNumber` en `assignedProfessional`, asegura `avatar`/`rating`/`reviewCount` en `client`, oculta `preciseAddress` o `client.phoneNumber` según permisos)
- `src/Service/` - Lógica de negocio
- `src/State/` - Procesadores API Platform
- `src/Validator/` - Validadores personalizados
- `tests/` - Tests PHPUnit

## Entidades y relaciones

### Entidades principales

| Entidad | Descripción |
|---------|-------------|
| User | email, roles, FCM token, Firebase UID, Stripe customer ID, `isVerifiedPhone()` calculado a partir de los perfiles, flag `subscriptionCancelAtPeriodEnd` expuesto para el frontend |
| ClientProfile | Perfil cliente: nombre, teléfono, avatar, `rating`, `reviewCount`, `verifiedPhone` (rating unificado; antes ratingAsClient) |
| ProfessionalProfile | Perfil profesional: bio, skills, paidThroughAt, ubicación (`locationPoint` + `serviceRadiusKm`), `verifiedPhone`, `subscriptionCancelAtPeriodEnd`, `rating`, `reviewCount` y array `reviews` embebido; rating/reviewCount actualizados por ReviewProcessor y fixtures |
| Request | Solicitud de trabajo: título, descripción, estado, categoría, diagnóstico IA (`aiDiagnosis` con precios sugeridos) y posibles `VisitRequest` asociadas |
| Bid | Oferta de profesional: precio, comentario, estado, origen (`origin` = APP/VISIT), relación opcional a `VisitRequest` |
| VisitRequest | Solicitud de visita de valoración: enlaza una `Request` y un `ProfessionalProfile`, con `status` (PENDING/ACCEPTED/REJECTED), `note` y timestamps |
| Review | Reseña sobre cliente o profesional |
| RequestQuestion | Pregunta/respuesta en una solicitud |
| Notification | Notificación in-app |
| GeminiCache | Caché de contexto para Gemini |
| VerificationToken | Token de verificación de email |
| RefreshToken | Token JWT refresh |

### Relaciones

- User 1:1 ClientProfile, 1:1 ProfessionalProfile
- ClientProfile 1:N Request
- Request N:1 ProfessionalProfile (asignado al aceptar bid)
- Request 1:N Bid, 1:N RequestQuestion
- Bid N:1 Request, N:1 User (profesional)
- Review N:1 Request, N:1 User (autor y target)

### Enums

- BidStatus: PENDING, ACCEPTED, REJECTED, COMPLETED
- RequestStatus: PENDING, PENDING_APPROVAL, ACCEPTED, COMPLETED, CANCELLED
- Category, RiskLevel

## Servicios

| Servicio | Responsabilidad |
|----------|-----------------|
| StripeService | Checkout, suscripciones Stripe |
| StripeCheckoutSessionHandler | Webhook checkout.session.completed |
| NotificationService | WhatsApp, push FCM, email (elige canal según roles/perfil y preferencias, con fallback escalonado) |
| GeminiService | Diagnóstico IA, validación y precios sugeridos usando CSV externo + contexto cacheado |
| GeminiCacheService | Gestión de cache persistente de contexto Gemini (tabla de precios y reglas) |
| SocialAuthService | Verificación Firebase |
| EmailVerificationService | Emails de verificación |
| PhoneVerificationService | OTP por SMS/sandbox, verificación por perfil (cliente/profesional) |
| MediaService | Guardado de media |
| SupabaseUploadTicketService | URLs firmadas Supabase |

## Comandos consola relevantes

- `app:calibrate-pricing`: analiza las `Request` con diagnosis de IA (incluyendo `sub_category` y `risk_level`) y pujas aceptadas, y ajusta el CSV `config/gemini_pricing.csv`:
  - Reescala los rangos de precio existentes por subcategoría (factor limitado a ±30 %).
  - Crea nuevas filas cuando detecta subcategorías nuevas, usando como base la categoría de la request, el precio medio aceptado, la zona `"Córdoba"` y una complejidad derivada del riesgo predominante.

## State Processors

- UserRegistrationProcessor: hash contraseña, roles
- RequestClientProcessor: asigna cliente
- BidProfessionalProcessor: valida profesional, límites
- BidAcceptanceProcessor: acepta bid
- BidWithdrawProcessor: retira bid
- ReviewProcessor: valida autor; recalcula y persiste `rating` y `reviewCount` en el ClientProfile o ProfessionalProfile del usuario valorado
- RequestQuestionProcessor: notifica
- ProfessionalProfileOwnerProcessor: verifica propietario

## Event Listeners

- BidCreationNotifier, BidAcceptanceNotifier
- RequestCreationNotifier, RequestQuestionNotifier
- ReviewCreationNotifier

## Seguridad

- CurrentUserExtension: restringe Request/Bid por usuario
- RequestAddressVoter: visibilidad de preciseAddress
- IsGranted en controladores
