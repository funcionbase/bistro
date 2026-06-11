# PWA · Web Push Notifications (#149)

Guía operativa para entender, configurar y testear el sistema de
notificaciones push de flexyflow Restaurante.

**Catálogo canónico de tipos / payloads / permisos**:
[`application/constants/NOTIFICATIONS.md`](../../application/constants/NOTIFICATIONS.md).

---

## ¿Qué resuelve?

El frontend tenía banners y feeds in-app (`PendingApprovalsBanner`,
`useAlerts`), pero **solo funcionaban con la pestaña visible**. Si el
cajero dejaba la tablet en standby o el owner estaba en WhatsApp, no se
enteraba de:

1. Mesas con pedidos esperando aprobación durante minutos.
2. Alertas de inventario detectadas en el cron diario de 5am.

Web Push entrega notificaciones a nivel **sistema operativo**, incluso
con la app cerrada (siempre que la PWA esté instalada).

---

## Arquitectura

```
[Comensal QR]
   │ POST /api/v1/t/orders/items
   ▼
[TableOrderService::addItem]
   │ DB::transaction
   │   - OrderItem.status = 'pending_approval'
   │   - submitted_at = now()
   │   - event(new OrderItemSubmittedForApproval($item))
   ▼
[Event Bus]
   │
   ▼
[NotifyPendingApprovalListener (ShouldQueue)]
   │ encola en queue 'notifications'
   ▼
[Worker EC2]
   │ SendPendingApprovalPushJob::dispatch(orderItemId)
   ▼
[SendPendingApprovalPushJob]
   │ 1. Trae OrderItem + Order.
   │ 2. Lista PushSubscription active de la empresa.
   │ 3. Filtra por WebPushDispatcher::userCanReceiveOrderUpdate().
   │ 4. Por cada sub válida → WebPushDispatcher::send(sub, payload).
   ▼
[WebPushDispatcher → minishlink/web-push]
   │ Cifra payload con VAPID + sub.p256dh/auth
   │ POST a endpoint del navegador (FCM/Mozilla/Apple)
   │
   │  ┌── 200/201 → sub.last_seen_at = now()
   │  ├── 410 Gone / 404 → sub.revoked_at = now()
   │  └── 5xx/red → log Sentry; cron seguirá intentando en próximo tick
   ▼
[Browser SW (bistro/frontend/src/sw.ts)]
   │ Listener 'push' → showNotification(title, options)
   │ Listener 'notificationclick' → openWindow(url) o focus existente
   ▼
[Sistema Operativo]
   │ Muestra banner / lock screen
   ▼
[Usuario hace click] → URL definida en payload
```

### Recordatorios escalados

```
[Cron every minute] notifications:remind-pending-approvals
   │ onOneServer() + withoutOverlapping(5)
   ▼
[RemindPendingApprovalsCommand]
   │ Find OrderItem status='pending_approval' AND submitted_at < now-5min
   │ Por cada item:
   │   - Cache::lock("push.reminder.order_item.{id}", 300) — gana sólo 1 instancia
   │   - SendPendingApprovalReminderPushJob::dispatch(id)
   ▼
[SendPendingApprovalReminderPushJob]
   │ Mismo flujo que el job inicial, pero:
   │   - title incluye minutos transcurridos
   │   - tag IDÉNTICO al push inicial → OS reemplaza la notif, no apila
```

### Digest de inventario (one-shot por día)

```
[AuthController::selectCompany]
   │ User selecciona empresa activa
   │ Cache::add("push.inventory.sent.{userId}.{date}", 1, ttl_until_midnight)
   │   ↑ atomic — si retorna true, dispatcha; si false, alguien ya dispatchó
   ▼
[SendInventoryDigestPushJob]
   │ - Trae alert_events del día sin dismiss para esa empresa.
   │ - Filtra por user.permission ∈ {reports.read, inventory.read}.
   │ - Envía 1 push con cuenta agregada y url=/dashboard?focus=alerts.
```

---

## Setup local

1. **Generar VAPID keys**:

   ```bash
   cd application
   php artisan push:generate-vapid-keys
   ```

   Pega las 2 líneas en `.env`. El comando tiene fallback automático a
   OpenSSL CLI cuando PHP nativo de Windows no puede crear EC keys.

2. **Verificar config**:

   ```bash
   php artisan config:show notifications.web_push
   ```

   Debe mostrar `vapid_public_key`, `vapid_private_key`, `vapid_subject`.

3. **Servidor + queue worker**:

   ```bash
   composer run dev      # arranca php + queue:listen + vite
   ```

4. **Instalar la PWA** desde Chrome → menu → "Instalar Flexyflow". En
   iOS desde Safari → Compartir → Añadir a inicio.

5. **Habilitar push**: ir a `/settings/notifications` → "Activar
   notificaciones". El navegador pide permiso; al aceptar, se crea fila
   en `push_subscriptions`.

6. **Test manual del flujo pending_approval**:

   ```bash
   # Como comensal QR: agregar un item a la mesa
   php artisan tinker --execute '
   $session = App\Models\TableSession::first();
   $guest = $session->guests->first();
   app(App\Services\TableOrderService::class)->addItem(
     $guest, "menu-item-id", 1, null, request()
   );
   '
   ```

   El listener encola el job; el worker debería loguear el envío.

---

## Setup QA / PDN

### GitHub Environment Variables

Variables (no secrets, decisión owner #149):
- `VAPID_PUBLIC_KEY`
- `VAPID_PRIVATE_KEY`
- `VAPID_SUBJECT` (opcional; default `mailto:info@flexyflow.co`)
- `PUSH_INVENTORY_DIGEST_ENABLED` (kill-switch global del digest de
  inventario al login; default `true`). Se consume desde
  `config/notifications.php` → `inventory_digest.enabled`.

En PDN ambas claves VAPID viven en SSM Parameter Store, no en el `.env`
directamente. El UserData del ASG las lee al boot.

El workflow [`sync-env-secret.yml`](../../.github/workflows/sync-env-secret.yml)
las pisa en el `.env` durante deploy. Cada entorno tiene su propio par
(qa ≠ pdn); rotar invalida las subs del entorno.

### Rotación de VAPID

1. `php artisan push:generate-vapid-keys` localmente.
2. Actualizar variable en GH Environments (qa / pdn).
3. Disparar workflow `Sync GH Environment → AWS Secrets Manager`.
4. Refrescar ASG (next launch toma el nuevo .env).
5. Los browsers de los users existentes dispararán
   `pushsubscriptionchange` al próximo intento de push fallido y
   re-suscriben automáticamente vía el listener del SW. UX impact: 1
   notif perdida por device durante la transición.

### Requisitos N-instance (CLAUDE.md §12)

Stack canónico del proyecto: TODO sobre PostgreSQL. NO se usa Redis, SQS
ni DynamoDB.

- `QUEUE_CONNECTION=database` (tablas `jobs` + `failed_jobs` en postgres).
  Los workers EC2 coordinan vía `SELECT ... FOR UPDATE SKIP LOCKED` — un
  solo worker toma cada job. NO `sync` (inline, bloquea request).
- `CACHE_STORE=database` (tablas `cache` + `cache_locks` en postgres). El
  cron y `AuthController::selectCompany` usan `Cache::lock` y `Cache::add`
  que requieren coordinación cross-instance; postgres da atomicidad.
- Cron `notifications:remind-pending-approvals` ya viene con
  `->onOneServer()->withoutOverlapping(5)` (`routes/console.php`).

---

## Testing

### Manual (DevTools)

1. Chrome DevTools → Application → Service Workers → asegurarse que
   `sw.js` está "activated and running" tras `npm run build`.
2. Application → Push → "Subscribe to test" si Chrome ofrece la opción,
   o usar el bookmarklet de Workbox.
3. Enviar payload de prueba:

   ```bash
   php artisan tinker --execute '
   $sub = App\Models\PushSubscription::active()->first();
   app(App\Services\WebPushDispatcher::class)->send($sub, [
       "title" => "Test",
       "body" => "Payload manual",
       "tag" => "test-payload",
       "url" => "/dashboard"
   ]);
   '
   ```

### Casos a validar (manual QA)

1. **Subscribe flow**: instalar PWA → /settings/notifications → activar
   → fila aparece en `push_subscriptions`.
2. **Pending push real**: agregar item via mesa QR → un mesero suscrito
   con `orders.update` activo recibe push en su tablet (cerrada).
3. **Reminder**: dejar item pending 6+ minutos → recibir reminder con
   título actualizado.
4. **OS dedup**: dos pestañas + un nuevo pending → una sola notif a
   nivel OS gracias al `tag`.
5. **410 cleanup**: revocar permission desde DevTools →
   `push_subscriptions.revoked_at` se setea automáticamente en el
   próximo push fallido.
6. **Permission negativo**: usuario sin `orders.update` no recibe push
   aunque tenga sub activa.
7. **Inventory one-shot**: login + logout + login mismo día → un solo
   push de inventario.
8. **Browser sin soporte**: Safari iOS <16.4 → UI dice "Tu navegador no
   soporta".

---

## Troubleshooting

| Síntoma | Causa probable | Fix |
|---|---|---|
| `push:generate-vapid-keys` falla con "Unable to create the key" | PHP Windows sin `openssl.cnf` | El comando auto-fallbackea a OpenSSL CLI. Asegurate de tener `openssl` en PATH (Git Bash lo trae). |
| Push se envía pero el browser no muestra notif | Permission revocado / OS notifications off | Verificar `Notification.permission` en consola del browser. Verificar OS settings. |
| `push_subscriptions.revoked_at` se llena solo | El endpoint del browser está expirando con 410 | Normal post rotación VAPID o cuando el user desinstaló la PWA. El SW debería re-suscribirse en el próximo `pushsubscriptionchange`. |
| Recordatorios duplicados | Cache store no compartido entre EC2 (file/array) | Cambiar a `CACHE_STORE=database` (postgres). |
| Push síncrono lento bloquea HTTP | Queue es `sync` | Cambiar a `QUEUE_CONNECTION=database` (postgres). |
| iOS no recibe push | PWA no instalada / iOS <16.4 | Instalar PWA desde Safari → Compartir. Verificar versión iOS. |

---

## Referencias

- [`application/constants/NOTIFICATIONS.md`](../../application/constants/NOTIFICATIONS.md) — catálogo canónico de tipos.
- [`application/constants/PERMISSIONS_CATALOG.md`](../../application/constants/PERMISSIONS_CATALOG.md) — slugs `notifications.*`.
- [`application/constants/AUDIT_EVENTS.md`](../../application/constants/AUDIT_EVENTS.md) — `notifications.{subscribed,revoked,pushed}`.
- [Web Push standard (RFC 8030)](https://datatracker.ietf.org/doc/html/rfc8030).
- [minishlink/web-push](https://github.com/web-push-libs/web-push-php) — librería PHP.
- [Workbox docs — push](https://developer.chrome.com/docs/workbox/handling-service-worker-updates/).
