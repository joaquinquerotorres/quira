# Admin Quira (API)

Panel interno consumido por la app móvil (`docs/ADMIN.md` en quira-mobile).  
Todas las rutas `/api/admin/*` exigen `ROLE_ADMIN` (JWT).

## Rol

- Constantes: `User::ROLE_ADMIN`, `User::ROLE_CLIENT` (faceta cliente del operador).
- Hierarchy (`security.yaml`): `ROLE_ADMIN` → `ROLE_USER`.
- El operador lleva `ClientProfile` + `ROLE_CLIENT` (y `ROLE_USER` vía `getRoles()`).

### Provisionar operador (one-off)

**No** uses fixtures ni migraciones con passwords. Secrets solo en variables de entorno:

```bash
railway variables set ADMIN_EMAIL=admin@quira.app ADMIN_PASSWORD='***'
railway run php bin/console app:admin:ensure

# Si ya existe y quieres rotar la password desde ADMIN_PASSWORD:
railway run php bin/console app:admin:ensure --reset-password
```

Local:

```bash
# en .env.local (gitignored), nunca en el repo:
# ADMIN_EMAIL=admin@example.com
# ADMIN_PASSWORD=...
php bin/console app:admin:ensure
```

Comportamiento de `app:admin:ensure` (idempotente):

1. Exige `ADMIN_EMAIL` y `ADMIN_PASSWORD` (si faltan → error, exit ≠ 0).
2. Si no existe el user → lo crea con el mismo `UserPasswordHasher` que `/api/login_check`, roles `ROLE_ADMIN` + `ROLE_CLIENT` (+ `ROLE_USER`), `ClientProfile`, `verifiedEmail=true`. **No** setea teléfono ni `verifiedPhone` (el login email/password no lo exige; crear solicitudes sí).
3. Si ya existe → asegura `ROLE_ADMIN` + `ROLE_CLIENT` (+ ClientProfile si faltaba). La password **solo** se actualiza con `--reset-password`.

No ejecutar este comando en el boot HTTP ni en cada request.

Login: `POST /api/login_check` → JWT. El user debe incluir `ROLE_ADMIN` en `roles`.

## Fase 1 — Dashboard

### `GET /api/admin/stats/overview?from=YYYY-MM-DD&to=YYYY-MM-DD`

- Auth: `ROLE_ADMIN` → 200; otros usuarios → 403; anon → 401.
- JSON plano (no Hydra).
- Zona civil: **Europe/Madrid**.
- `to >= from`; máximo **366** días.
- `previousFrom` / `previousTo`: intervalo inmediatamente anterior con la **misma duración en días**.

Contrato de respuesta: ver quira-mobile `docs/ADMIN.md` (`period`, `kpis`, `funnel`, `queues`, `timeseries`).

### Definiciones implementadas

| Campo | Definición |
|-------|------------|
| `newUsers` | `user.created_at` en periodo |
| `newPros` | `professional_profile.created_at` en periodo |
| `newRequests` / `newBids` | `created_at` en periodo |
| `acceptedBids` | bids `status=ACCEPTED` con `updated_at` en periodo |
| `completedRequests` | requests `status=COMPLETED` con `updated_at` en periodo |
| `activePaidSubscriptions` | `paid_through_at > now()`; `previous` ≈ `paid_through_at > fin previousTo` |
| `cancelAtPeriodEnd` | snapshot `subscription_cancel_at_period_end = true` (solo `value`) |
| Funnel | ver comentarios en `AdminStatsService` (preferibles del doc móvil) |
| Colas | snapshots `PENDING_APPROVAL` / visit `PENDING` |
| Timeseries | un punto por día civil Madrid; ceros si no hay datos |

Migración `Version20260731140000` añade `user.created_at`, `bid.updated_at`, `request.updated_at` e índices.

## Fuera de alcance (fases 2–9)

Listados, moderación, exports, etc.
