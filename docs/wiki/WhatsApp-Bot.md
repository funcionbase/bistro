# WhatsApp Bot

> Estado: En desarrollo (contratos estables; integración del bot externo evoluciona)
> Versión API: v1
> Owner: equipo de plataforma

---

## Visión general

El bot de WhatsApp es un **proceso externo** (n8n + Meta Cloud API) que conversa con clientes finales y crea pedidos en flexyflow. Se autentica con un JWT separado (`BOT_JWT_SECRET`) y consume:

- Endpoint público del catálogo (`/api/v1/public/menu/{companyNit}`) — sin auth.
- Endpoints externos bajo `/api/external/*` (auth `bot.jwt`): status del restaurante, handoff a humano, sincronización de chats y fidelización.
- El bot **NO** llama directamente `/api/v1/coupons/{code}/validate` ni `/api/v1/cart/apply-coupon`: esas rutas están bajo el JWT de usuario (operador POS, #174 P2-3). El flujo de carrito de comensal se arma con `CartJwtService` (JWT cifrado AES-256-CBC) y la URL `{CART_BASE_URL}/{jwt}` que el bot envía al cliente para que termine el pedido en la web.

---

## Autenticación del bot

El bot recibe un JWT firmado con `BOT_JWT_SECRET` (HS256) y TTL configurable (`BOT_JWT_TTL`, default `3600` segundos = 1h). El payload va **en claro** (no cifrado) e incluye solo:

| Campo | Descripción |
|-------|-------------|
| `company_nit` | Empresa a la que pertenece el bot (única scope) |
| `iat`, `exp` | Timestamps |

`ValidateBotJwt` (alias `bot.jwt`) inyecta `bot_company_nit` y `bot_jwt_payload` en el request. Además, si la empresa está bloqueada por mora (`fully_blocked` / `!canServePublic()`, #193), responde **503** con `code='company_unavailable'` para que el bot deje de procesar mensajes.

**Diferencias con el JWT de usuario:**

| Aspecto | JWT usuario | JWT bot |
|---------|-------------|---------|
| Clave | `JWT_SECRET` | `BOT_JWT_SECRET` |
| TTL default | 1h | 1h (`BOT_JWT_TTL=3600`) |
| Payload | Encriptado AES-256-CBC (`JWT_PAYLOAD_ENCRYPTION_KEY`) | En claro (firmado HS256) |
| Claims | active_company, permisos, branch | solo `company_nit + iat + exp` |
| Refresh | Auto en `<300s` | Manual cuando expira |
| Middleware | `jwt` | `bot.jwt` |

Existe además un **JWT distinto para el carrito web** (`CartJwtService`, header `typ=whatsapp_web_order`) firmado con `CART_JWT_SECRET` y payload cifrado en AES-256-CBC con `JWT_PAYLOAD_ENCRYPTION_KEY`. TTL `CART_JWT_TTL` (default `4200s` = 70 min). El bot emite la URL `{CART_BASE_URL}/{jwt}` al cliente; el carrito vive en `cart_sessions` indexado por `jwt_jti`.

---

## Endpoints externos

Todos bajo `prefix=external` + middleware `bot.jwt`. **No** llevan el prefijo `v1` (contrato de API): viven en `/api/external/*`. El `company_nit` viene del JWT — nunca del body.

| Método | Ruta | Controller | Descripción |
|--------|------|------------|-------------|
| `GET` | `/api/external/hours/status` | `ExternalHoursStatusController@show` | ¿Está abierto el restaurante? |
| `POST` | `/api/external/chats/handoff` | `ExternalChatHandoffController@store` | Bot solicita intervención humana |
| `POST` | `/api/external/chats/messages` | `ExternalChatMessageController@store` | Push de mensaje al cache local |
| `GET` | `/api/external/chats/messages` | `ExternalChatMessageController@index` | Lee delta de mensajes desde BD |
| `POST` | `/api/external/loyalty/lookup` | `ExternalLoyaltyController@lookup` | Consulta saldo de puntos (intent `/puntos`, #122) |
| `POST` | `/api/external/loyalty/redeem` | `ExternalLoyaltyController@redeem` | Canjea puntos por cupón (intent `/canjear`) |

```http
GET /api/external/hours/status HTTP/1.1
Authorization: Bearer <BOT_JWT>
```

```http
HTTP/1.1 200 OK
{
  "data": {
    "company_nit": "900123456-7",
    "is_open": true,
    "reason": "within_hours",
    "menu_available": true,
    "menu_visibility_reason": "visible"
  }
}
```

Valores posibles de `reason`: `within_hours`, `out_of_hours`, `open_by_exception`, `closed_by_exception`, `not_in_service_window`, `no_schedule_defined`.

---

## Endpoints públicos consumidos por el bot

### Menú público

```http
GET /api/v1/public/menu/900123456-7 HTTP/1.1
```

```http
HTTP/1.1 200 OK
{
  "menu": {
    "name": "Carta del día",
    "categories": [
      {
        "name": "Platos principales",
        "items": [
          { "id": "uuid", "name": "Bandeja paisa", "price": 32000, "available": true }
        ]
      }
    ]
  }
}
```

`GET /api/v1/public/menu/{companyNit}` (`MenuController@showPublic`) NO requiere autenticación (TC-3.3.1, issue #26). El controller no consume datos del JWT y solo devuelve ítems disponibles del menú activo. El bot lo consume igual que cualquier cliente.

### Cupones y carrito

Las rutas `GET /api/v1/coupons/{code}/validate` y `POST /api/v1/cart/apply-coupon` están bajo el JWT de **usuario** (operador POS), NO bajo `bot.jwt`. El comensal aplica cupones desde la web del carrito (`/cart/{jwt}`, autenticado con el `CartJWT` cifrado).

---

## Sesión de carrito

Para pedidos multi-turno, el flujo usa una sesión de carrito (`cart_sessions`) identificada por el `jwt_jti` único del `CartJWT`. El bot emite el CartJWT con `CartJwtService::issue(...)` y le manda al cliente la URL `{CART_BASE_URL}/{jwt}` para que termine el pedido en la web.

| Tabla | Campos clave |
|-------|--------------|
| `cart_sessions` | `id` UUID, `jwt_jti` (unique), `company_nit`, `branch_id`, `client_phone`, `status` (`active`/`abandoned`/`converted`), `expired_at`, `created_at` |
| `cart_items` | `id` UUID, `cart_session_id`, `branch_id`, `menu_item_id`, `name`, `price` `decimal(12,2)`, `quantity`, `category`, `notes` |

Notas:
- `cart_items` es tabla separada (1:N), NO JSONB embebido.
- No hay columna `coupon_id` ni `updated_at` en `cart_sessions` (timestamps off).
- Estados: `active` → `abandoned` por TTL, o `active` → `converted` cuando se crea la `Order` real.
- El `client_phone` en `cart_sessions` permite la métrica de abandono y la deduplicación de sesiones por número.

---

## Flujo end-to-end

```
1. Cliente escribe al WhatsApp del restaurante (webhook Meta → bot externo)
2. Bot consulta /api/external/hours/status (¿abierto?)
3. Si abierto:
   a. Bot consulta /api/v1/public/menu/{nit} (catálogo)
   b. Conversa, arma un carrito y emite CartJWT (CartJwtService)
   c. Crea cart_sessions + cart_items con jwt_jti único
   d. Envía al cliente la URL {CART_BASE_URL}/{jwt} para confirmar en web
   e. Al confirmar, la web crea la Order y marca cart_sessions.status='converted'
   f. El restaurante ve el pedido en el panel (kanban) en tiempo real
4. Si cerrado:
   - Bot responde con horario y reason del status
5. Sync chats:
   - Bot empuja mensajes a /api/external/chats/messages para historial local
   - Para handoff a humano: POST /api/external/chats/handoff
6. Fidelización:
   - Intent /puntos → POST /api/external/loyalty/lookup
   - Intent /canjear → POST /api/external/loyalty/redeem
```

---

## Notas de seguridad

- El bot **solo puede operar sobre su `company_nit`**; el `company_nit` viene del payload del JWT, NUNCA del body. `ExternalHoursStatusController` y los `External*Controller` leen `bot_company_nit` inyectado por `ValidateBotJwt`.
- El JWT del bot **no es refrescable automáticamente**; debe rotar por gestión externa cuando expira.
- `bot.jwt` **no exige** `company.access`/`branch.access` — es un alias dedicado más simple. La empresa se valida por NIT al consultar.
- Si la empresa está bloqueada por mora (`!canServePublic()`, #193) el middleware responde **503 `company_unavailable`** para que el bot deje de procesar.
- El menú público (`/api/v1/public/menu/{nit}`) NO requiere autenticación; cualquier visitante o bot lo consulta.
- Los `cart_sessions` se marcan como `abandoned` cuando exceden `expired_at`; las activas durante reinicios persisten en BD.
- Webhooks de WhatsApp Cloud API (`/api/webhooks/whatsapp`) están whitelisted en `NormalizeStrings` para preservar el firmado de Meta.
