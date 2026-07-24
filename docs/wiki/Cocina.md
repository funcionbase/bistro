# Cocina — Kitchen Display System (#115)

Pantalla operativa de cocina con tickets agrupados por orden, ordenados
FIFO, con semáforo SLA (verde / ámbar / rojo) y soporte para tabletas
dedicadas por estación física.

> Heredado de #191 Fase 5 (KDS consolidado por sede) y extendido en #115
> con estaciones, device-tokens, agrupación por orden, SLA visual y
> layout standalone responsive total.

---

## Modelos canónicos

| Modelo | Tabla | Notas |
|---|---|---|
| `KdsStation` | `kds_stations` | Por sede (branch_id NOT NULL). Slug único por (company, branch). is_default señala el fallback para categorías sin mapeo. SLA en minutos. Soft-archive vía archived_at. |
| `KdsDeviceToken` | `kds_device_tokens` | Token SHA-256 hasheado, label, last_seen_at, last_ip, revoked_at. El claro se devuelve UNA sola vez en `generate()`. |
| `RestaurantMenu` | `restaurant_menus` | Cada `category` del JSON v3 puede declarar `kds_station_id` opcional. Sin mapeo → cae a la estación is_default de la sede. |

**Estaciones canónicas** (`KdsStation::defaultDefinitions()`):

| Slug | Nombre | Color | SLA warn | SLA alert | Default |
|---|---|---|---|---|---|
| `caliente` | Caliente | `#EF4444` | 8 min | 15 min | ✓ |
| `fria` | Fría | `#22C55E` | 5 min | 10 min |  |
| `barra` | Barra | `#3B82F6` | 4 min | 8 min |  |
| `fritos` | Fritos | `#F59E0B` | 6 min | 12 min |  |

Cada sede recién creada (en `CompanyEnrollmentController` o
`BranchController::store`) recibe las 4 estaciones automáticamente.
Backfill para empresas existentes vía `KdsStationSeeder` en
`ProductionSeeder`. Ver `bistro/backend/constants/KDS_STATIONS.md` para la
referencia canónica completa.

---

## Permisos (#115 F7)

| Slug | owner | admin | cook | manager | supervisor | waiter | cashier |
|---|---|---|---|---|---|---|---|
| `kds.read` | RCUD | RCUD | R--- | R--- | R--- | R--- | R--- |
| `kds.update` | RCUD | RCUD | --U- | --U- | --U- | ---- | ---- |
| `kds_stations.*` | RCUD | ---- (sensible — asignable manualmente) | ---- | ---- | ---- | ---- | ---- |

> `accountant`, `marketing` y `inventory_manager` no reciben KDS por
> default; el owner puede asignarles manualmente. Ver
> `bistro/backend/constants/PERMISSIONS_CATALOG.md` y `ROLES_TEMPLATES.md`.

---

## Auth y autenticación

| Contexto | Auth | Permiso |
|---|---|---|
| Consolidado web (`/kds`) | JWT cookie `flexyflow_jwt` | `kds.read` |
| API consolidado (`/api/v1/kds/tickets`) | JWT + `EnsureCompanyAccess` + `EnsureBranchAccess` | `kds.read` (read) / `kds.update` (update) |
| Settings (`/company/kds`) | JWT | `kds_stations.*` |
| Standalone tablet (`/kds/{stationSlug}`) | Device-token (cookie HttpOnly `kds_device_token` o `Authorization: Bearer`) | — middleware `kds.device` resuelve company/branch/station — |
| API estación (`/api/v1/kds/{stationSlug}/...`) | Device-token | rate-limited 60 req/min per token |

**Onboarding de tableta**:
1. Owner/admin abre `/company/kds`, click "Tokens" en la estación
   deseada, genera token con label "Pereira Caliente Tablet 1".
2. El claro se muestra UNA vez en un dialog copy-once. También se
   genera una `launch_url` con `?device=<token>`.
3. El owner abre la URL en la tableta (kiosk mode). El controller
   `KdsStandaloneController` persiste el token como cookie HttpOnly y
   redirige a la URL limpia.
4. La tableta queda autenticada hasta que el token se revoque desde
   `/company/kds`.

---

## Layout y responsive

`pages/kds/station.tsx` monta `kds-standalone-layout` (sin sidebar, sin
header de app, `min-h-dvh w-screen overflow-x-hidden`).

Grid responsive de tickets:

| Breakpoint | Columnas | Caso típico |
|---|---|---|
| `<640px` | 1 | iPhone SE 375×667 — fallback mobile, sin overflow horizontal |
| `640–1023px` | 2 | iPad portrait 768×1024 |
| `1024–1535px` | 3 | tablet horizontal 1280×800 (target original cocina) |
| `≥1536px` | 4 | desktop 1920×1080 |

SLA visual con tokens semánticos del DS: `border-safe` / `border-warning`
/ `border-critical`. Hex hardcoded **prohibidos** en cards de SLA — el
color de identidad de la estación (`#EF4444` etc.) sí se aplica al chip
del header con `style={{backgroundColor: ...}}`.

`useAutoPolling` con `intervalMs=30_000` mantiene la pantalla viva sin
esfuerzo manual; el hook pausa el polling cuando la tab pierde foco. La
pantalla consolidada (`pages/kds/index.tsx`) usa `useLivePolling` con el
mismo intervalo de 30s y expone el toggle `LivePollingToggle` para que el
operador active/desactive la actualización en caliente.

---

## Auditoría

Eventos emitidos por `KdsTicketService`, `KdsStationController` y
`KdsDeviceTokenService`:

- `kds.item.in_kitchen` — `approved → in_kitchen`
- `kds.item.ready` — `in_kitchen → ready`
- `kds.item.served` — `ready → served`
- `kds.station_ready` — todos los items de una orden en la estación
  quedaron ready/served (emitido como efecto colateral de `markReady` en
  modo device-token)
- `kds.station.created` — nueva estación
- `kds.station.updated` — edición de estación
- `kds.station.archived` — soft-archive
- `kds.device_token.generated` — nuevo token (label incluido en data)
- `kds.device_token.revoked` — revocación

`AuditService::log` agrega automáticamente `branch_id` (de la orden /
estación / token) y `actor_active_branch_id` del request. Ver
`bistro/backend/constants/AUDIT_EVENTS.md` para la firma exacta del data.

---

## Integración con orders

`KdsTicketService::markReady()` deja el item en estado `ready` con
`ready_at`. Luego:

1. `maybeMarkStationReady` — si todos los items de la orden EN ESA
   estación quedaron ready/served, emite audit `kds.station_ready`.
2. `maybePromoteOrderStatus` — si todos los items consumibles de la
   orden globales quedaron ready/served, promueve `orders.status='ready'`.

La promoción global es la lógica heredada de #191 F5. F115 agrega la
señal por estación sin tocar la promoción global — el cajero/mesero
sigue viendo `orders.status=ready` cuando la mesa está completa.

`maybePromoteOrderStatus` es **público** y también corre cuando una
cancelación de item desbloquea la condición (`TableWaiterService::
rejectItem` / `cancelItemInKitchen` / `resolveCancellationRequest`):
cancelar el último plato pendiente promueve la orden a `ready` en vez de
dejarla clavada en "En cocina". Guard: si la orden no tiene items
consumibles (p. ej. todos cancelados) NO se promueve — un set vacío no
significa "todo listo". Se invoca siempre fuera de la txn de la mutación.

---

## Cross-references

- Constants: `bistro/backend/constants/KDS_STATIONS.md`,
  `PERMISSIONS_CATALOG.md`, `AUDIT_EVENTS.md`, `FEATURES_INDEX.md`,
  `ROLES_TEMPLATES.md`.
- Backend: `bistro/backend/app/Models/KdsStation.php`,
  `KdsDeviceToken.php`, `app/Services/KdsTicketService.php`,
  `KdsDeviceTokenService.php`,
  `app/Http/Controllers/Api/KdsController.php`,
  `KdsStationController.php`, `KdsDeviceTokenController.php`,
  `app/Http/Controllers/Web/KdsStandaloneController.php`,
  `app/Http/Middleware/EnsureKdsDeviceToken.php`.
- Frontend: `bistro/frontend/src/pages/kds/index.tsx`
  (consolidado), `pages/kds/station.tsx` (standalone),
  `pages/company/kds.tsx` (settings),
  `components/kds/kds-station-select.tsx`,
  `components/kds/kds-station-ticket-card.tsx`,
  `components/ui/kds-ticket-card.tsx`,
  `components/ui/kds-skeleton.tsx`,
  `layouts/kds-standalone-layout.tsx`, `hooks/use-auto-polling.ts`,
  `hooks/use-live-polling.ts`.
