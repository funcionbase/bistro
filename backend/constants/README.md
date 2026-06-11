# `application/constants/` — Fuentes de verdad compartidas backend ↔ frontend

> **Propósito**: documentar de forma canónica los conceptos del proyecto que
> hoy viven replicados — RBAC (roles, permisos, modos de operación, aislamiento
> por sede), operación (estados de orden) y contabilidad CO (métodos de pago,
> regímenes DIAN, auditoría) — para que backend (`FeatureSeeder`,
> `PermissionTemplateSeeder`, `config/roles.php`, `config/orders.php`,
> `FeaturePermissionService`, middlewares, `PostLoginRedirect`, `AuditService`,
> `OrderController`) y frontend (`use-permissions`, `courier-mode`,
> `role-badge`, `lib/order-status.ts`, sidebar) dejen de desincronizarse.

---

## Qué es esta carpeta

- **Referencia para desarrolladores (humanos o agentes Claude)**, **no es
  fuente ejecutable**. Ningún build (Vite, Composer, PHPUnit, Docker) la
  consume; ningún tipo / constante / enum se genera a partir de los `.md`.
- Cada archivo tiene una cabecera `> **Fuente de verdad ejecutable**: …`
  apuntando al archivo PHP (o, en pocos casos, TS) real. **El código gana**
  cuando hay drift; el `.md` se corrige en el mismo PR.
- Cada archivo cierra con `## Pares espejo que deben mantenerse sincronizados`
  listando los archivos físicos que duplican estado del concepto.

## Qué NO es

- No se generan tipos TypeScript ni constantes PHP desde estos `.md`. Si en el
  futuro se desea autogenerar (por ejemplo `permissions.generated.ts` desde el
  catálogo), se abre sub-issue específico (mencionado en
  [#201 — Fuera de alcance](https://github.com/cristianmarint/flexyflow.restaurante/issues/201)).
- No es documentación de producto / negocio ni de arquitectura ejecutiva
  (todo el material humano vive en `docs/wiki/`).

## Archivos

### Núcleo RBAC (#201)

| Archivo | Cubre |
|---|---|
| [`ROLES_SYSTEM.md`](./ROLES_SYSTEM.md) | Los 3 roles `is_system=true` (`owner`, `admin`, `employee`): bypass, último-owner inviolable, helper `config/roles.php`. |
| [`ROLES_TEMPLATES.md`](./ROLES_TEMPLATES.md) | Roles operativos `waiter` / `cook` / `cashier` (creados vía `roles:sync-templates`), defaults exactos. |
| [`ROLES_DEMO.md`](./ROLES_DEMO.md) | Roles que solo existen en seeders QA (`Domiciliario`, `Cocina`), por qué no son `role_type`. |
| [`PERMISSIONS_CATALOG.md`](./PERMISSIONS_CATALOG.md) | Tabla canónica de los ~74 slugs, agrupados por dominio. Manual; se actualiza con cada PR de permiso nuevo. |
| [`COURIER_MODE.md`](./COURIER_MODE.md) | Definición del modo *courier-only*: `FULL_NAV_PERMISSIONS`, `COURIER_PERMISSION`, los 2 archivos espejo. |
| [`BRANCH_RBAC.md`](./BRANCH_RBAC.md) | Aislamiento por sede (#192): `BranchScope`, `branch_users`, middlewares, permisos sensibles owner-only. |
| [`RBAC_CHECKLIST.md`](./RBAC_CHECKLIST.md) | Checklist accionable para agregar / renombrar / eliminar un permiso o rol. **Para pegar en el cuerpo del PR.** |

### Operaciones y contabilidad (#202)

| Archivo | Cubre |
|---|---|
| [`ORDER_STATUSES.md`](./ORDER_STATUSES.md) | Estados de `orders` (10) y `order_items` (6), transiciones forward-only, `kanban_rank`, `revenue`. Espejo de `config/orders.php` + `lib/order-status.ts`. |
| [`PAYMENT_METHODS.md`](./PAYMENT_METHODS.md) | Lista cerrada `cash \| card \| transfer \| refund`, signos (cobros positivos / refunds negativos), `reference` por método, conservación DIAN 5/10 años. |
| [`ACCOUNTING_RULES.md`](./ACCOUNTING_RULES.md) | Resumen estructurado de CLAUDE.md §13 (decimal(12,2), DB::transaction + lockForUpdate, IVA 19% / INC 8% / RST, propina separada, refunds como asiento nuevo) + checklist pre-merge financiero. |
| [`MIDDLEWARE_MAP.md`](./MIDDLEWARE_MAP.md) | Stack de middleware por contexto, aliases canónicos (`jwt`, `company.access`, `branch.access`, `permission:<slug>,<action>`) y orden lógico en rutas. |
| [`AUDIT_EVENTS.md`](./AUDIT_EVENTS.md) | Catálogo de acciones registradas por `AuditService::log`. Convenciones de `action` slug + `data` mínimo reconstructible. |
| [`FEATURES_INDEX.md`](./FEATURES_INDEX.md) | Índice de módulos funcionales por dominio (10 dominios). Cross-ref al wiki, backend/frontend clave y permisos asociados. Portada hacia `docs/wiki/`. |

### Usuarios, legal y entregas (#203)

| Archivo | Cubre |
|---|---|
| [`USER_STATUSES.md`](./USER_STATUSES.md) | Enum `users.status` (`pending_enrollment` / `active` / `inactive`), transiciones, distinción con `company_users.status`, permisos para mutar. |
| [`LEGAL_DOCUMENT_TYPES.md`](./LEGAL_DOCUMENT_TYPES.md) | Tipos canónicos `terms` / `privacy` / `contract`, inmutabilidad por `(type, version)`, alias `tos→terms`, snapshot en `user_acceptances`. |
| [`DELIVERY_STATUSES.md`](./DELIVERY_STATUSES.md) | Estados `deliveries.status` (`pending` / `completed` / `cancelled`), razones canónicas (`error_usuario` / `pedido_rechazado` / `reassigned`), CHECK constraints. |

### Colaboradores y tributario (#204)

| Archivo | Cubre |
|---|---|
| [`EMPLOYEE_STATUSES.md`](./EMPLOYEE_STATUSES.md) | Enum `employees.vinculation_status` (`active` / `inactive` / `vacation` / `sick_leave` / `compensatory`), transiciones, policy de denegación, cascada de turnos, sincronización con `users.status`. Espejo de `config/employees.php`. |
| [`TAXES_AND_REGIMES.md`](./TAXES_AND_REGIMES.md) | Regímenes tributarios CO (`simple` / `inc_8` / `iva_19` / `iva_5` / `iva_exento` / `custom`), columnas snapshot en `orders` (`tax_amount`, `tax_rate`, `tax_regime`), propina separada (`tip_amount`). Espejo de `config/taxes.php`. |

### Empresa (#205)

| Archivo | Cubre |
|---|---|
| [`COMPANY_STATUSES.md`](./COMPANY_STATUSES.md) | Enum `companies.status` (`pending_activation` / `active` / `past_due` / `suspended` / `rejected` / `inactive`), buckets semánticos (`verified` / `pending` / `blocked` / `fully_blocked`), gates (`EnsureCompanyVerified`, `EnsureCompanyNotBlocked`), workflow ops `.github/workflows/company-status.yml`, snapshot extra `past_due`/`suspended`. Espejo de `config/companies.php` + `lib/company-status.ts`. |

### Infraestructura (#239)

| Archivo | Cubre |
|---|---|
| [`INFRASTRUCTURE.md`](./INFRASTRUCTURE.md) | Mapa de hosts por entorno (frontend SPA en Cloudflare Workers, backend API en AWS ALB), TLS (ACM wildcard `*.flexyflow.co`), DNS (Cloudflare, no Route53), recursos críticos AWS, pares espejo backend ↔ frontend ↔ IaC. Plan de migración #239 (panel.flexyflow.co) con cross-ref al runbook. |

## Regla obligatoria

Antes de tocar cualquier archivo que afecte permisos, roles, courier-mode,
RBAC, branch isolation o seeders relacionados (`FeatureSeeder`,
`PermissionTemplateSeeder`, `RestauranteFlexySeeder`, `config/roles.php`,
`PostLoginRedirect.php`, `lib/courier-mode.ts`, `use-permissions.ts`,
middlewares de la familia `Ensure*Access`):

1. **Leer primero** el `.md` correspondiente en esta carpeta.
2. Aplicar el cambio en el **código ejecutable**.
3. **Actualizar el `.md` correspondiente en el mismo PR**, marcando la fila
   modificada en `PERMISSIONS_CATALOG.md` o el archivo que aplique.
4. Si encontrás drift entre `.md` y código: el **código gana**, pero corregís
   el `.md` y dejás comentario en el issue raíz si fue intencional.

La regla larga vive en [`CLAUDE.md`](../../CLAUDE.md) raíz — sección
**FUENTES DE VERDAD COMPARTIDAS BACKEND/FRONTEND (`application/constants/`)**.

## Exclusión de builds — verificado

Esta carpeta vive en `application/constants/`, fuera de cualquier ruta que
algún build incluya:

| Build | Cómo se garantiza |
|---|---|
| **Vite** (`application/vite.config.js`) | `input` es `resources/css/app.css` + `resources/js/app.tsx` + ssr `resources/js/ssr.jsx`. `constants/` no se importa desde ningún módulo TS/JS. |
| **Composer** (`application/composer.json`) | `autoload` PSR-4 mapea `app/`, `database/factories/`, `database/seeders/`. `autoload-dev` mapea `tests/`. `constants/` queda fuera. |
| **PHPUnit / Pest** (`application/phpunit.xml`) | Única testsuite registrada: `tests/Feature/Architecture`. Sin glob recursivo. |
| **Docker** | No hay `Dockerfile` en el repo a la fecha de creación de esta carpeta. Si se introduce en el futuro un deploy con Docker que copie `application/` entera, agregar `application/constants/` al `.dockerignore`. Documentado en `RBAC_CHECKLIST.md`. |

Verificación inicial: `rm -rf application/constants && npm --prefix application run build && cd application && php artisan optimize` pasa limpio (probado en
Fase 2 del issue #201; restaurado tras la prueba).

## Mantenimiento

- Cada `.md` lleva un `> Última revisión: YYYY-MM-DD (#issue)` al pie.
- Si el `.md` no se ha tocado en > 6 meses y hubo PRs de RBAC en ese período,
  abrir issue de auditoría.
- No duplicar contenido del wiki público (`docs/wiki/`). Esta carpeta apunta
  al wiki cuando aplica; el wiki cita esta carpeta para devs.

> Última revisión: 2026-05-19 (#201 + #202 + #203 + #204 + #205)
