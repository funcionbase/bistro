# COMPANY_STATUSES — Fuente única de verdad

> **Antes de modificar el enum `companies.status`, los buckets semánticos
> (`verified`/`pending`/`blocked`/`fully_blocked`), las transiciones permitidas
> o las labels de UI, lee este archivo.**
> **Después de modificar, actualiza este archivo + `config/companies.php` +
> la migración + el workflow ops + los tipos TS + el helper
> `lib/company-status.ts` en el mismo PR.**

---

## Fuente de verdad ejecutable

- `bistro/backend/config/companies.php` — catálogo canónico
  (`all`, `verified`, `pending`, `blocked`, `fully_blocked`, `default`,
  `allowed_transitions`, `labels`).
- `bistro/backend/database/migrations/0001_01_01_000100_create_companies_block.php:73-80`
  — enum BD (default `'pending_activation'`).
- `.github/workflows/company-ops.yml` (action `change_status`) — workflow ops
  manual que muta el status. Valida `from→to` contra `allowed_transitions`.
  Idempotente si `from==to` (no genera audit log).
- `.github/sql/company-status.sql` — SQL parametrizada que aplica
  la transición + inserta `audit_logs`.

> El `.md` es **referencia humana**. Si difiere del código, el código gana.

---

## Estados de `companies.status`

Una sola fila por empresa. Ciclo principal: nace `pending_activation` al
crearse vía enrollment, workflow ops valida y la mueve a `active` (o
`rejected`). El estado `past_due`/`suspended` se aplica vía el job de
facturación cuando hay facturas vencidas (#175). `inactive` es baja
voluntaria/administrativa.

| status | Bucket | Label UI (es-CO) | Badge sugerido | Terminal | Notas |
|---|---|---|---|---|---|
| `pending_activation` | `pending` | Pendiente de verificación | `secondary` | no | Default al crear empresa. Espera workflow ops manual. JWT se emite pero `EnsureCompanyVerified` bloquea acceso operativo. |
| `active` | `verified` | Activa | `safe` | no | Operando OK. Paga al día o en trial. Único estado completamente operativo. |
| `past_due` | `verified` | En mora | `warning` | no | ≥1 factura vencida y atraso ≤ 3 meses calendario. **Sigue operando normal**, solo se muestra banner. |
| `suspended` | `verified` + `fully_blocked` | Suspendida | `critical` | no | Atraso > 3 meses. `EnsureCompanyNotBlocked` rechaza todas las rutas excepto `/billing`, `/dashboard`, settings personales y comprobante. |
| `rejected` | `blocked` | Rechazada | `destructive` | no | Workflow de verificación marcó la empresa como inválida. Owner puede reintentar enrollment (`rejected → pending_activation`). |
| `inactive` | `blocked` | Inactiva | `secondary` | semi-terminal | Baja administrativa/voluntaria. No se usa por past_due. |

Fuente: `config('companies.all')` + `config('companies.labels')`.

### Estados retirados (histórico)

| Estado retirado | Reemplazo | Issue |
|---|---|---|
| `verified` | `active` | #175 — el bucket `verified` ahora es semántico, no un estado físico. |
| `delinquent` | `past_due` | #175 — semántica clarificada. |

---

## Buckets semánticos

Los buckets son **agrupaciones** de status que los gates y la UI consumen.
No viven en BD: existen solo en `config/companies.php`.

| Bucket | Contiene | Quién lo usa | Para qué |
|---|---|---|---|
| `verified` | `active`, `past_due`, `suspended` | `EnsureCompanyVerified` | Permite que el request entre al stack operativo. `EnsureCompanyNotBlocked` decide después si la ruta concreta es accesible. |
| `pending` | `pending_activation` | `pages/auth/company-selector.tsx`, redirect a `/company/under-review` | Empresa onboardeando, JWT emitido pero sin acceso operativo. |
| `blocked` | `rejected`, `inactive` | UI muestra "Cuenta no disponible". | Terminal o semi-terminal. |
| `fully_blocked` | `suspended` | `EnsureCompanyNotBlocked` | Bloqueo comercial por mora prolongada. Solo `/billing` y comprobantes pasan. |

> Nota: `suspended` está **tanto en `verified` como en `fully_blocked`**. Eso
> es intencional: `verified` lo deja pasar el primer gate (JWT + empresa
> onboardeada), pero el segundo gate (`EnsureCompanyNotBlocked`) lo rechaza
> en todas las rutas salvo el whitelist de billing.

---

## Transiciones permitidas

`config('companies.allowed_transitions')` — usado por el workflow ops para
validar input. Cualquier par `(from, to)` no listado es rechazado.

```
pending_activation → active | rejected
rejected           → pending_activation
```

Las transiciones operativas hacia `past_due` / `suspended` / `inactive`
**no** pasan por el workflow ops — las dispara el job de facturación
(`billing:settle`) o el admin desde un endpoint dedicado. El workflow ops
solo cubre el flujo manual de verificación inicial / rechazo.

Idempotencia: si `from == to`, el workflow lo trata como no-op y NO inserta
audit log.

---

## Snapshot extra (`past_due` / `suspended`)

Cuando `status ∈ {past_due, suspended}`, `HandleInertiaRequests::share`
adjunta al `activeCompany` un snapshot adicional desde la BD:

- `past_due_started_at` — `timestamp`, momento en que entró en mora.
- `expected_block_at` — `date`, día calculado en que pasaría a `suspended`.
- `payment_blocked_at` — `timestamp`, momento en que el sistema bloqueó.
- `flexyflow_payment` — objeto con datos para regularizar pago.

Esto vive en `companies.past_due_started_at`, `companies.expected_block_at`,
`companies.payment_blocked_at` (migración foundation, líneas 82-84).

---

## Impacto RBAC

- **Permiso para mutar `status` vía API**: no hay endpoint público. La
  mutación pasa por:
  - **Workflow ops manual** (`pending_activation` ↔ `active`/`rejected`):
    actor humano con acceso al repo GH.
  - **Job de facturación** (`active` → `past_due` → `suspended` →
    `active` post-pago): proceso automatizado, sin actor humano.
  - **Enrollment** (`rejected` → `pending_activation`): el owner desde la
    UI de re-onboarding.
- **Owner**: ni siquiera el owner puede mutar `status` de su propia
  empresa por API. Acción reservada al operador del sistema (workflow ops).
- **Auditoría**: cada cambio dispara fila en `audit_logs` (acción
  `company.status_changed`) con metadata `{from, to, actor_label, reason,
  github_run_url}`.

---

## Frontend — cómo se consume

A partir de #205 las vistas que pintan o filtran por status consumen el
catálogo vía `resources/js/lib/company-status.ts`:

```ts
import {
  companyStatusLabel,
  companyStatusBadgeVariant,
  isVerified,
  isFullyBlocked,
} from '@/lib/company-status';

// companyStatusLabel('past_due') === 'En mora'
// companyStatusBadgeVariant('suspended') === 'critical'
// isVerified('active') === true
// isFullyBlocked('suspended') === true
```

El tipo canónico `CompanyStatus` se exporta desde
`resources/js/lib/company-status.ts` y se reutiliza en `types/index.ts`
(`Company.status`). El antiguo `CompanyBillingStatus` (`types/billing.ts`)
queda eliminado — era código duplicado sin consumidores.

### Páginas / componentes que consumen el catálogo

- `pages/auth/company-selector.tsx` — labels + badge variants + cómputo
  de `SELECTABLE_STATUSES` (= bucket `verified`).
- `pages/billing/index.tsx`, `pages/dashboard.tsx`,
  `pages/company/under-review.tsx`, `pages/company/settings.tsx` —
  flags `isCompanySuspended`, redirects de empresas sin onboardear.
- `components/billing/PastDueBanner.tsx`,
  `components/billing/OverdueBanner.tsx` — gating por bucket.
- `components/app-sidebar.tsx` — filtra navegación cuando
  `isFullyBlocked(activeCompany.status)`.

---

## Cómo añadir un status nuevo

1. Migración nueva que altere el CHECK constraint (PostgreSQL: drop CHECK
   auto-named via `pg_constraint`, recrear con la lista completa).
2. Agregar el slug a `config/companies.php`:
   - `all` (mandatorio).
   - Bucket(s) correspondiente(s) (`verified` / `pending` / `blocked` /
     `fully_blocked`).
   - `labels[<slug>]`.
   - `allowed_transitions` si participa en el flujo manual.
3. Actualizar `COMPANY_STATUS_FALLBACK` en `resources/js/lib/company-status.ts`
   (labels + buckets + badges).
4. Extender el union `CompanyStatus` en `lib/company-status.ts`.
5. Si el nuevo status entra al workflow ops manual, agregarlo a las
   `options:` del `.github/workflows/company-status.yml`.
6. Si tiene gate dedicado (e.g., `EnsureCompanySomething`), crear el
   middleware y registrarlo en `bootstrap/app.php`.
7. Si tiene snapshot extra (como `past_due`), agregar columnas a la
   migración + sumarlas a `HandleInertiaRequests::share`.
8. Actualizar este `.md` (tabla canónica + buckets + transiciones).
9. PR descripción: "Nuevo status `<x>`. Bucket(s): `<...>`. Gates: `<...>`.
   Workflow ops: sí/no. Auditoría: sí (`company.status_changed`)."

---

## Pares espejo que deben mantenerse sincronizados

- `database/migrations/0001_01_01_000100_create_companies_block.php:73-80` ↔ `config/companies.all` ↔ tabla de este `.md`.
- `config/companies.php` ↔ helpers de `lib/company-status.ts` (`COMPANY_STATUS_FALLBACK`).
- `app/Http/Middleware/EnsureCompanyVerified.php:49` ↔ `config('companies.verified')`.
- `app/Http/Middleware/EnsureCompanyNotBlocked.php:110` ↔ `config('companies.fully_blocked')`.
- `app/Http/Middleware/HandleInertiaRequests.php` ↔ snapshot extra para `past_due`/`suspended`.
- `.github/workflows/company-status.yml` ↔ `options:` del input `status` (subset operable manualmente) + `allowed_transitions` validados en `company-status.sql`.
- `resources/js/lib/company-status.ts` ↔ `CompanyStatus` union + fallback.
- `resources/js/types/index.ts` (`Company.status`) ↔ reusa `CompanyStatus` desde lib.

---

## Referencias cruzadas

- [`USER_STATUSES.md`](./USER_STATUSES.md) — `users.status` (no confundir;
  son ortogonales).
- [`PERMISSIONS_CATALOG.md`](./PERMISSIONS_CATALOG.md) — los permisos NO
  controlan mutación de `company.status` (acción reservada al sistema).
- [`AUDIT_EVENTS.md`](./AUDIT_EVENTS.md) — `company.status_changed`.
- [`MIDDLEWARE_MAP.md`](./MIDDLEWARE_MAP.md) — `EnsureCompanyVerified`,
  `EnsureCompanyNotBlocked`.
- `docs/wiki/BILLING_PLAN.md` — flujo `active → past_due → suspended`
  driven por `billing:settle`.

> Última revisión: 2026-05-19 (#205)
