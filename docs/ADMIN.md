# Admin Quira (API)

Panel interno consumido por la app móvil (`docs/ADMIN.md` en quira-mobile).  
Todas las rutas `/api/admin/*` exigen `ROLE_ADMIN` (JWT).

## Rol

- Constante: `App\Entity\User::ROLE_ADMIN` (`ROLE_ADMIN`).
- Hierarchy (`security.yaml`): `ROLE_ADMIN` → `ROLE_USER`.
- Puede coexistir con perfil cliente (`ROLE_USER` / client profile).

### Crear operador

```bash
# Crear usuario nuevo con ROLE_ADMIN
php bin/console app:create-admin admin@quira.app --password='***'

# Promocionar usuario existente
php bin/console app:create-admin existing@quira.app --promote-only
```

Login igual que el resto: `POST /api/login_check` → JWT. El payload de user debe incluir `ROLE_ADMIN` en `roles`.

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
