# AUDIT_EVENTS — Fuente única de verdad

> **Antes de añadir/modificar acciones auditadas, lee este archivo.**
> **Después de modificar, actualiza este archivo + el llamado a
> `AuditService::log` en el mismo PR.**

## Archivos que deben quedar sincronizados

- [ ] `bistro/backend/app/Services/AuditService.php` — `log()` agrega `branch_id` + `actor_active_branch_id` auto
- [ ] `bistro/backend/app/Models/AuditLog.php` — modelo de la tabla
- [ ] Migración `audit_logs` (columnas: `user_id`, `action`, `auditable_type/id`, `data` jsonb, `ip_address`, `user_agent`)
- [ ] Controllers y services que emiten `AuditService::log`
- [ ] `bistro/backend/constants/ACCOUNTING_RULES.md` — toda mutación financiera audita
- [ ] `bistro/backend/constants/BRANCH_RBAC.md` — `actor_active_branch_id` + `branch_id` cross-sede

---

## Modelo del AuditLog

```
audit_logs
├── id              bigint PK
├── user_id         bigint NULLABLE FK users.id (actor — null en system actions)
├── action          varchar(255)       (slug canónico: orders.close_with_payment, employees.view_salary, ...)
├── auditable_type  varchar NULLABLE   (class FQN del modelo afectado)
├── auditable_id    bigint NULLABLE    (PK del modelo afectado)
├── data            jsonb NULLABLE     (metadata reconstructible — montos, refs, motivos)
├── ip_address      varchar
├── user_agent      varchar
├── created_at      timestamp
```

`AuditService::log(...)` agrega automáticamente al `data`:

- `branch_id` = sede del recurso (si `$auditable` la tiene como atributo).
- `actor_active_branch_id` = sede que el actor tenía activa al ejecutar.

Fuente: `bistro/backend/app/Services/AuditService.php:24-58`.

---

## Convenciones del campo `action`

- Slug en formato `<dominio>.<acción>` (parecido a permisos RBAC, pero NO debe coincidir 1:1 — `action` es la acción ejecutada, no el permiso requerido).
- Verbos completos: `close_with_payment`, `refund`, `cancel`, `view_salary`, `reassign_branch`, `disconnect`, `swap_phone`.
- Una acción crítica = un `action` slug = una fila por evento.

## Convenciones del campo `data`

- Contiene metadata reconstructible para reproducir el contexto sin tocar otras tablas.
- Mínimo recomendado:
  - `before` / `after` (snapshots de campos relevantes).
  - `actor_id` (si difiere de `user_id`, ej. impersonation).
  - Montos involucrados, referencias, motivos.
- NO meter dump del modelo entero — solo lo necesario para auditoría/cumplimiento.

---

## Catálogo de acciones (estado actual)

Tabla mantenida append-only. Cada vez que se agregue un evento auditado nuevo, sumarlo aquí.

### Órdenes (cobros, refunds, cancelaciones)

| `action` | Disparado por | `data` mínimo | Permiso relacionado |
|---|---|---|---|
| `order.closed_with_payment` | `OrderController::closeWithPayment` | `order_id`, `method`, `amount`, `tip_amount`, `reference`, `change_returned` | `orders.update` |
| `order.refunded` | `OrderController::refund` | `order_id`, `original_method`, `total_refunded`, `is_partial`, `remaining_refundable`, `reference`, `reason` | `orders.update` |
| `order.cancelled` | `OrderController::cancel` | `order_id`, `reason` | `orders.update` |
| `order.append_items` | `OrderController::appendItems` | `items_added`, `delta_total` | `orders.update` |
| `order.status_changed` | `OrderController::updateStatus` | `order_id`, `from`, `to`, `inventory_consumed` | `orders.update` |
| `order.sms_sent` | `SendOrderStatusSmsJob::handle` (#275) | `order_id`, `to_status`, `phone` (enmascarado), `provider`, `provider_message_id`, `segments` | Efecto colateral de `orders.update` — sin permiso propio. Sin actor (system action — job de cola). |
| `order.sms_failed` | `SendOrderStatusSmsJob::handle` (#275) | `order_id`, `to_status`, `phone` (enmascarado), `provider`, `error` | Efecto colateral de `orders.update` — sin permiso propio. Sin actor (system action — job de cola). |
| `order.created_by_customer` | `Public\BranchOrderController::store` (pedido sin mesa por QR de sede) | `company_nit`, `branch_id`, `order_type` (pickup/delivery), `client_phone`, `items_count`, `delivery_fee`, `total` | Público sin JWT — sin actor. |
| `order.approved` | `OrderController::approve` (pedidos públicos sin mesa) | `order_id`, `order_type`, `total` | `orders.update` |

### Cocina (KDS #115)

| `action` | Disparado por | `data` mínimo | Permiso relacionado |
|---|---|---|---|
| `kds.item.in_kitchen` | `KdsTicketService::markInKitchen` | `order_id`, `from`, `to` | `kds.update` |
| `kds.item.ready` | `KdsTicketService::markReady` | `order_id`, `from`, `to` | `kds.update` |
| `kds.item.served` | `KdsTicketService::markServed` | `order_id`, `from`, `to` | `kds.update` |
| `kds.station_ready` | `KdsTicketService::maybeMarkStationReady` | `order_id`, `station_id`, `station_slug`, `items_ready` | `kds.update` (efecto colateral de `markReady`) |
| `kds.station.created` | `KdsStationController::store` | `station_id`, `slug`, `name` | `kds_stations.*` |
| `kds.station.updated` | `KdsStationController::update` | `station_id`, `before`, `after` | `kds_stations.*` |
| `kds.station.archived` | `KdsStationController::archive` | `station_id`, `slug` | `kds_stations.*` |
| `kds.device_token.generated` | `KdsDeviceTokenService::generate` | `station_id`, `station_slug`, `label` | `kds_stations.*` |
| `kds.device_token.revoked` | `KdsDeviceTokenService::revoke` | `station_id`, `label` | `kds_stations.*` |

### Caja y cierre operativo

| `action` | Disparado por | `data` mínimo | Permiso relacionado |
|---|---|---|---|
| `cash_register.open` | `CashRegisterController::open` | `opening_amount`, `branch_id` | `orders.update` (operativo) |
| `cash_register.close` | `CashRegisterController::close` | `closing_amount`, `expected`, `diff` | `orders.update` |
| `cash.expense.recorded` | `CashRegisterController::storeExpense` | `amount`, `category`, `payment_method`, `cash_session_id` | `orders.update` |
| `cash.income.recorded` | `CashRegisterController::storeIncome` | `amount`, `category`, `payment_method`, `cash_session_id` | `orders.update` |
| `cash_register.bypass_switch_lock` | `CashRegisterController::switchBranch` (con bypass) | `session_id`, `from_branch_id`, `to_branch_id` | `cash_register.bypass_switch_lock` |

### Multi-sede sensibles

| `action` | Disparado por | `data` mínimo | Permiso relacionado |
|---|---|---|---|
| `chat.reassigned` | `ChatController::reassignBranch` | `from_branch_id`, `to_branch_id`, `reason` | `chats.reassign_branch` |
| `chat.viewed` | `ChatController::show` | `chat_id`, `company_nit` | `chats.read` (dedupe 30 min) |
| `chat.client.viewed` | `ChatController::clientDetail` | `chat_id`, `contact_id` | `chats.read` (dedupe 30 min) |
| `chat.media.viewed` | `ChatController::mediaUrl` | `chat_id`, `message_id`, `media_type` | `chats.read` (dedupe 30 min por mensaje) |
| `chat.message.sent` | `ChatController::storeMessage` / `storeAttachment` | `chat_message_id`, `body_length`, `status` | `chats.update` |
| `chat.bot.toggled` | `ChatController::updateBot` | `from_paused`, `to_paused` | `chats.update` |
| `chat.contact.updated` | `ChatController::updateContact` | `before`, `after` (única excepción PII) | `chats.update` |
| `chat.menu_link.sent` | `ChatController::menuLink` | `chat_id`, `cart_session_id`, `branch_id` | `chats.update` (throttle 20/min) |
| `chat.access.denied` | `ChatController::findChatOrDeny` + `ChatAuditController` | `chat_id`, `attempted_company_nit`, `route` | — (dedupe 5 min) |
| `chat.history.read_by_bot` | `ExternalChatMessageController::index` | `chat_id`, `messages_returned`, `user_id=null` | `bot.jwt` (dedupe 15 min) |
| `chat.message.sent_by_bot` | `ExternalChatMessageController::store` | `chat_message_id`, `body_length`, `user_id=null` | `bot.jwt` |
| `whatsapp.channel.connected` | `EvolutionChannelService::provision` | `channel_id`, `branch_id`, `instance` | `whatsapp.connect` |
| `whatsapp.channel.qr_viewed` | `EvolutionChannelService::qr` | `channel_id` | `whatsapp.connect` (dedupe 5 min) |
| `whatsapp.channel.disconnected` | `EvolutionChannelService::disconnect` | `channel_id`, `branch_id` | `whatsapp.disconnect` |

> **El slug real de la reasignación es `chat.reassigned`**, no `chat.reassign_branch`: este documento
> lo listaba mal desde #192 y el plan de WhatsApp proponía un tercer nombre (`chat.branch.reassigned`).
> Gana el código — renombrarlo huerfanaría las filas históricas.
>
> **PII (regla dura del módulo de chats)**: `data` guarda SOLO identificadores. Nunca `client_phone`
> ni el cuerpo del mensaje — de ahí `body_length` en vez de `body`. `ChatAuditLogger` filtra las
> claves prohibidas aunque el llamador las pase. Única excepción: el `before`/`after` de
> `chat.contact.updated`, que ES el cambio auditado, marcado explícitamente con `_pii_exempt`.
| `inventory.transfer_cross_branch` | Endpoint dedicado de transferencia | `from_branch_id`, `to_branch_id`, `items[]`, `total_value` | `inventory.transfer_cross_branch` |
| `kds.station.*` y `kds.device_token.*` | `KdsStationController` / `KdsDeviceTokenService` | ver sección "Cocina (KDS)" arriba | `kds_stations.*` |
| `branch.business_type_changed` (#237) | `BranchController::changeBusinessType` | `branch_id`, `before` (slug del vertical previo), `after` (slug nuevo) | `branches.manage,update` |

### CRM (clientes)

| `action` | Disparado por | `data` mínimo | Permiso relacionado |
|---|---|---|---|
| `client.created` | `ClientController::store` | `contact_id`, `kind`, `doc_type`, `doc_number`, `client_phone`, `client_name`, `branch_id` | `clients.create` |
| `client.updated` | `ClientController::update` | `contact_id`, `kind`, `doc_type`, `doc_number`, `client_phone`, `client_name` | `clients.update` |
| `client.merged` | `ClientController::merge` | `contact_id` (principal), `merged_contacts[]` (snapshot id/name/doc/phone/email), `moved` (conteos por tabla) | `clients.delete` |
| `client.note_created` / `client.note_deleted` | `ClientController::storeNote` / `destroyNote` | `contact_id`, `note_id` (+ `note_excerpt` al borrar) | `clients.update` / `clients.delete` |
| `client.tag_added` / `client.tag_removed` | `ClientController::storeTag` / `destroyTag` | `contact_id`, `tag` | `clients.update` / `clients.delete` |

### WhatsApp

| `action` | Disparado por | `data` mínimo | Permiso relacionado |
|---|---|---|---|
| `whatsapp.connect` | Embedded Signup callback | `phone_number_id`, `waba_id` | `whatsapp.connect` |
| `whatsapp.update` | Display Name change / webhook subscribe | `before`, `after` | `whatsapp.update` |
| `whatsapp.swap_phone` | Cambio de número | `from_phone_number_id`, `to_phone_number_id` | `whatsapp.swap_phone` (owner) |
| `whatsapp.disconnect` | Liberar el número en Meta | `phone_number_id`, `waba_id` | `whatsapp.disconnect` (owner) |

### Compras

| `action` | Disparado por | `data` mínimo | Permiso relacionado |
|---|---|---|---|
| `purchase.receive` | `PurchaseController::receive` | `purchase_id`, `inventory_movements[]` | `purchases.receive` |
| `purchase.pay` | `PurchaseController::pay` | `amount`, `payment_method`, `reference` | `purchases.pay` |
| `purchase.cancel` | `PurchaseController::cancel` | `reason`, `nota_credito_id` | `purchases.delete` |

### Colaboradores

| `action` | Disparado por | `data` mínimo | Permiso relacionado |
|---|---|---|---|
| `employees.view_salary` | `EmployeeController::showSalary` | `employee_id` (objetivo de la consulta) | `employees.view_salary` |
| `employees.archive` | `EmployeeController::destroy` (soft) | `employee_id`, `reason` | `employees.delete` |

### Identidad y roles

| `action` | Disparado por | `data` mínimo | Permiso relacionado |
|---|---|---|---|
| `user.role.update` | `UserRoleController::update` | `user_id`, `from_role_id`, `to_role_id` | `roles.update` + `users.update` |
| `role.permissions.update` | `UserPermissionsEditor` save (admin/owner) | `role_id`, `changes[]` (slug → can_*) | `roles.update` |
| `branch_user.add` | Asignar usuario a sede | `branch_id`, `user_id` | `branches.assign_users` |
| `branch_user.remove` | Revocar usuario de sede | `branch_id`, `user_id` | `branches.assign_users` |

### Documentos legales

Los documentos legales viven en el wiki externo (ver `config/legal.php`).
Las aceptaciones se registran como parte de `user.enrolled` y `company.created`
(con `accepted_documents` y `documents_source: external_wiki` en `data`) —
no hay action dedicado.

### Notificaciones push (#149)

| `action` | Disparador | `data` mínimo | Notas |
|---|---|---|---|
| `notifications.subscribed` | `PushSubscriptionController::store` | `subscription_id`, `user_agent` | Tras upsert exitoso; el `auditable` es `PushSubscription`. |
| `notifications.revoked` | `PushSubscriptionController::destroy` o `WebPushDispatcher::send` con 410 Gone | `subscription_id`, `reason` (`'user_revoke'` o `'endpoint_410'`) | Soft-revoke vía `revoked_at`. Append-only. |
| `notifications.pushed` | `Send{PendingApproval,PendingApprovalReminder,InventoryDigest}PushJob` | `type` (`pending_approval` / `pending_approval_reminder` / `inventory_digest`), `target_user_id`, `payload_tag`, `order_id?`/`order_item_id?`/`alerts_count?` | 1 fila por sub enviada exitosamente. NO loguear si el envío falló. |

### Enrolamiento (registro de empresa)

| `action` | Disparador | `data` mínimo | Notas |
|---|---|---|---|
| `company.created` | `CompanyEnrollmentController::store` (dentro de la `DB::transaction`) | `nit`, `status` | `auditable` es `Company`. Ya existente pre-#226. |
| `company.activated` | `BillingService::activateCompany()` (vía `companies:approve`) — dentro de `DB::transaction` con `lockForUpdate`. | `previous_status` (`pending_activation`), `new_status` (`active`), `subscription_id`, `billing_plan_id`, `plan_name_snapshot`, `plan_price_snapshot`, `paid_billing_starts_at`, `approved_via` (`artisan_command` vía CLI; `panel` si un futuro flujo web pasa el approver), `notes` (opcional CLI). | `auditable` es `Company`. `user_id` null (operación CLI sin actor). Dispara `CompanyRegistrationApprovedNotification` a owners + admins activos via `notifyOnce` con marker `activation_notified_at`. #257. |
| `enrollment.welcome_email_sent` | `SendCompanyRegistrationWelcomeEmailJob::handle` tras `Notification::sendNow` OK | `company_nit`, `notifiable_route` (email destinatario) | `auditable` es `Company`. Se emite **una sola vez** por `(user_id, company_nit)` gracias a `ShouldBeUnique` + `welcome_email_sent_at` (#226 CA-6). |
| `enrollment.welcome_email_failed` | `SendCompanyRegistrationWelcomeEmailJob::failed` cuando se agotan los `tries=3` | `company_nit`, `reason` (mensaje), `exception` (FQN) | `auditable` es `Company` o null si la empresa fue borrada. Alerta operativa — el registro de la empresa NO se revierte. |
| `enrollment.ops_alert_sent` | `SendCompanyPendingActivationOpsAlertJob::handle` tras notificar al buzón ops | `company_nit`, `ops_address` (destinatario configurado en `mail.ops_alert_address`) | `auditable` es `Company`. Se emite una sola vez por `company_nit` gracias a `ShouldBeUnique` + `ops_alert_sent_at`. `user_id` es el propietario que registró la empresa (no hay un actor real, pero queda trazable). |
| `enrollment.ops_alert_failed` | `SendCompanyPendingActivationOpsAlertJob::failed` cuando se agotan los `tries=3` | `company_nit`, `reason`, `exception` | Alerta operativa — la moderación interna queda sin el aviso automático, debe revisarse manualmente. |

### Notificaciones billing — tracking de envíos (#257)

Tabla append-only (con `SoftDeletes`) `notification_dispatches` con UNIQUE
compuesto `(notification_class, idempotency_key, user_id)`. Una fila por (notif,
user destinatario, evento). El INSERT ocurre en `DedupedMailChannel` **en el
momento del envío** (dentro del worker), no al encolar — por eso un reintento de
cola del job de envío re-ejecuta el INSERT y choca con el UNIQUE → skip
silencioso + log info, sin reenviar el correo.

NO genera entradas en `audit_logs` por cada envío (sería ruidoso —
10+ por empresa por mes). Los duplicados sí dejan log estructurado:

| Log channel | Slug | Trigger | Payload |
|---|---|---|---|
| `Log::info` | `notification.dispatch_skipped_duplicate` | `NotificationDispatchTracker::markDispatched` (invocado por `DedupedMailChannel` en el worker) cuando el INSERT falla por UNIQUE. | `notification_class`, `idempotency_key`, `user_id`, `company_nit` |
| `Log::error` | `notification.dispatch_tracking_failed` | Cualquier otra excepción de BD al insertar (FK inválido, etc.). El correo NO se envía. | `notification_class`, `idempotency_key`, `user_id`, `error` |

**Por qué no son audit_logs**: estos eventos son ruido normal del runtime (retries de cola, ejecuciones cron paralelas). `audit_logs` se reserva para acciones del usuario o transiciones de estado significativas. Para forensía completa, consultar `notification_dispatches` directo.

**Esquema de `idempotency_key` por notification**:

| Notification | `idempotency_key` |
|---|---|
| `CompanyRegistrationApprovedNotification` | `company:{nit}:activated:{subscription_id}` |
| `InvoiceGeneratedNotification` | `invoice:{id}:generated` |
| `InvoiceOverdueNotification` | `invoice:{id}:overdue` |
| `CompanyBlockingSoonNotification` | `company:{nit}:blocking_soon:{YYYY-MM-DD}` (1 por día) |
| `CompanyEnteredPastDueNotification` | `company:{nit}:past_due:{past_due_started_at}` |
| `CompanyPaymentBlockedNotification` | `company:{nit}:suspended:{payment_blocked_at}` |
| `CompanyReactivatedNotification` | `company:{nit}:reactivated:{last_paid_at}` |

### Invitaciones a usuarios de empresa (#227)

| `action` | Disparador | `data` mínimo | Notas |
|---|---|---|---|
| `invitation.sent` | `InvitationController::store` (dentro de la `DB::transaction`) | `company_nit`, `invited_email`, `role`, `invited_by` (user_id del actor) | `auditable` es `CompanyInvitation`. Registro del intent de invitar — el envío real del correo es asíncrono y se audita aparte. |
| `invitation.resent` | `InvitationController::resend` | `company_nit`, `invited_email`, `invited_by` | `auditable` es `CompanyInvitation`. Limpia `email_sent_at` y vuelve a encolar el Job. |
| `invitation.email_sent` | `SendUserInvitationEmailJob::handle` tras `Notification::routes('mail', $email)->notifyNow(...)` OK | `company_nit`, `invited_email`, `expires_at` | `auditable` es `CompanyInvitation`. Se emite **una sola vez** por invitación gracias a `ShouldBeUnique` + `email_sent_at`. Si `resend()` limpia el flag, vuelve a poder emitirse. |
| `invitation.email_failed` | `SendUserInvitationEmailJob::failed` cuando se agotan los `tries=3` | `invitation_id`, `reason` (mensaje), `exception` (FQN) | `auditable` es `CompanyInvitation` o null si fue borrada. Alerta operativa — la invitación en BD no se revierte; el operador puede reenviar via `resend()`. |
| `invitation.accepted` | `InvitedEnrollmentController::store` tras crear membership | (vacío — info en `auditable_id` + `user_id`) | `auditable` es `CompanyInvitation`. Marca status='accepted'. |
| `employee.linked_to_user` | `InvitedEnrollmentController::ensureEmployeeProfile` cuando match por email | `matched_by_email`, `via='invited_enrollment'` | `auditable` es `Employee`. Idempotente. |
| `employee.created` | `InvitedEnrollmentController::ensureEmployeeProfile` cuando no existía employee | `via='invited_enrollment'`, `note` | `auditable` es `Employee` autocreado tras aceptar invitación. |

### Correo electrónico (SES + suppression list)

| `action` | Disparador | `data` mínimo | Notas |
|---|---|---|---|
| `email.suppressed` | `EmailDeliveryService::suppress` (vía `SesNotificationController` o manual) | `email`, `reason` (`bounce` / `complaint` / `manual`), `subtype`, `expires_at` | `auditable` es `EmailSuppression`. Idempotente — si ya existe activa para `(email, reason)`, NO genera nuevo audit. |
| `email.unsuppressed` | `EmailDeliveryService::unsuppress` (admin) | `email`, `reason` | `auditable` es `EmailSuppression`. Marca `expires_at=now()`, no borra fila. |
| `ses.subscription_confirmed` | `SesNotificationController::confirmSubscription` | `topic_arn`, `message_id`, `response_status` | Sin actor (system action — SNS handshake). |
| `ses.unsubscribed` | `SesNotificationController::logUnsubscribe` | `topic_arn`, `message_id` | Alerta operativa — alguien removió la subscription SNS del Configuration Set. |

---

## Cómo añadir un evento auditado nuevo

1. Decidir el slug canónico de `action`: `<dominio>.<acción_concreta>`.
2. Identificar el `auditable` (modelo afectado) y el `actor` (`User`).
3. Definir el `data` mínimo reconstructible. Incluir before/after si aplica.
4. Llamar `AuditService::log($action, $actor, $auditable, $data, $request)` dentro de la `DB::transaction`.
5. Si la acción es financiera: cumplir checklist de `ACCOUNTING_RULES.md`.
6. Agregar fila a la tabla correspondiente de este `.md`.
7. PR descripción: "Nueva acción auditada: `<x>`. Disparada por: `<controller::método>`. Permiso relacionado: `<slug>`."

---

## Reglas duras

- ❌ **NUNCA** `UPDATE` ni `DELETE` de `audit_logs`. Append-only por diseño regulatorio.
- ❌ **NUNCA** dump del modelo entero al `data`. Selectivo.
- ❌ **NUNCA** loguear secretos en `data` (tokens, API keys, passwords).
- ✅ Loguear IP y user-agent (los inserta `AuditService` auto).
- ✅ Loguear acciones de sistema con `user_id=null` y `action='system.*'` (cron jobs, migraciones).

---

## Histórico / deprecaciones

- _(vacío — al cierre de HU #202)_

---

## Referencias cruzadas

- `bistro/backend/app/Services/AuditService.php:24-58` — implementación.
- `bistro/backend/app/Models/AuditLog.php` — modelo.
- `bistro/backend/constants/ACCOUNTING_RULES.md` — auditoría obligatoria en mutaciones financieras.
- `bistro/backend/constants/BRANCH_RBAC.md` — `branch_id` + `actor_active_branch_id` automáticos.
- `bistro/backend/constants/PERMISSIONS_CATALOG.md` — relación permiso↔acción (no 1:1).
