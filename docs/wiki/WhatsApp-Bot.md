# WhatsApp Bot

> Estado: contrato estable. El transporte de WhatsApp pasó de Meta Cloud API a **Evolution API** (Baileys, vinculación por QR). La automatización (n8n) es **opcional por cliente** y su despliegue queda a futuro; este documento define el contrato para cuando se integre.
> Versión API: v1
> Owner: equipo de plataforma

---

## Visión general

El bot de WhatsApp es un **proceso externo** (n8n) que conversa con clientes finales y crea pedidos en flexyflow. El transporte de WhatsApp es **Evolution API** (Baileys), no Meta Cloud API: bistro recibe los mensajes y se los empuja al bot; el bot **nunca** habla con Evolution ni con WhatsApp directamente (§9.4). La automatización es opcional — sin flujo, la bandeja de operadores atiende todo (§5.6).

El bot se autentica con un **token por flujo** (`bot.token`, §7.5.1) y consume:

- Endpoint público del catálogo (`/api/v1/public/menu/{companyNit}`) — sin auth.
- Endpoints externos bajo `/api/external/*`: status del restaurante, **responder la conversación** (`/chats/reply`), handoff a humano, sincronización de chats y fidelización.
- Recibe un **push saliente firmado** (webhook a n8n) cuando entra un mensaje u ocurre un evento (§9.2, ver abajo).
- El bot **NO** llama directamente `/api/v1/coupons/{code}/validate` ni `/api/v1/cart/apply-coupon`: esas rutas están bajo el JWT de usuario (operador POS, #174 P2-3). El flujo de carrito de comensal se arma con `CartJwtService` (JWT cifrado AES-256-CBC) y la URL `{CART_BASE_URL}/{jwt}` que el bot envía al cliente para que termine el pedido en la web.

---

## Autenticación del bot — token por flujo (§7.5.1)

El bot se autentica con un **token de automatización por flujo**, no con un secreto global. Cada flujo (fila en `automation_flows`, por `(company_nit, branch_id)`) tiene su propio token.

| Aspecto | Diseño |
|---|---|
| Formato | `ffw_<flow_uuid_sin_guiones>_<32B base64url>`. Header `Authorization: Bearer ffw_…`, siempre HTTPS |
| Emisión | Se genera al crear el flujo desde el panel (Empresa → WhatsApp → Automatización) y se muestra **una sola vez** (patrón PAT). Si se pierde, se rota |
| Almacenamiento | Solo el **SHA-256** del componente aleatorio (`token_hash`). Un dump de la BD no entrega tokens usables. `token_last4` para la UI |
| Verificación | El middleware `bot.token` extrae el uuid del prefijo → carga el flujo por PK → `hash_equals` → rechaza si `enabled=false`. Una query, sin escaneo |
| Scope | El token pertenece a un flujo atado a `(empresa, sede)`. Un flujo de sede no puede tocar chats de otra sede; uno de empresa (`branch_id NULL`) cubre todas |
| Revocación | Rotar el token o poner `enabled=false` → 401 inmediato para ese flujo, sin afectar a nadie más |

`ValidateBotToken` (alias `bot.token`) inyecta `bot_company_nit`, `bot_branch_id` y `bot_flow_id` en el request. La empresa y la sede se derivan del **token**, nunca del body. Si la empresa está bloqueada por mora (`!canServePublic()`, #193), responde **503** con `code='company_unavailable'`.

### Ventana de convivencia con el JWT legado

`bot.token` acepta **también** el JWT legado firmado con `BOT_JWT_SECRET` (HS256, claim `company_nit`, TTL `BOT_JWT_TTL`). Cada uso del legado deja un log `bot.auth.legacy_jwt`. Cuando ese log queda en cero, se retira el soporte de JWT, `BotJwtService` y el secreto global (§11.1). Configuración en n8n: reemplazar el nodo Code que firmaba el JWT por una credencial nativa de tipo *Header Auth* con el token `ffw_…`.

Existe además un **JWT distinto para el carrito web** (`CartJwtService`, header `typ=whatsapp_web_order`) firmado con `CART_JWT_SECRET` y payload cifrado en AES-256-CBC con `JWT_PAYLOAD_ENCRYPTION_KEY`. TTL `CART_JWT_TTL` (default `4200s` = 70 min). El bot emite la URL `{CART_BASE_URL}/{jwt}` al cliente; el carrito vive en `cart_sessions` indexado por `jwt_jti`.

---

## Endpoints externos

Todos bajo `prefix=external` + middleware `bot.token`. **No** llevan el prefijo `v1` (contrato de API): viven en `/api/external/*`. El `company_nit` (y la `branch_id`) vienen del **token del flujo** — nunca del body.

| Método | Ruta | Controller | Descripción |
|--------|------|------------|-------------|
| `GET` | `/api/external/hours/status` | `ExternalHoursStatusController@show` | ¿Está abierto el restaurante? |
| `POST` | `/api/external/chats/reply` | `ExternalChatReplyController@reply` | **El bot responde la conversación** (envía por Evolution, `sender='bot'`) |
| `POST` | `/api/external/chats/{id}/bot` | `ExternalChatReplyController@bot` | Pausar/reanudar el bot en un chat (§9.8) |
| `POST` | `/api/external/chats/{id}/typing` | `ExternalChatReplyController@typing` | Indicador "escribiendo…" (`sendPresence`) |
| `POST` | `/api/external/chats/handoff` | `ExternalChatHandoffController@store` | Bot solicita intervención humana |
| `POST` | `/api/external/chats/messages` | `ExternalChatMessageController@store` | Push de mensaje al cache local |
| `GET` | `/api/external/chats/messages` | `ExternalChatMessageController@index` | Lee delta de mensajes desde BD |
| `POST` | `/api/external/loyalty/lookup` | `ExternalLoyaltyController@lookup` | Consulta saldo de puntos (intent `/puntos`, #122) |
| `POST` | `/api/external/loyalty/redeem` | `ExternalLoyaltyController@redeem` | Canjea puntos por cupón (intent `/canjear`) |

### `POST /api/external/chats/reply` — el endpoint más sensible (§9.6)

Escribe en la conversación de un cliente y dispara un envío real por WhatsApp. n8n **no** ve credenciales de WhatsApp ni sabe en qué servidor Evolution vive el canal. Cuerpo:

```http
POST /api/external/chats/reply HTTP/1.1
Authorization: Bearer ffw_…
Content-Type: application/json

{ "chat_id": "uuid", "body": "Sí, hacemos domicilio hasta las 10 pm.", "idempotency_key": "opcional" }
```

Controles (en orden): 404 si el `chat_id` no es de la empresa/sede del token (no confirma existencia ajena); 409 `channel_disconnected` si el canal está caído; 409 `chat_taken_by_operator` si `bot_paused=true`; 429 por rate limit de chat (~10/min) y de empresa (60/min); idempotencia por `idempotency_key` (5 min). Devuelve `{message_id, chat_id, status}` — **no** eco de historial ni teléfono.

```http
GET /api/external/hours/status HTTP/1.1
Authorization: Bearer ffw_…
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

## Push saliente — webhook firmado a n8n (§9.2)

Al retirar Meta, los mensajes llegan a bistro y a ningún otro lado. Para que el bot reaccione en tiempo real, bistro **empuja** un webhook firmado a la URL del flujo (`automation_flows.url`) cuando el chat resuelve un flujo habilitado y suscrito al evento. Encolado (`DispatchAutomationWebhookJob`, cola `database`, `tries=5`, backoff 10 s→10 min, timeout 10 s). Cada intento deja fila en `company_whatsapp_account_events` (`event_type='automation_delivery'`) — visible en la tabla de entregas del panel.

```http
POST https://n8n.<host>/webhook/bistro-whatsapp HTTP/1.1
X-Flexyflow-Event: chat.message.received
X-Flexyflow-Delivery: 018f…uuid
X-Flexyflow-Signature: sha256=<hmac_hex(body, automation_flows.secret)>
Content-Type: application/json

{
  "event": "chat.message.received",
  "sent_at": "2026-07-22T14:03:11-05:00",
  "company_nit": "900123456-7",
  "branch_id": "uuid",
  "channel": { "id": "uuid", "label": "Sede Norte", "phone_e164": "+57310..." },
  "chat":    { "id": "uuid", "client_phone": "+57300...", "client_name": "Ana", "bot_paused": false },
  "message": { "id": "uuid", "sender": "client", "body": "hola, tienen domicilio?", "media_type": null, "sent_at": "..." }
}
```

**Verificación de la firma en n8n**: recomputar `HMAC-SHA256(raw_body, secret)` y comparar con `X-Flexyflow-Signature` (el secreto se copia una vez al crear/rotar el flujo). Eventos: `chat.message.received`, `chat.handoff.requested`, `chat.bot_toggled`, `channel.status.changed`. **Anti-loop**: `chat.message.received` nunca se emite para `sender='bot'`.

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
1. Cliente escribe → Evolution (Baileys) → webhook loopback a bistro
   → bistro persiste el chat y EMPUJA un webhook firmado a n8n (§9.2)
2. Bot consulta /api/external/hours/status (¿abierto?)
3. Si abierto:
   a. Bot consulta /api/v1/public/menu/{nit} (catálogo)
   b. Responde por /api/external/chats/reply (bistro envía por Evolution)
   c. Conversa, arma un carrito y emite CartJWT (CartJwtService)
   d. Crea cart_sessions + cart_items con jwt_jti único
   e. Envía al cliente la URL {CART_BASE_URL}/{jwt} para confirmar en web
   f. Al confirmar, la web crea la Order y marca cart_sessions.status='converted'
   g. El restaurante ve el pedido en el panel (kanban) en tiempo real
4. Si cerrado:
   - Bot responde con horario y reason del status
5. Handoff a humano: POST /api/external/chats/handoff (deja bot_paused=true)
6. Fidelización:
   - Intent /puntos → POST /api/external/loyalty/lookup
   - Intent /canjear → POST /api/external/loyalty/redeem
```

---

## Notas de seguridad

- El bot **solo puede operar sobre su `(company_nit, branch_id)`**; ambos vienen del **token del flujo**, NUNCA del body. Los `External*Controller` leen `bot_company_nit`/`bot_branch_id` inyectados por `ValidateBotToken`. Un `reply` sobre un chat de otra empresa/sede devuelve **404**.
- El token del flujo es **revocable**: rotar o `enabled=false` lo invalida al instante, sin afectar a otros flujos. Se guarda hasheado (SHA-256), no cifrado reversible.
- `bot.token` **no exige** `company.access`/`branch.access` — es un alias dedicado. El scope lo da el token.
- Si la empresa está bloqueada por mora (`!canServePublic()`, #193) el middleware responde **503 `company_unavailable`** para que el bot deje de procesar.
- El webhook saliente lleva contenido de conversaciones: la UI exige `https://` y firma cada entrega con HMAC-SHA256 (`X-Flexyflow-Signature`). El destino lo decide el cliente.
- El menú público (`/api/v1/public/menu/{nit}`) NO requiere autenticación; cualquier visitante o bot lo consulta.
- Los `cart_sessions` se marcan como `abandoned` cuando exceden `expired_at`; las activas durante reinicios persisten en BD.
- El webhook entrante de Evolution (`/api/v1/webhooks/whatsapp/evolution/{account}`) autentica por secreto de 32 bytes por canal (header `X-Flexyflow-Token`), responde igual (401 sin cuerpo) ante secreto inválido y canal inexistente, y está whitelisted en `NormalizeStrings`. El webhook legado de Meta (`/api/webhooks/whatsapp`) coexiste hasta el corte de F4.
