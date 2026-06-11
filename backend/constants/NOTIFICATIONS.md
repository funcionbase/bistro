# Catálogo de notificaciones push (#149)

Este `.md` documenta los **tipos** de notificación Web Push que flexyflow
puede enviar, su disparador, destinatario y payload canónico. Es la
contraparte de [`AUDIT_EVENTS.md`](./AUDIT_EVENTS.md) (que documenta los
`action` slugs auditados) y de [`PERMISSIONS_CATALOG.md`](./PERMISSIONS_CATALOG.md)
(que documenta los slugs RBAC asignables).

> **Regla de drift**: si el código emite un tipo nuevo o cambia
> destinatarios, actualizar esta tabla en el mismo PR. Si esta tabla y
> el código difieren → **el código gana** (CLAUDE.md §7).

---

## Tipos canónicos

| `data.type` | Disparador | Destinatario (regla) | Permiso requerido | URL destino | Tag de colapso | Tries |
|---|---|---|---|---|---|---|
| `pending_approval` | Listener `NotifyPendingApprovalListener` del evento `OrderItemSubmittedForApproval` (cuando `OrderItem.status = pending_approval`). | Users con membership activa en la empresa de la orden + `orders.update` en la sede correspondiente. Owner (`is_system=true`) bypassea. | `orders.update` (`can_update=true`) | `/orders?focus=pending&order={id}` | `pending-approval-{order_id}` (config: `notifications.dispatch.pending_approval_payload_tag_prefix`) | 3 |
| `pending_approval_reminder` | Cron `notifications:remind-pending-approvals` (every minute, `onOneServer`) cuando `OrderItem.submitted_at > now() - 5min`. | Idéntico a `pending_approval`. Reusa el MISMO `tag` para que el OS reemplace la notif previa en lugar de apilar. | `orders.update` | `/orders?focus=pending&order={id}` | `pending-approval-{order_id}` | 2 |
| `inventory_digest` | `AuthController::selectCompany` al primer login del día (cache `push.inventory.sent.{userId}.{date}` no existe + hay `alert_events` del día). | Sólo el user que hizo login. Una vez por día, idempotente vía `Cache::add` atomic. | `reports.read` o `inventory.read` | `/dashboard?focus=alerts` | `inventory-digest-{YYYY-MM-DD}` | 2 |

---

## Convención de payload

Todos los push siguen el mismo shape de payload JSON (deserializado por
el SW en `resources/js/sw.ts`):

```typescript
interface PushPayload {
    title: string;        // ~50 chars máx para visibilidad en lock screen
    body: string;         // ~120 chars máx; truncado por el OS
    url?: string;         // ruta interna al hacer click (`notificationclick`)
    tag?: string;         // colapsa duplicados a nivel OS
    icon?: string;        // default '/icons/icon-192.png'
    badge?: string;       // default '/icons/icon-96-monochrome.png' (Android)
    data?: Record<string, unknown>;  // payload arbitrario para clientes;
                                     // SIEMPRE incluir `type` ∈ tabla
}
```

**Convenciones**:

- `title`: oración nominal en es-CO neutro (sin diminutivos, sin
  modismos paisas). Ej: `"Mesa 4 espera aprobación"`, NO
  `"Mesa 4 espera aprobacion mijo"`.
- `body`: oración corta accionable. Incluir números concretos
  (`"3 platos pendientes de aprobar"`, no `"Algunos platos pendientes"`).
- `tag`: prefix por tipo + identificador estable del target (order_id,
  fecha ISO, etc.). NUNCA timestamp único — eso rompe el colapso.
- `data.type` es **obligatorio** y debe ser un valor de la tabla anterior.
  El cliente puede usarlo para analytics o lógica condicional al click.

---

## RBAC — gobierno de los push

- **No existe un permiso "enviar push a otros"**. El sistema decide a
  quién enviar basándose en los permisos OPERATIVOS existentes
  (`orders.update`, `reports.read`, etc.). Razón: evita la explosión
  combinatoria de "ver pedidos × recibir push de pedidos × ..." y
  alinea con la regla de mínima sorpresa (si podés actuar sobre la
  cosa, te avisamos cuando algo cambie).
- Los slugs `notifications.{read,create,update,delete}` (feature
  `notifications`) son **self-service universal** — todos los `role_type`
  los tienen en `[true,true,true,true]`. Gobiernan UNICAMENTE las propias
  suscripciones del user (CRUD de los devices de `/settings/notifications`).
- Owner (`role.is_system=true`) bypassea check de permiso operativo. Si
  el owner está suscrito y la sede es la suya, recibe TODOS los push.

---

## Service Worker — listeners

`resources/js/sw.ts` registra 3 listeners:

| Listener | Responsabilidad |
|---|---|
| `push` | Deserializa el payload JSON. Llama `showNotification(title, options)`. Si el payload no es JSON, muestra texto plano. |
| `notificationclick` | Cierra la notif. Busca un cliente abierto con `clients.matchAll`. Si lo encuentra, le hace `focus()` + `navigate(url)`. Si no, abre nueva ventana con `clients.openWindow(url)`. |
| `pushsubscriptionchange` | El browser rotó el endpoint. Despierta el primer cliente activo vía `postMessage({type: 'pwa:push:resubscribe'})` para que pida una nueva sub al backend. |

El SW se compila con `injectManifest` (vite-plugin-pwa). `generateSW` no
permite agregar listeners custom cleanly.

---

## N-instance safety (CLAUDE.md §12)

Todos los disparadores son N-instance safe en ASG con N EC2:

| Componente | Mecanismo |
|---|---|
| `NotifyPendingApprovalListener` | `implements ShouldQueue` → corre en worker, no en request HTTP. Driver `database` (postgres) — el proyecto NO usa Redis/SQS. Workers EC2 toman cada job vía `SELECT ... FOR UPDATE SKIP LOCKED`. |
| `SendPendingApprovalPushJob` | Idem. Tag de notif colapsa duplicados a nivel OS si por error 2 workers tomaran el mismo job. |
| `RemindPendingApprovalsCommand` | Cron con `->onOneServer()->withoutOverlapping(5)`. Triple defensa con `Cache::lock(push.reminder.order_item.{id}, throttle*60)` per-item. Requiere `CACHE_STORE=database` (tablas `cache` + `cache_locks` en postgres). |
| `SendInventoryDigestPushJob` | Disparado desde `AuthController::selectCompany` con `Cache::add(push.inventory.sent.{userId}.{date}, 1, ttl)` atomic. Dos logins simultáneos: sólo uno gana (atomicidad de `INSERT` postgres). |

---

## Browser support matrix (Mayo 2026)

| Browser / Plataforma | Web Push | PWA required | Notas |
|---|---|---|---|
| Chrome desktop | ✅ | No | Full support. |
| Chrome Android | ✅ | No | Full support. |
| Edge desktop | ✅ | No | Full support. |
| Firefox desktop+Android | ✅ | No | Full support. |
| Safari macOS 16.4+ | ✅ | No | Full support. |
| Safari iOS 16.4+ | ✅ | **Sí** | Requiere "Añadir a inicio". |
| Safari iOS <16.4 | ❌ | — | El hook `usePushSubscription` lo detecta y oculta la UI. |

---

## VAPID keys

- Curva P-256, base64url. ~88 chars (pública) / ~43 chars (privada).
- Generadas con `php artisan push:generate-vapid-keys` (fallback CLI
  `openssl` para Windows).
- En PDN/QA viven en GitHub Environment Variables (`VAPID_PUBLIC_KEY`,
  `VAPID_PRIVATE_KEY`) y se inyectan al `.env` vía
  `sync-env-secret.yml`. Por decisión explícita del owner se almacenan
  como **Variables** (no Secrets), porque la pública NO es secreta y la
  privada se trata como configuración rotable.
- Rotar invalida TODAS las subs existentes — el SW las re-suscribe
  automáticamente via el listener `pushsubscriptionchange`.

---

## Histórico / deprecaciones

- _(vacío — primer release en #149)_

---

## Referencias cruzadas

- `application/app/Services/WebPushDispatcher.php` — envío + soft-revoke 410.
- `application/app/Jobs/Send{PendingApproval,PendingApprovalReminder,InventoryDigest}PushJob.php`.
- `application/app/Listeners/NotifyPendingApprovalListener.php`.
- `application/app/Console/Commands/RemindPendingApprovalsCommand.php`.
- `application/config/notifications.php` — tuning (cooldown, throttle, kill-switch).
- `application/resources/js/sw.ts` — Service Worker custom.
- `application/resources/js/hooks/use-push-subscription.ts` — hook React.
- `application/constants/PERMISSIONS_CATALOG.md` — slugs `notifications.*`.
- `application/constants/AUDIT_EVENTS.md` — `notifications.{subscribed,revoked,pushed}`.
- `docs/wiki/PWA-Push-Notifications.md` — guía de setup + browser matrix.
