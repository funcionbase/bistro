# Chats con Clientes

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

Panel operativo `/chats` para conversar con clientes por WhatsApp (Cloud
API). Cada conversación agrupa todos los mensajes intercambiados con un
mismo `(company_nit, client_phone)`. Página `pages/chats.tsx`, hook
`useChats`, controller `App\Http\Controllers\Api\ChatController`.

Mientras la integración con n8n no esté operativa, todos los chats nuevos
llegan con `bot_paused=true` y son atendidos manualmente por un operador.

---

## Resumen

| Capacidad | Detalle |
|---|---|
| Por sede | Sí. `Chat` usa `BelongsToBranch`. Owner puede reasignar entre sedes. |
| Polling | 5 s desde `useChats` (`pauseWhenHidden` por defecto). |
| Mensajería saliente | Síncrona vía `WhatsappOutboundMessageSender`. |
| Read receipts | Opt-in por `company_settings.whatsapp_read_receipts` (default `false`). |
| Webhook | `POST /api/v1/webhooks/whatsapp` firmado HMAC SHA-256 con App Secret. |
| Purga | Schedule `chats:purge-old` diario `03:00` (60 días sin actividad). |

---

## Modelo de datos

### `chats`

| Campo | Notas |
|---|---|
| `id` (uuid) | PK. |
| `company_nit` | FK. |
| `client_phone` | UNIQUE `(company_nit, client_phone)`. |
| `client_name` | Nullable. Viene de profile WhatsApp o se setea manual. |
| `contact_id` | FK a `contacts`. Nullable (chat puede existir sin Contact CRM). |
| `branch_id` | Sede que atiende actualmente la conversación. |
| `status` | `open` \| `closed`. |
| `source` | `whatsapp` \| `instagram` \| `facebook` \| `otro`. |
| `bot_paused` | bool. `true` mientras n8n no esté operativo o cuando un operador toma control. |
| `handoff_requested_at`, `handoff_reason` | Timestamps de handoff desde el bot externo. |
| `last_message_at` | Timestamp del último mensaje (cualquier sender). |
| `meta_synced_at`, `meta_conversation_id` | Metadata Meta. |

Un chat por `(company_nit, client_phone)`. Si el mismo teléfono escribe a
dos restaurantes, son dos chats independientes.

### `chat_messages`

| Campo | Notas |
|---|---|
| `id` (uuid) | PK. |
| `chat_id` | FK. |
| `sender` | `client` \| `bot` \| `operator`. |
| `body` | Texto, nullable si es solo media. Hasta 4096 chars (límite WhatsApp). |
| `status` | `sent` / `delivered` / `read` / `failed` (lado operator). |
| `meta_message_id` | wamid de Meta (cliente) o response id (operator). |
| `media` (jsonb) | `[{type:'audio'|'image'|'document', mime, url, duration}]`. |
| `sent_at` | Timestamp del mensaje. |
| `created_at` | Persistencia interna. |

---

## Permisos RBAC

| Slug | owner | admin | waiter | cashier | manager |
|---|---|---|---|---|---|
| `chats.read` | RCUD | RCUD | R--- | R--- | R--- |
| `chats.update` | RCUD | RCUD | --U- | --U- | --U- |
| `chats.reassign_branch` | (bypass) | ---- (asignable) | ---- | ---- | ---- |

`chats.update` cubre enviar mensaje, pausar/reanudar bot y editar contacto.
`chats.read` cubre lista, detalle, ver datos del cliente y marcar como leído
(es evento de visualización, no de escritura).

`chats.reassign_branch` solo cuenta cuando combinado con acceso real a la
sede destino vía `branch_users`. Owner bypasa por `role.is_system=true`.

Ver `application/constants/PERMISSIONS_CATALOG.md` y `BRANCH_RBAC.md`.

---

## Endpoints

Prefijo: `/api/v1`. Middleware: `jwt` + `company.access` + permission.

| Método | Ruta | Permiso | Notas |
|---|---|---|---|
| GET | `/chats` | `chats.read,read` | Lista con `?q=` (max 100 chars). |
| GET | `/chats/{id}` | `chats.read,read` | Detalle + mensajes ordenados por `sent_at asc`. |
| POST | `/chats/{id}/messages` | `chats.update,update` | Enviar respuesta del operador. |
| POST | `/chats/{id}/mark-read` | `chats.read,read` | Doble chulito azul (gated por setting). |
| GET | `/chats/{id}/client` | `chats.read,read` | Contacto + 50 últimas órdenes. |
| PATCH | `/chats/{id}/bot` | `chats.update,update` | Pausar/reanudar bot. |
| PATCH | `/chats/{id}/contact` | `chats.update,update` | Editar nombre/teléfono/notas del contacto. |
| POST | `/chats/{id}/reassign-branch` | `chats.reassign_branch` o owner | Mover conversación entre sedes. |

### Webhook entrante

| Método | Ruta | Auth | Notas |
|---|---|---|---|
| GET | `/api/v1/webhooks/whatsapp` | Token público | Handshake con Meta (devuelve `hub.challenge`). |
| POST | `/api/v1/webhooks/whatsapp` | HMAC SHA-256 `X-Hub-Signature-256` | Eventos de Meta (mensajes, statuses). |

### Bot externo

| Método | Ruta | Auth |
|---|---|---|
| POST | `/api/external/chats/handoff` | `bot.jwt` |
| POST | `/api/external/chats/messages` | `bot.jwt` |
| GET | `/api/external/chats/messages` | `bot.jwt` |

---

## Cómo entra un mensaje (webhook WhatsApp Cloud API)

`POST /api/v1/webhooks/whatsapp` — sin JWT ni `company.access`. La
autenticidad la garantiza la firma HMAC SHA-256 del header
`X-Hub-Signature-256` calculada con el App Secret de Meta.

Flujo en `WhatsappWebhookController::receive` + `WhatsappInboundMessageHandler`:

1. `WhatsappSignatureValidator` valida el HMAC contra
   `MetaPlatformCredential::activeForCurrentEnvironment()`. Si falla → 403.
2. Se persiste un `WebhookEvent` con el payload crudo (auditoría DIAN
   5/10 años, soft-delete máximo).
3. El handler enruta:
   - `messages` entrantes → resuelve company por `phone_number_id` →
     `chat = Chat::firstOrCreate((company_nit, client_phone))` →
     persiste `ChatMessage(sender='client', meta_message_id=wamid)` →
     `chat.last_message_at = sent_at`.
   - Si el `chat` se acaba de crear: `bot_paused=true` por defecto (n8n
     no operativo), `source='whatsapp'`, `client_name` del profile Meta.
   - `statuses` (delivered/read del operador) → actualiza
     `chat_messages.status` por `meta_message_id`.
4. Respuesta 200 con cuerpo vacío en ≤1s (Meta exige ack rápido o reintenta).

`NormalizeStrings` middleware está bypaseado para la ruta del webhook (ver
`bootstrap/app.php`). Sanitización ocurre en `WhatsappInboundMessageHandler`
antes de persistir.

---

## Lectura/respuesta desde el panel

### Lista (`GET /chats?q=...`)

Implementación en `ChatController::index`. Búsqueda con escape de wildcards
LIKE (`%`, `_`, `!`) usando `ESCAPE '!'`. Validación `SafePlainText(maxBytes: 100)`.

Algoritmo:
- Sin término: top 5 chats por `last_message_at desc`.
- Con término: busca por `client_name`, `client_phone`, `body` de mensajes
  y — si el término contiene dígitos — por `id` de orden (cast a TEXT).
  Sube el tope a 100 resultados.

Respuesta enriquecida en `attachLatestPaidOrders`: batch lookup de la última
orden por phone (sin filtro de pago). La UI muestra el badge de status.

```json
{
  "data": [
    {
      "id": "01HX...",
      "client_phone": "573001112233",
      "client_name": "Laura Restrepo",
      "status": "open",
      "source": "whatsapp",
      "bot_paused": true,
      "last_message_at": "2026-05-06T19:32:00Z",
      "latest_message": { "sender": "client", "body": "¿A qué hora abren?", "sent_at": "..." },
      "latest_order_id": 12345,
      "latest_order_status": "completed",
      "unread_count": 3
    }
  ]
}
```

### Detalle del cliente

`GET /chats/{id}/client` retorna el Contact asociado + 50 últimas órdenes
del phone dentro de la empresa activa. UI: click en el header del chat
abre `ClientDetailModal`. Cada orden del historial es clickeable y abre
`OrderDetailModal`.

### Enviar mensaje (`POST /chats/{id}/messages`)

FormRequest: `StoreChatMessageRequest`. Validación:

```php
'body' => ['required', 'string', 'min:1', 'max:4096'],
```

Lógica:
1. Crea `ChatMessage(sender='operator', body, sent_at=now)`.
2. Actualiza `chat.last_message_at = now`, `bot_paused = true` (intervención
   humana pausa el bot).
3. Si `chat.source === 'whatsapp'`: llama síncrono a
   `WhatsappOutboundMessageSender::deliver`. Si Meta falla (número inválido,
   ventana de 24 h cerrada, etc.), `message.status='failed'` y la UI muestra
   badge "no entregado". El mensaje queda en BD para histórico.
4. Si WhatsApp no está conectado, el mensaje se guarda igual y no se envía.

### Marcar como leído (doble chulito azul)

`POST /chats/{id}/mark-read`. Reglas:

1. Frontend invoca cuando:
   - `selectedChat != null`,
   - `document.visibilityState === 'visible'`,
   - existe `chat_messages` con `sender='client'` y `meta_message_id != null`.
2. Backend valida `company_settings.whatsapp_read_receipts` (default
   `false`). Si está apagado → `{skipped: 'read_receipts_disabled'}`.
3. Toma el último mensaje entrante con `meta_message_id`. Meta trata read
   receipts como acumulativos: marcar el último cubre todos los anteriores.
4. Throttle 5 min vía `Cache::put("chat:{id}:last_read_message_id", ...)`.
5. Despacha `MarkWhatsappMessageReadJob` (async, no bloquea el panel).

El setting `whatsapp_read_receipts` se controla desde `/company/whatsapp` →
bloque "Preferencias" → toggle "Privacidad".

---

## Asignación a staff

El modelo es por **sede**, no por usuario individual. `chat.branch_id`
indica qué sede atiende la conversación. Solo los usuarios con acceso a esa
sede (via `branch_users` pivot) la ven en su bandeja, por el `BranchScope`
natural del modelo.

### Reassign branch (#192)

`POST /chats/{id}/reassign-branch`. Autorización composable:

- Owner (`role.is_system=true`): siempre puede.
- Otros: requieren `chats.reassign_branch` Y tener acceso a la sede destino
  vía `branch_users`. No se puede "tomar" un chat hacia una sede a la que
  el actor no llega.

Validación:
- `branch_id`: required, uuid.
- `reason`: nullable, `SafePlainText(maxBytes: 500)`.
- Sede destino debe existir, pertenecer a la empresa y NO estar archivada.
- Si el chat ya está en la sede solicitada → 200 con mensaje informativo.

Audit: `chat.reassigned` con `from_branch_id`, `to_branch_id`, `reason`.

### Bot externo (handoff)

`POST /api/external/chats/handoff` (bot.jwt): el bot solicita que un humano
tome la conversación. Setea `bot_paused=true`, `handoff_requested_at=now`,
`handoff_reason`. La UI muestra badge "Handoff pedido por el bot".

`PATCH /chats/{id}/bot` con `{paused: false}` reanuda el bot y limpia
`handoff_requested_at` y `handoff_reason`.

---

## Estados de conversación

`chats.status`:

| Estado | Significado | Transiciones |
|---|---|---|
| `open` | Conversación activa. Default al crearse. | → `closed` por operador. |
| `closed` | Cerrada por operador. No purga automática mientras `last_message_at` esté reciente. | → `open` si el cliente vuelve a escribir. |

`chats.bot_paused`:

| Valor | Significado |
|---|---|
| `false` | El bot puede responder automáticamente (cuando n8n esté operativo). |
| `true` | Operador en control o bot pidió handoff. Las respuestas automáticas se suprimen. |

En v1 todos los chats nuevos arrancan con `bot_paused=true` mientras n8n no
esté disponible.

`chat_messages.status` (lado operator):

| Estado | Significado |
|---|---|
| `sent` | Persistido en BD; aún no confirmado por Meta. |
| `delivered` | Meta confirmó entrega al device. |
| `read` | Cliente abrió el mensaje. |
| `failed` | Envío rechazado (número inválido, ventana 24 h cerrada, etc.). |

---

## Componentes frontend

Página principal: `application/frontend/src/pages/chats.tsx`. Hook `useChats`.

Componentes destacados:
- `ChatSourceBadge` (`components/chat-source-badge.tsx`): texto plano sin
  icono, `bg-muted/40` con borde. Aparece en lista y header.
- `chat-message-media.tsx` con `<AudioPlayer>` interno: barra de progreso
  vía `requestAnimationFrame` (~60 fps), thumb circular, click-to-seek,
  mute/unmute, contador `MM:SS / MM:SS`.
- `ClientDetailModal` y `OrderDetailModal` reutilizados.
- Polling 5 s siempre activo (operación crítica — los clientes esperan
  respuesta rápida).

---

## Eventos de auditoría

Emitidos por `ChatController` vía `AuditService::log`:

| Acción | Data mínimo |
|---|---|
| `chat.reassigned` | `from_branch_id`, `to_branch_id`, `reason`. |
| `chat.bot_toggled` | `bot_paused`, `previous_state`. |
| `chat.contact_updated` | `contact_id`, `changed_fields`. |
| `whatsapp.message_sent` | `chat_id`, `meta_message_id`, `status`. |
| `whatsapp.message_failed` | `chat_id`, `error_code`, `error_title`. |

`AuditService::log` agrega `branch_id` y `actor_active_branch_id` del
request. Mensajes entrantes del webhook NO emiten audit (alta cardinalidad);
se mantienen en `chat_messages` como fuente. Ver
`application/constants/AUDIT_EVENTS.md`.

---

## Purga automática

Schedule en `routes/console.php`:

```php
Schedule::command('chats:purge-old')->dailyAt('03:00')->onOneServer();
```

Comando `App\Console\Commands\PurgeOldChats`:

- Borra `chats` con `last_message_at < now() - 60 días` (o NULL).
- Cascade a `chat_messages` por FK.
- `contacts` y `orders` se preservan (FK sin cascade) — el cliente no se
  pierde, solo el historial de mensajes.

`->onOneServer()` exige cache store compartido. El proyecto usa
`CACHE_STORE=database` sobre Postgres (stack canónico). Cumple
CLAUDE.md §12 (N-instance safe).

---

## Edge cases y empty states

- **WhatsApp no conectado**: `storeMessage` persiste el mensaje en BD pero
  no llama a Meta. La UI muestra badge "no entregado".
- **Ventana de 24 h cerrada**: Meta rechaza con `131047`. El handler marca
  `status='failed'` y la UI exige plantilla pre-aprobada (no implementada
  en v1).
- **Mensaje sin `meta_message_id`**: `mark-read` responde
  `{skipped: 'no_inbound_messages'}` y no toca Meta.
- **Setting `whatsapp_read_receipts=false`**: cualquier `mark-read` responde
  `{skipped: 'read_receipts_disabled'}` (privacy-by-default).
- **Throttle de mark-read**: segunda llamada con el mismo wamid en <5 min →
  `{skipped: 'already_marked'}`.
- **Lista vacía**: `EmptyState` con icono y texto "Sin conversaciones aún".
- **Búsqueda sin resultados**: misma `EmptyState` con mensaje contextual.
- **Reassign a la misma sede**: 200 con mensaje "El chat ya pertenece a la
  sede solicitada", sin audit.
- **Reassign sin permiso o sin acceso a sede destino**: 403 con
  `code: CHAT_REASSIGN_FORBIDDEN` o `BRANCH_NOT_ACCESSIBLE`.
- **Webhook con firma inválida**: 403; el evento NO se persiste.
- **Webhook duplicado** (Meta reintenta): idempotencia por `meta_message_id`
  unique en `chat_messages` — el segundo insert lanza
  `UniqueConstraintViolation` y el handler lo captura silenciosamente.
- **Conversación reactivada tras purga**: si el cliente vuelve a escribir
  después de 60 días, el chat se recrea desde cero (sin historial previo).

---

## Cross-references

- Constants: `application/constants/PERMISSIONS_CATALOG.md`,
  `BRANCH_RBAC.md`, `AUDIT_EVENTS.md`, `MIDDLEWARE_MAP.md`,
  `FEATURES_INDEX.md`.
- Backend: `app/Http/Controllers/Api/ChatController.php`,
  `WhatsappWebhookController.php`, `WhatsappAccountController.php`,
  `ExternalChatHandoffController.php`, `ExternalChatMessageController.php`,
  `app/Services/Whatsapp/WhatsappOutboundMessageSender.php`,
  `WhatsappInboundMessageHandler.php`,
  `WhatsappSignatureValidator.php`,
  `app/Jobs/MarkWhatsappMessageReadJob.php`,
  `app/Models/Chat.php`, `ChatMessage.php`, `MetaPlatformCredential.php`,
  `WebhookEvent.php`,
  `app/Console/Commands/PurgeOldChats.php`.
- Frontend: `src/pages/chats.tsx`, `hooks/use-chats.ts`,
  `components/chat-source-badge.tsx`, `components/chat-message-media.tsx`,
  `components/client-detail-modal.tsx`.
- Routes schedule: `routes/console.php` → `chats:purge-old`.
- Relacionados: `CRM-Clientes.md`, `Fidelizacion-Puntos.md`.
