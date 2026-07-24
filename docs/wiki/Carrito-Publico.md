# Carrito público (QR de mesa)

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

---

## Resumen

Cubre el flujo que ejecuta el **comensal final** desde su celular cuando
escanea el QR pegado en la mesa: ver el menú, armar su selección, enviarla
para aprobación del mesero y seguir el estado en vivo. **No hay JWT de
usuario ni autenticación de empresa**: la única credencial es el
`qr_token` de la URL del QR más una cookie de dispositivo firmada
(`tdt_*`) que identifica al comensal dentro de la sesión grupal de mesa
(ver `Mesas.md`).

Existen dos canales públicos complementarios:

1. **QR de mesa (#191)** — el principal. Prefijo
   `/api/v1/public/table/{qr_token}`. El frontend es la SPA
   `pages/table/join.tsx` (alta) y `pages/table/menu.tsx` (carrito).
2. **Carrito de WhatsApp Bot (`/api/v1/cart/{jwt}`)** — legacy, mirror
   del carrito que arma el bot externo. CartJWT corto, sin auth de
   usuario. Sección final.

> **Nota (unificación desde /chats)**: el panel de chats YA NO envía links
> de carrito con CartJWT a `pedidos.flexyflow.co`. La opción unificada
> "Enviar la carta" genera un link corto `/menus?cart={uuid}` respaldado por
> una `CartSession` ligada al chat (`chat_id`); al confirmar el pedido desde
> la carta, la sesión se convierte (`order_id`) y el resumen se precarga en
> la conversación. `CartJwtService` y `/api/v1/cart/{jwt}` quedan para el
> bot externo. Ver `Chats-Clientes.md`.

Ambos respetan que la **caja debe estar abierta** y el menú esté activo
(de lo contrario `MenuController::showPublic` responde 423
`cash_register_closed`).

---

## Modelo de datos

### QR de mesa

| Tabla | Campos clave | Notas |
|---|---|---|
| `tables` | `id`, `company_nit`, `branch_id`, `number`, `qr_token` (Str::random(40)), `seats`, `archived_at` | El `qr_token` se imprime en el QR físico. Regenerable. |
| `table_sessions` | `id`, `table_id`, `status` (`open`/`locked`/`closed`/`expired`), `accepts_new_guests` | Una sesión activa por mesa (UNIQUE parcial). |
| `table_session_guests` | `id`, `table_session_id`, `display_name`, `phone` (normalizado), `client_uuid`, `joined_at`, `device_token_hash` | Identifica al comensal. Cookie `tdt_*` ligada por hash. |
| `orders` | `table_session_id`, `status='pending_approval'` (buffer) | Buffer única por sesión. Al aprobarse migra a `pending`. |
| `order_items` | `name`, `unit_price` (snapshot), `quantity`, `notes`, `status` (`pending_approval`/`approved`/`rejected`/`cancelled`/`served`) | Precio congelado al agregar. |
| `order_item_cancellation_requests` | `order_item_id`, `reason`, `status` (`pending`/`approved`/`denied`) | Solicitud del comensal cuando el ítem ya está aprobado. |
| `order_notes` | `scope` (`group`/`kitchen_alert`), `body` | Notas grupales o alertas a cocina. |

### Carrito Bot (legacy)

| Tabla | Campos clave | Notas |
|---|---|---|
| `cart_sessions` | `id` (UUID), `company_nit`, `jwt_jti` (UNIQUE), `client_phone`, `status` (`active`/`abandoned`/`converted`), `expired_at` | Sin `updated_at` (`$timestamps=false`). |
| `cart_items` | `cart_session_id`, `menu_item_id`, `name`, `price`, `quantity`, `category`, `notes` | Snapshot: precio congelado al agregar. |

---

## Permisos RBAC

**Ambos canales son públicos** — no requieren rol ni `company_users`.
La autoría se ata a la sesión:

| Capa | Mecanismo |
|---|---|
| QR de mesa | `qr_token` válido en URL + cookie `tdt_*` firmada (httpOnly, Lax, ~12 h). Middleware `table.guest` resuelve `TableSessionGuest` de la cookie e inyecta como `request->attributes['table_guest']`. |
| Carrito bot | `CartJwt` corto (HS256, secreto separado `CART_JWT_SECRET`, TTL 70 min). `jti` único en `cart_sessions.jwt_jti`. |

Lo único que distingue a un comensal de otro es su `device_token_hash`.
Las acciones se auditan con `actor_type='guest'` y `guest_id` en el
metadata.

---

## Endpoints

### QR de mesa (prefijo `/api/v1/public/table/{qr_token}`)

Rate-limit dual `table-public` (IP + qr_token). Regex
`qr_token=[A-Za-z0-9]+`.

| Método | Ruta | Middleware | Descripción |
|---|---|---|---|
| `GET` | `/api/v1/public/table/{qr_token}` | `throttle:table-public` | Contexto de unión: mesa, sede, branding, `already_joined`. |
| `POST` | `/api/v1/public/table/{qr_token}/join` | `throttle:table-public` | Crea/une comensal, setea cookie `tdt_*`, devuelve contexto del menú. |
| `GET` | `/api/v1/public/table/{qr_token}/contact-lookup` | `throttle:table-public` | Autocompleta `display_name` por `phone`. |
| `GET` | `/api/v1/public/table/{qr_token}/menu` | + `table.guest` | Menú activo + estado del carrito. |
| `GET` | `/api/v1/public/table/{qr_token}/state` | + `table.guest` | Polling 5s del estado de la sesión grupal. |
| `POST` | `/api/v1/public/table/{qr_token}/items` | + `table.guest` | Agrega ítem al buffer (status `pending_approval`). |
| `PATCH` | `/api/v1/public/table/{qr_token}/items/{item}` | + `table.guest` | Edita cantidad/notas (solo `pending_approval`). |
| `DELETE` | `/api/v1/public/table/{qr_token}/items/{item}` | + `table.guest` | Cancela. Si el ítem ya pasó a `approved`, crea `OrderItemCancellationRequest`. |
| `POST` | `/api/v1/public/table/{qr_token}/submit` | + `table.guest` | Envía la tanda buffer al mesero. |
| `POST` | `/api/v1/public/table/{qr_token}/notes` | + `table.guest` | Nota `group` (al mesero) o `kitchen_alert` (a cocina). |

Controlador: `App\Http\Controllers\Public\TableJoinController` (alta) y
`App\Http\Controllers\Public\TableOrderController` (carrito). Servicios:
`TableSessionService`, `TableOrderService`.

### Carrito Bot

| Método | Ruta | Auth | Descripción |
|---|---|---|---|
| `POST` | `/api/v1/cart/migrate-jwt/{jwt}` | CartJwt en URL | `updateOrCreate` por `jwt_jti`. Devuelve items. |
| `GET` | `/api/v1/cart/{jwt}` | CartJwt en URL | Refresh estado. 404 si la sesión no existe. |
| `POST` | `/api/v1/cart/apply-coupon` | JWT de usuario (operador) | Aplica cupón. **Nota**: este endpoint NO es público — lo usa el cajero, no el comensal. |

Controlador: `App\Http\Controllers\Api\CartController`. Servicio:
`CartJwtService`.

---

## Flujos funcionales

### 1. Escaneo del QR (alta del comensal)

1. El cliente escanea el QR pegado en la mesa. La URL navega a la SPA
   `pages/table/join.tsx`, que llama
   `GET /api/v1/public/table/{qr_token}`.
2. `TableJoinController::show` resuelve la mesa por `qr_token` vía
   `TableSessionService::resolveTable`, valida que la empresa esté
   operativa (`ensureCompanyOperational`) y retorna `TableJoinContextResource`
   con `table`, `branch`, `company`, `qr_token`, `already_joined`.
3. Si la cookie `tdt_*` del dispositivo ya apunta a un guest activo en
   esa mesa, `already_joined=true` y la SPA navega directo al menú sin
   volver a pedir nombre/celular.

### 2. Unión (`POST /join`)

Body: `{ display_name, phone }`. Sanitizados por `JoinTableRequest`
(NFC + control-chars strip + `SafePlainText`).

`TableSessionService::openOrJoin`:

- Si NO existe sesión activa para la mesa → abre una nueva
  (`TableSession::status='open'`, `accepts_new_guests=true`) y emite
  `table.session.opened`.
- Si existe y `accepts_new_guests=true` → agrega `TableSessionGuest` y
  emite `table.guest.joined`.
- Si existe pero `accepts_new_guests=false` → `DomainException` → 422
  con `{errors:{session:[...]}}`.
- Si la empresa está en `mora`/`delinquent`/`suspended` → `DomainException`.

Tras crearse el guest:

- Se setea cookie HttpOnly Lax `tdt_<sha256(qr_token)[:16]>` firmada con
  el `device_token` del guest. TTL = `config('tables.device_token_ttl_hours')`
  (default 12 h).
- Se devuelve `TableMenuContextResource` (igual shape que el endpoint
  `GET /menu`) para hidratar la pantalla sin segundo round-trip.

### 3. Vista de menú (`GET /menu`)

Middleware `table.guest` resuelve `TableSessionGuest` desde la cookie.
Si no hay cookie o expiró, responde 401 con mensaje "No estás en la mesa.
Volvé a escanear el QR.". La SPA captura este 401 y redirige a la
pantalla de join.

El recurso devuelve el menú activo de la sede (`RestaurantMenu::active()`
con `branch_id` de la mesa), sólo ítems con `available=true`. El cliente
NUNCA ve precios de otras sedes.

### 4. Manipulación del carrito

| Acción | Audit |
|---|---|
| `POST /items` | `table.item.added_by_customer` |
| `PATCH /items/{id}` (notes/quantity) sobre `pending_approval` | `table.item.edited_by_customer` |
| `DELETE /items/{id}` sobre `pending_approval` | `table.item.cancelled_by_customer` |
| `DELETE /items/{id}` sobre `approved` | `table.item.cancellation_requested` + crea `OrderItemCancellationRequest` |
| `POST /submit` (envía buffer al mesero) | `table.batch.submitted` |
| `POST /notes` `scope=group` | `table.note.group_added` |
| `POST /notes` `scope=kitchen_alert` | `table.note.kitchen_alert_added` |

El precio nunca viaja desde el cliente: el backend lo lee del menú
activo en BD y lo congela en `order_items.unit_price`. Si el menú cambia
después, los ítems ya agregados conservan su snapshot original.

### 5. Polling de estado (`GET /state`)

`pages/table/menu.tsx` consulta cada 5 s. Devuelve:

- Ítems de la buffer del comensal con `status` actualizado.
- Ítems aprobados (con `served=true|false` para semáforo).
- Notas del grupo.
- Decisiones de cancelación pendientes del mesero.
- Saldo cobrado vs pendiente.

Esto refleja en tiempo casi-real las aprobaciones, rechazos y entregas
de cocina sin necesidad de WebSockets.

### 6. Cierre y pago

El comensal **NO paga desde el frontend público**. El cobro lo ejecuta
el cajero desde `/orders/table-sessions/{id}` (ver `Mesas.md` §Pago
dividido). El polling de `/state` refleja cuando un comensal específico
ya está pagado por el cajero, para mostrar UI "Tu cuenta está saldada".

---

## Carrito Bot (`/cart/{jwt}`) — legacy

Esta es la superficie pública que armó el bot de WhatsApp. Coexiste con
el QR de mesa pero **no** comparte modelo: vive en `cart_sessions` y
`cart_items`. La página `pages/cart.tsx` (si está activa) la consume.

### Anatomía del CartJWT

Emitido por `CartJwtService::issue($companyNit, $clientPhone)`:

```json
{
  "jti": "uuid-...",
  "company_nit": "1",
  "client_phone": "573001112233",
  "iat": 1714999999,
  "exp": 1715004199
}
```

- Secret separado (`CART_JWT_SECRET`) — no hay cross-impersonate con
  usuarios.
- TTL 70 min (`CART_JWT_TTL=4200`).
- `jti` UUID persistido en `cart_sessions.jwt_jti` para idempotencia.

### Resolución perezosa

`CartController` resuelve `CartJwtService` vía `Container::make()`. Si
`CART_JWT_SECRET` no está configurado:

- `verify()` lanza `RuntimeException`.
- El controlador responde **401** con mensaje genérico (no 500).

### Limitaciones

`pages/cart.tsx` es **solo lectura** desde el frontend público: items,
total, descuento. No permite agregar ni quitar — eso lo hace el bot
externo. El botón "Confirmar pedido" delega al endpoint del bot que
crea la `Order` final (no implementado en este repo).

---

## Componentes frontend

| Archivo | Propósito |
|---|---|
| `pages/table/join.tsx` | Pantalla de unión: nombre + celular. Hidrata desde `GET /public/table/{qr}`. |
| `pages/table/menu.tsx` | Menú + carrito + estado de la sesión (poll 5s). Layout sin sidebar. Lista de comensales fuera del header sticky (v1.43.1): solo empresa/sede/mesa quedan fijos al scrollear en móvil. |
| `lib/api-fetch.ts` | Cliente fetch que envía la cookie `tdt_*` automáticamente. |
| `pages/cart.tsx` | Carrito legacy del bot WhatsApp (solo lectura). |
| `components/ui/table-skeleton.tsx` | Loading states. |

Layout standalone (`min-h-dvh`, sin chrome de app autenticada) — la UI
está pensada para móvil del comensal.

---

## Estados y transiciones

```
TableSession (lado público):
  (sin sesión) ──(POST /join primer guest)──► open
       open ──(POST /join siguientes)──► open (más guests)
       open ──(mesero approve-batch)──► locked

OrderItem (lado público):
  (sin ítem) ──(POST /items)──► pending_approval
  pending_approval ──(PATCH)──► pending_approval (edit)
  pending_approval ──(DELETE)──► cancelled (sin request)
  pending_approval ──(mesero approve)──► approved ──► served (KDS)
  approved ──(DELETE)──► cancellation_request:pending
```

---

## Eventos de auditoría

Todos emitidos por `TableSessionService` y `TableOrderService`. El actor
es el `guest` (no un `user_id`).

| Acción | Disparador |
|---|---|
| `table.session.opened` | Primer guest abre sesión |
| `table.guest.joined` | Guest se une a sesión existente |
| `table.item.added_by_customer` | `POST /items` |
| `table.item.edited_by_customer` | `PATCH /items/{id}` |
| `table.item.cancelled_by_customer` | `DELETE` sobre `pending_approval` |
| `table.item.cancellation_requested` | `DELETE` sobre `approved` |
| `table.batch.submitted` | `POST /submit` |
| `table.note.group_added` | `POST /notes` scope=group |
| `table.note.kitchen_alert_added` | `POST /notes` scope=kitchen_alert |

---

## Validaciones contables

- Precios **siempre** leídos del menú activo en BD; el cliente nunca
  envía precios.
- `OrderItem.unit_price` se congela al agregar (snapshot).
- `orders.total` se recalcula server-side en cada cambio
  (`OrderController::computeItemsTotal`).
- El **comensal no paga** desde esta superficie. El cobro ocurre en
  `TableCashierService` con `DB::transaction` + `Order::lockForUpdate`
  y crea `PaymentReceipt` inmutable (ver `Mesas.md` y `Caja-POS.md`).
- Si el menú activo no aplica para hoy (`active_days`) → `POST /items`
  responde 422.
- Si la caja está cerrada → endpoints `POST /items` y `POST /submit`
  responden 423 `cash_register_closed`.

---

## Edge cases y empty states

| Caso | Respuesta |
|---|---|
| `qr_token` inexistente o archivado | 404 |
| Mesa de una **sede archivada** (archivar sede no archiva sus mesas) | 404 — guard en `TableSessionService::resolveTable` + `TableResolveController::showByToken` (v1.30.3) |
| Empresa en `mora`/`delinquent`/`suspended` | 422 `company.not_operational` |
| Sin cookie `tdt_*` en endpoints protegidos | 401 con mensaje "No estás en la mesa. Volvé a escanear el QR." |
| Cookie de otra mesa (qr_token distinto) | 401 (cookie nombre incluye `hash(qr_token)`) |
| Sesión `closed` o `expired` y se intenta agregar item | 422 `session.closed` |
| Caja cerrada | 423 `cash_register_closed` |
| Ítem no disponible (`available=false`) | 422 (mensaje genérico) |
| Editar/cancelar ítem de otro comensal | 403 |
| Rate limit excedido (`table-public`: IP+qr_token) | 429 |
| Empresa sin menú activo | 422 `{errors:{menu:[...]}}` |
| CartJWT inválido o `CART_JWT_SECRET` ausente | 401 (no 500) |
| CartJWT expirado | 401 |
| `/cart/{jwt}` sin sesión persistida | 404 |

---

## Configuración

```env
# QR de mesa
TABLES_DEVICE_TOKEN_TTL_HOURS=12         # cookie tdt_*
TABLES_SESSION_INACTIVITY_MINUTES=240    # job de expiración

# Carrito Bot (legacy)
CART_JWT_SECRET=...                       # requerido para activar
CART_JWT_TTL=4200                         # 70 min
CART_BASE_URL=https://pedidos.flexyflow.co
```

Rate limiters (en `AppServiceProvider`):

- `table-public`: 60 req/min por (IP, qr_token).
- `menu-scan-public`: 30 req/min por (IP, nit).
