# RBAC — refinamiento de issues + fuentes de verdad compartidas

> REGLA OBLIGATORIA. Consultar antes de refinar/planear issues de producto y antes de tocar permisos, roles, branch scope o cualquier archivo del listado "Cuándo aplica".

## 1. Refinamiento de issues / historias — RBAC siempre presente

Siempre que el usuario pida **refinar, redactar, completar, analizar o planear** un issue/HU que toque producto, DEBES evaluar impacto sobre el sistema RBAC y dejarlo explícito. Aplica incluso si el issue no menciona permisos: multi-tenant (`company_nit`) + multi-sede (`branch_id`) + roles por empresa (`company_roles`) + permisos asignables hace que casi cualquier feature tenga implicaciones.

**Cuándo aplica**: `/refine`, `/plan`, frases tipo "refiname este issue", "redacta la historia", "completa #NNN", "analiza qué falta", "revisa gaps", redacción de CAs o plan que vaya a comentario, review de PR que implementa una HU.

**Qué revisar (los 10 puntos)**:
1. **¿Permiso nuevo?** Definir `<dominio>.<acción>` (ej: `cash_register.bypass_switch_lock`) + default de asignación (owner-only, admin auto, asignable a cualquier rol).
2. **¿Sede vs empresa?** Si muta datos operativos respeta `BranchScope`. Si es config global de empresa no lleva scope. Si es híbrido, declararlo.
3. **¿Interactúa con `branch_users`?** Permisos que requieren acceso a sede (caja, comanda, chats) vs globales (facturación, marca).
4. **¿Aparece en editor del owner?** Si es asignable → catálogo `permissions` + seeders + visible en `UserPermissionsEditor`. Si es bypass automático, no exponer.
5. **¿Bypass owner?** `role.is_system=true` suele bypasear. Confirmar explícito si aplica al permiso nuevo, y por qué.
6. **¿Reglas derivadas en runtime?** "admin con acceso a TODAS las sedes activas → recibe permiso auto". Documentar la lógica y dónde vive (`FeaturePermissionService`).
7. **¿Cómo se valida en backend?** Especificar: middleware (`EnsureCompanyAccess`, `EnsureBranchAccess`, `AllowConsolidatedBranches`), `FeaturePermissionService::userCan*()`, gates, o `authorize()` en FormRequest. No dejar "se valida" en abstracto.
8. **¿Cómo se valida en frontend?** Permisos llegan vía props compartidas Inertia. Mencionar si la UI oculta, tooltip "sin permiso" o redirige.
9. **¿Audita?** Acciones sensibles llaman `AuditService::log(...)` con metadata (permiso, rol actor, sede activa, datos accionables).
10. **¿Cambia catálogo?** Migración o seeder + entrada en docs RBAC.

**Salida esperada — sección "Impacto RBAC" en toda propuesta**:
- Permisos nuevos (nombre, descripción, default).
- Permisos existentes reutilizados.
- Cambios al catálogo (`permissions` table, seeders).
- Bypass owner / asignación automática admin.
- Validación backend (dónde) y frontend (cómo).
- Eventos de auditoría emitidos.

**Mecanismos canónicos a tener en mente**:
- `role.is_system=true` → owner bypass global.
- `branch_users` pivot → acceso a sede operativa.
- `metrics.view_all_branches` → vista consolidada de sedes.
- `EnsureCompanyAccess` → inyecta `active_company_nit`.
- `EnsureBranchAccess` → inyecta `active_branch_id` y valida acceso.
- `AllowConsolidatedBranches` → middleware para `?branch=all`.
- `AuditService::log` → agrega `branch_id` + `actor_active_branch_id` automáticamente.
- `FeaturePermissionService` → resolución de permisos por rol.
- `UserPermissionsEditor` → UI frontend para asignar.

**Excepciones (no aplica sección RBAC, pero menciona "Sin impacto RBAC")**: bug fixes sin nueva acción, cambios visuales/cosméticos, refactors internos sin endpoints nuevos, docs/typos.

---

## 2. Fuentes de verdad compartidas backend/frontend (`backend/constants/`)

Carpeta `backend/constants/` con `.md` que centralizan los modelos canónicos del proyecto: RBAC (permisos, roles, branch isolation, courier mode), contabilidad CO, estados de orden, métodos de pago, middlewares y eventos de auditoría. **Referencia para devs y agentes**, NO buildeable: ningún Vite/Composer/PHPUnit/Docker la consume y ningún tipo/enum se genera de ahí. Existe para evitar drift cuando un permiso, rol, estado o regla contable cambia.

**Contenido — núcleo RBAC** (#201):
- `README.md` — propósito + regla + exclusión de builds.
- `ROLES_SYSTEM.md` — owner/admin/employee (`is_system=true`), bypass, último-owner inviolable.
- `ROLES_TEMPLATES.md` — waiter/cook/cashier (vía `roles:sync-templates`).
- `ROLES_DEMO.md` — Domiciliario/Cocina (solo seeder QA).
- `PERMISSIONS_CATALOG.md` — los ~74 slugs agrupados por dominio, defaults por rol, owner-only, sensibles cross-branch.
- `COURIER_MODE.md` — `FULL_NAV_PERMISSIONS` + `COURIER_PERMISSION` literales en los 2 archivos espejo.
- `BRANCH_RBAC.md` — `BranchScope`, middlewares, owner-only #192.
- `RBAC_CHECKLIST.md` — 8 escenarios accionables para pegar al PR.

**Contenido — operaciones y contabilidad** (#202):
- `ORDER_STATUSES.md` — estados de `orders` (10) y `order_items` (6), transiciones forward-only, `kanban_rank`, `revenue`. Espejo de `config/orders.php` + `lib/order-status.ts`.
- `PAYMENT_METHODS.md` — lista cerrada `cash | card | transfer | refund`, signos (cobros positivos / refunds negativos), `reference` obligatoria por método, conservación DIAN 5/10 años.
- `ACCOUNTING_RULES.md` — resumen estructurado de las reglas contables (decimal(12,2), DB::transaction + lockForUpdate, regímenes IVA 19% / INC 8% / RST, propina separada, refunds como asiento nuevo) con checklist pre-merge para PRs financieros.
- `MIDDLEWARE_MAP.md` — stack de middleware por contexto, aliases canónicos (`jwt`, `company.access`, `branch.access`, `permission:<slug>,<action>`, etc.) y orden lógico recomendado en rutas.
- `AUDIT_EVENTS.md` — catálogo de acciones registradas por `AuditService::log`, convenciones de `action` slug y `data` mínimo reconstructible.
- `FEATURES_INDEX.md` — índice de módulos funcionales por dominio (10 dominios) con cross-ref al wiki, backend/frontend clave y permisos asociados. Portada hacia `docs/wiki/`.

**Cuándo aplica (consultar antes de editar)**: `config/roles.php`, `config/orders.php`, `FeatureSeeder.php`, `PermissionTemplateSeeder.php`, `RestauranteFlexySeeder` (bloques de `seedRoles`), `FeaturePermissionService`, `EnsureFeaturePermission`, `EnsureCompanyAccess`, `EnsureBranchAccess`, `AllowConsolidatedBranches`, `PostLoginRedirect`, `SyncRoleTemplatesCommand`, `OrderController` (closeWithPayment / refund / cancel), `CashRegisterController`, `AuditService`, `PaymentReceipt`, `lib/courier-mode.ts`, `lib/order-status.ts`, `hooks/use-permissions.ts`, `components/role-badge.tsx`, migraciones que toquen `features`/`permission_templates`/`company_roles`/`company_role_permissions`/`user_permission_overrides`/`branch_users`/`payment_receipts`/`orders`.

**Flujo obligatorio** (en este orden):
1. Leer el `.md` correspondiente antes de editar.
2. Editar el código ejecutable (PHP/TS/migrations) — sigue siendo la fuente real.
3. Actualizar el `.md` correspondiente **en el mismo PR**.
4. Pegar el sub-checklist de `RBAC_CHECKLIST.md` al cuerpo del PR y marcarlo fila por fila.

**Regla de drift**: si el `.md` no coincide con el código → **el código gana**. Corregir el `.md` en el mismo PR. Si fue intencional, comentar en el issue origen.

**Excepciones (no requieren consulta)**: cosméticos/typos en UI, refactor interno sin tocar permisos/roles, bug fixes sin afectar RBAC, cambios de labels en `/identities/roles.tsx` que no tocan slugs. Mencionar "sin cambio RBAC" en el PR igualmente.

**Por qué existe**: en #119 hubo que tocar 5 archivos espejo por `deliveries.self_assign` y el último (`courier-mode.ts`) se descubrió en code review. `RestauranteFlexySeeder` hardcodeaba colores distintos a `config/roles.php` (drift descubierto en #201). El wiki público listaba 3 roles cuando hay 6 `role_type` + 2 demo.
