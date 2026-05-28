# Fidelización con Puntos

> Estado: Estable (v1)
> Versión API: v1
> Owner: equipo de plataforma

Programa de retención cross-sede (issue #122). Una cuenta de puntos vive por
`(company_nit, client_phone)` sin importar la sede donde el cliente pidió.
Configurable por empresa vía `company_settings` con prefijo `loyalty.*`;
defaults globales en `config/loyalty.php` y variables `LOYALTY_*` del `.env`.

Pantalla principal: `/loyalty/reports`. Integraciones:
- Staff: panel embebido en `/clients/{contact}` (`LoyaltyPanel`).
- Cliente final: tarjeta en `/cart/{jwt}` (`LoyaltyCard`).
- Bot externo: endpoints `/api/external/loyalty/*` (consumidos por flujos
  n8n, fuera de este repo).

---

## Resumen

| Capacidad | Detalle |
|---|---|
| Cross-sede | Sí. `LoyaltyAccount` no usa `BelongsToBranch`. |
| Moneda | COP. Puntos **NO son moneda** (no tocan `payment_receipts`). |
| Idempotencia earn | UNIQUE PARCIAL `(reference_type='order', reference_id, type='earn')`. |
| Concurrencia | `DB::transaction` + `LoyaltyAccount::lockForUpdate()` en todas las mutaciones. |
| Append-only | `LoyaltyMovement::UPDATED_AT = null`. Errores se corrigen con un movement nuevo. |
| Schedule | `loyalty:expire-stale` diario `04:15` con `->onOneServer()`. |

---

## Modelo de datos

### `loyalty_accounts`

| Campo | Notas |
|---|---|
| `id` (uuid) | PK. |
| `company_nit`, `client_phone` | UNIQUE `(company_nit, client_phone)`. |
| `balance` | Saldo actual de puntos (int). |
| `lifetime_earned` | Acumulado histórico. Mueve el tier; **no** se mueve por adjusts positivos. |
| `tier` | `bronze` / `silver` / `gold` (configurable). |
| `last_activity_at` | Timestamp del último movement. Para job de expiración. |

Mutaciones SIEMPRE via `LoyaltyService` dentro de transacción con
`lockForUpdate()`.

### `loyalty_movements`

Libro mayor append-only.

| Campo | Notas |
|---|---|
| `id` (uuid) | PK. |
| `loyalty_account_id`, `company_nit` | Denormalizado para queries cross-cuenta. |
| `type` | `earn` \| `redeem` \| `refund_reverse` \| `adjust` \| `expire`. |
| `points` | Signed. Positivo = entra al balance, negativo = sale. |
| `reference_type`, `reference_id` | Polimórfico: `order` para earn/refund_reverse, `loyalty_redemption` para redeem. |
| `actor_id` | `users.id` para `adjust`. NULL para earn/expire automáticos. |
| `meta` (jsonb) | Metadata reconstructible (`reason`, `coupon_code`, `tier_before`, etc.). |
| `created_at` | Inmutable. **`UPDATED_AT = null`**. |

### `loyalty_redemptions`

Vincula un canje con su Coupon temporal single-use.

| Campo | Notas |
|---|---|
| `loyalty_account_id`, `loyalty_movement_id` | Trazabilidad bidireccional. |
| `coupon_id`, `reward_key` | Cupón emitido y key del catálogo. |
| `points` | Puntos descontados (positivo). |
| `status` | `issued` → `applied` / `expired` / `cancelled`. |
| `expires_at` | Igual a `coupons.valid_until` del cupón emitido. |
| `applied_at`, `applied_order_id` | Cuando `CouponService::redeemCoupon` la aplica. |

### `coupons` (extensión)

Columnas añadidas por el módulo:
- `is_single_use` (bool).
- `locked_to_phone` (varchar, normalizado).
- `source` (varchar): `loyalty_redeem` para cupones de canje; se ocultan de
  listados públicos de cupones disponibles.

---

## Permisos RBAC

| Slug | owner | admin | manager | accountant |
|---|---|---|---|---|
| `loyalty.read` | RCUD | RCUD | R--- | R--- |
| `loyalty.update` | RCUD | RCUD | --U- | ---- |

Owner bypass por `role.is_system=true`. Otros roles (waiter, cashier, cook)
NO reciben loyalty por default. Asignable manualmente desde
`UserPermissionsEditor`. Ver `application/constants/PERMISSIONS_CATALOG.md`.

Reportes (`/loyalty/reports`) usan middleware adicional `branch.consolidate`
para permitir vista cross-sede.

---

## Reglas de acumulación (earn)

Tras `OrderController::closeWithPayment` (fuera de la transacción de cobro,
para no reversar un pago válido si el programa falla),
`LoyaltyService::award($order)`:

1. Solo corre si `loyalty.enabled` para la empresa y la orden tiene
   `client_phone`.
2. Calcula puntos:

   ```
   points = floor(orderTotal * points_per_cop * tier_multiplier)
   ```

   Multiplicadores default por tier: bronze 1.0×, silver 1.2×, gold 1.4×.
3. Tier se recalcula tras cada earn: `tierFor(lifetime_earned)` recorre
   `config('loyalty.tiers')` ascendentemente.
4. Idempotencia garantizada por el UNIQUE PARCIAL — si el closeWithPayment
   se reintenta, el segundo intento no duplica el award.

`points_per_cop` y los thresholds de tier son overridables en
`company_settings` (`loyalty.points_per_cop`, `loyalty.tiers`).

---

## Reglas de canje (redeem)

Catálogo en `config('loyalty.rewards')`. Cada reward declara:

| Campo | Notas |
|---|---|
| `key` | Identificador en catálogo (`free_drink`, `discount_5k`, etc.). |
| `points` | Costo del canje. |
| `discount_type` | `fixed_amount` en v1. Item gratis queda para v2. |
| `discount_value` | Pesos COP de descuento. |
| `min_order_amount` | Subtotal mínimo del carrito para aplicar. |
| `label` | Texto visible. |

`LoyaltyService::redeem`:

1. Descuenta `reward.points` del balance (lock + transacción).
2. Emite `LoyaltyMovement` con `type=redeem`, `points` negativo.
3. Crea `Coupon` con:
   - `scope='company'`
   - `max_uses=1`
   - `valid_until = now() + loyalty.redemption_expires_minutes`
   - `is_single_use=true`
   - `locked_to_phone=<phone>`
   - `source='loyalty_redeem'`
   - código autogenerado `LYL-XXXXXXXX`.
4. Persiste `LoyaltyRedemption` con `status='issued'`.

Cuando el cupón se aplica (via `CouponService::redeemCoupon`), la redemption
pasa a `applied` con `applied_order_id` fijo.

`Coupon::isValidFor()` rechaza el cupón si:
- `locked_to_phone` ≠ phone normalizado del checkout → "Este cupón solo puede
  ser usado por el cliente que lo canjeó."
- `is_single_use` y `uses_count >= 1` → "Este cupón ya fue usado."

### Canje desde el carrito público (`/cart/{jwt}`)

`POST /api/v1/public/loyalty/{nit}/redeem` (rate-limit `throttle:loyalty-public`
10 req/min). El frontend inyecta el `coupon_code` emitido como `initialCode`
del `CouponInput` para que el cliente solo presione "Aplicar".

Si la empresa tiene `loyalty.enabled=false` o está bloqueada por mora,
responde 404 (sin revelar existencia del cliente).

---

## Movimientos del libro mayor

Catálogo cerrado de `type` (constantes en `LoyaltyMovement::TYPE_*`):

| Tipo | Signo de `points` | Origen | Mueve `lifetime_earned`? |
|---|---|---|---|
| `earn` | Positivo | `OrderController::closeWithPayment` → `LoyaltyService::award`. | Sí. |
| `redeem` | Negativo | `LoyaltyService::redeem` (staff o público). | No. |
| `refund_reverse` | Negativo | `LoyaltyService::refundReverse` en refunds **totales**. | Sí, resta. |
| `adjust` | Positivo o negativo | Staff via `POST /loyalty/accounts/{phone}/adjust`. | No (positivo) / No (negativo). |
| `expire` | Negativo (= `-balance`) | Job `loyalty:expire-stale`. | No. |

### Reglas contables (CLAUDE.md compliance)

- Puntos **NO son moneda**. Nunca tocan `payment_receipts`.
- Refunds **totales** disparan `refund_reverse`. Refunds parciales NO
  reversan puntos (decisión pragmática: el incentivo del cliente se
  mantiene).
- Canje **consume puntos al crearse**. Si el cupón expira sin usarse, los
  puntos NO se devuelven (el evento financiero ya ocurrió).
- Si staff cancela un canje antes de aplicarse, sí se devuelven vía
  `type=adjust` positivo equivalente.
- Todas las mutaciones bajo `DB::transaction` + `LoyaltyAccount::lockForUpdate()`.

### Ajustes manuales

`POST /api/v1/loyalty/accounts/{phone}/adjust` requiere:
- `loyalty.update`.
- `points`: int, `not_in:0`.
- `reason`: mín 3 chars, `SafePlainText(maxBytes: 255)`.
- Tope `LOYALTY_MAX_MANUAL_ADJUST` (default 10.000 pts).

Reglas:
- Ajustes positivos **no** suman a `lifetime_earned` (evita inflar tiers
  artificialmente).
- Ajustes negativos no pueden dejar balance < 0.
- Quedan en `audit_logs` como `loyalty.adjusted` con `points`, `reason`,
  `balance_after`, `actor_id`.

---

## Job de expiración (`loyalty:expire-stale`)

Schedule: `routes/console.php`.

```php
Schedule::command('loyalty:expire-stale')->dailyAt('04:15')->onOneServer();
```

`->onOneServer()` exige cache store compartido. El proyecto usa
`CACHE_STORE=database` sobre Postgres (stack canónico — sin Redis), lo que
es suficiente. Cumple CLAUDE.md §12 (N-instance safe).

Comportamiento por cada empresa con `loyalty.enabled`:

1. Marca como `expired` redenciones `issued` con `expires_at < now`.
2. Si `loyalty.expire_after_months > 0`: cuentas con balance > 0 y
   `last_activity_at` (o NULL) anterior a `now - N meses` se expiran
   completamente. Crea movement `type=expire` con `points = -balance` y
   resetea `balance` a 0.

Flags:
- `--dry-run`: salta la expiración masiva pero igual marca redenciones
  vencidas.
- `--company={nit}`: limita el alcance a una empresa.

---

## Endpoints

Prefijo: `/api/v1`.

### Staff (JWT + `company.access`)

| Método | Ruta | Permiso |
|---|---|---|
| GET | `/loyalty/accounts` | `loyalty.read,read` |
| GET | `/loyalty/accounts/{phone}` | `loyalty.read,read` |
| POST | `/loyalty/accounts/{phone}/adjust` | `loyalty.update,update` |
| POST | `/loyalty/accounts/{phone}/redeem` | `loyalty.update,update` |
| GET | `/loyalty/reports/summary` | `loyalty.read,read` + `branch.consolidate` |

`GET /loyalty/accounts/{phone}` devuelve placeholder con balance 0 si la
cuenta aún no existe (no 404), para que la UI pinte tarjeta vacía sin error.
Todos los lookups usan POST para los flujos públicos/externos — los staff
GET son aceptables porque el phone va por URL autenticada y no toca el log
público.

### Público (sin auth, throttle agresivo)

| Método | Ruta | Throttle | Notas |
|---|---|---|---|
| POST | `/public/loyalty/{nit}/lookup` | `loyalty-public` (10/min) | POST para evitar phones en `access.log`. |
| POST | `/public/loyalty/{nit}/redeem` | `loyalty-public` (10/min) | Devuelve `coupon.code`. |

### Externo / bot (`/api/external` con `bot.jwt`)

| Método | Ruta |
|---|---|
| POST | `/loyalty/lookup` |
| POST | `/loyalty/redeem` |

El parseo de intents (`/puntos`, `/canjear`) vive en el flujo n8n externo,
fuera del repo.

---

## Reportes (`/loyalty/reports`)

`LoyaltyReportController::summary` (`GET /api/v1/loyalty/reports/summary`).
Filtros `from` / `to` (default 30 días en `America/Bogota`). Todas las
agregaciones en SQL (CLAUDE.md §13).

KPIs del período:
- `points_earned`, `points_redeemed`, `points_expired`, `points_reversed`.
- `earn_events`, `redeem_events`, `active_earners`.
- Tasa de canje: `applied / total` de redemptions del rango.
- Distribución de cuentas por tier (`tiers_distribution`).
- ARPU por tier: revenue + ARPU calculado en SQL contra `orders.status=completed`.
- Top 20 clientes por `lifetime_earned`.
- Panel de expiraciones.

Si `loyalty.enabled=false`, la respuesta incluye `enabled: false` y los KPIs
quedan en cero — la UI muestra un empty state con CTA "Activar programa" si
el actor tiene `loyalty.update`.

---

## Componentes frontend

### Staff

- `pages/loyalty/reports.tsx`: dashboard con `StatTile`, `KpiCell`,
  `Table`, `LoyaltyBadge`, `LoyaltyReportsSkeleton`.
- `LoyaltyPanel` (embebido en `/clients/{contact}/show.tsx`): saldo, tier
  badge, progreso a siguiente tier, catálogo de rewards (canjeables /
  bloqueados por mínimo), historial de 50 movements, modales para ajustar
  puntos y para canjear en nombre del cliente.
- Tras canjear staff-side, el `coupon_code` se muestra para que el operador
  se lo dicte al cliente.

### Cliente final

- `LoyaltyCard` en `/cart/{jwt}`: aparece solo si el carrito tiene
  `client_phone`. 404 silencioso si el programa está deshabilitado.
- Muestra saldo + progreso a siguiente tier + recompensas (deshabilita las
  que requieren mínimo mayor al subtotal actual).
- Al canjear, el `coupon_code` emitido se inyecta como `initialCode` al
  `CouponInput`.

---

## Eventos de auditoría

Emitidos por `LoyaltyService` vía `AuditService::log`:

| Acción | Data mínimo |
|---|---|
| `loyalty.earned` | `account_id`, `order_id`, `points`, `tier_before`, `tier_after`. |
| `loyalty.adjusted` | `account_id`, `points`, `reason`, `balance_after`, `actor_id`. |
| `loyalty.redeemed` | `account_id`, `reward_key`, `points`, `coupon_id`, `coupon_code`. |
| `loyalty.refund_reversed` | `account_id`, `order_id`, `points`. |
| `loyalty.expired` | `account_id`, `points`, `balance_before`. |
| `loyalty.redemption_expired` | `redemption_id`, `coupon_id`. |

`AuditService::log` agrega `branch_id` (NULL para mutaciones cross-sede del
módulo) y `actor_active_branch_id`. Ver
`application/constants/AUDIT_EVENTS.md`.

---

## Configuración por empresa

Overrides en `company_settings` (todos opcionales — si faltan, se usa
`config/loyalty.php`):

| Key | Tipo | Notas |
|---|---|---|
| `loyalty.enabled` | bool | Habilita el programa. Default `false`. |
| `loyalty.points_per_cop` | float | Ratio de puntos por peso. Ej `0.01` = 1 pto por 100 COP. |
| `loyalty.tiers` | JSON | Mismo shape que `config/loyalty.php`. |
| `loyalty.refund_reverses_points` | bool | Override del comportamiento de refunds totales. |
| `loyalty.expire_after_months` | int | 0 = sin expiración. |
| `loyalty.redemption_expires_minutes` | int | TTL del cupón emitido. |

---

## Edge cases y empty states

- **Programa deshabilitado**: endpoints staff devuelven `config.enabled=false`
  para que la UI muestre tarjeta vacía con CTA. Endpoints públicos responden
  404 (no revelan existencia del cliente).
- **Empresa bloqueada por mora (#193)**: endpoints públicos responden 404 sin
  revelar motivo comercial.
- **Cuenta inexistente**: `GET /loyalty/accounts/{phone}` devuelve placeholder
  con balance 0 + rewards (no 404).
- **Reward sin balance suficiente**: `redeemable=false` en la respuesta; la UI
  muestra el reward greyed-out con etiqueta "Te faltan X pts".
- **Cupón expirado sin usar**: queda en `loyalty_redemptions.status='expired'`;
  los puntos NO se devuelven.
- **Canje cancelado por staff** (no entregado al cliente): devolución vía
  `type=adjust` positivo equivalente, audit `loyalty.adjusted` con `reason`.
- **Refund parcial**: NO dispara `refund_reverse` (decisión consciente).
- **Concurrencia (dos cierres simultáneos)**: el UNIQUE PARCIAL impide award
  doble; el segundo lanza `UniqueConstraintViolation` que `LoyaltyService`
  captura silenciosamente.
- **Adjust positivo que infla tier**: bloqueado por diseño — `lifetime_earned`
  solo se mueve con earn real.

---

## Pendientes fuera de alcance v1

- Rewards de ítem gratis (solo descuentos fijos en v1).
- Reversa proporcional en refunds parciales.
- Comandos del bot (`/puntos`, `/canjear`): los endpoints existen, el parsing
  de intents queda en el flujo n8n externo.
- Notificaciones automáticas al cambiar de tier.
- Multimoneda (todo COP).

---

## Cross-references

- Constants: `application/constants/PERMISSIONS_CATALOG.md`,
  `ACCOUNTING_RULES.md`, `AUDIT_EVENTS.md`, `FEATURES_INDEX.md`.
- Backend: `app/Http/Controllers/Api/LoyaltyController.php`,
  `LoyaltyReportController.php`, `PublicLoyaltyController.php`,
  `ExternalLoyaltyController.php`,
  `app/Services/LoyaltyService.php`,
  `app/Models/LoyaltyAccount.php`, `LoyaltyMovement.php`,
  `LoyaltyRedemption.php`,
  `app/Console/Commands/LoyaltyExpireStale.php`,
  `config/loyalty.php`.
- Frontend: `src/pages/loyalty/reports.tsx`,
  `components/loyalty/loyalty-panel.tsx`, `loyalty-card.tsx`,
  `loyalty-badge.tsx`, `hooks/use-loyalty.ts`.
- Routes schedule: `routes/console.php` → `loyalty:expire-stale`.
- Relacionados: `CRM-Clientes.md`, `Chats-Clientes.md`.
