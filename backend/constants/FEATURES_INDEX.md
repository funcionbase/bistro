# FEATURES_INDEX — Índice de módulos funcionales

> **Antes de añadir un módulo funcional nuevo, lee este archivo.**
> **Después de añadir/modificar un módulo, actualiza este archivo + el wiki + los
> archivos cross-referenciados (BACKEND_FILES, FRONTEND_FILES) en el mismo PR.**

## Archivos que deben quedar sincronizados

- [ ] `docs/wiki/FUNCIONALIDADES_APP.md` — manual narrativo del producto
- [ ] `docs/wiki/BACKEND_FILES.md` — mapa de archivos backend
- [ ] `docs/wiki/FRONTEND_FILES.md` — mapa de archivos frontend
- [ ] Wiki específico del módulo (`docs/wiki/<X>.md`)
- [ ] `bistro/backend/constants/PERMISSIONS_CATALOG.md` — permisos asociados
- [ ] `bistro/backend/constants/MIDDLEWARE_MAP.md` — middleware aplicable

---

## Mapa por dominio

### 1. Operación diaria — Pedidos y mesas

| Módulo | Wiki | Backend clave | Frontend clave | Permisos |
|---|---|---|---|---|
| Pedidos (kanban, KDS) | [Pedidos](../../docs/wiki/Pedidos.md) | `OrderController`, `OrderSyncController` | `pages/Orders/Kanban.tsx`, `pages/Orders/Detail.tsx` | `orders.*` |
| Cocina (KDS por estación #115) | [`KDS_STATIONS.md`](./KDS_STATIONS.md) | `KdsController`, `KdsStationController`, `KdsDeviceTokenController`, `KdsTicketService`, `KdsDeviceTokenService` | `pages/kds/index.tsx` (consolidado), `pages/kds/station.tsx` (standalone), `pages/company/kds.tsx` (settings) | `kds.read`, `kds.update`, `kds_stations.read`, `kds_stations.create`, `kds_stations.update`, `kds_stations.delete` |
| Pago y caja | [Facturación](../../docs/wiki/Facturación.md) | `OrderController::closeWithPayment`, `CashRegisterController` | `Orders/PaymentModal.tsx` | `orders.update`, `reports.read`, `cash_register.bypass_switch_lock` |
| Mesa con QR (#191) | (cross-ref Pedidos) | `OrderController` (pending_approval flow) | pantallas de mesero, KDS | `orders.read/update` (waiter/cook/cashier) |
| Menú y disponibilidad | [Menú](../../docs/wiki/Menú.md) | `MenuPermissionService` + controllers | `pages/Menu/*` | `menu.*` |
| Cupones | [Cupones](../../docs/wiki/Cupones.md) | `CouponController`, `CouponRedemptionController` | `pages/Coupons/*` | `coupons.*` |
| Horarios | [Horarios](../../docs/wiki/Horarios.md) | `BusinessHoursController` | `pages/Settings/Hours.tsx` | `hours.*` |
| Entregas / Domiciliarios | [Repartidores](../../docs/wiki/Repartidores.md) | `DeliveryController`, `DeliveryStatusController` | `pages/Deliveries/*`, `pages/MyDeliveries.tsx` | `deliveries.*`, `deliveries.self_assign` |

### 2. Multi-tenant + multi-sede

| Módulo | Wiki | Backend clave | Frontend clave | Permisos |
|---|---|---|---|---|
| Empresas | [Empresas](../../docs/wiki/Empresas.md) | `CompanyController`, `EnsureCompanyAccess` | `pages/Settings/Company.tsx` | `company.update` |
| Sedes (#117) | (cross-ref Empresas) | controllers de branches, `EnsureBranchAccess`, `AllowConsolidatedBranches` | `BranchFilterTabs`, `pages/Settings/Branches.tsx` | `branches.*`, `metrics.view_all_branches` |
| Verticales por sede (#237) | [`BUSINESS_TYPES.md`](BUSINESS_TYPES.md) | `BusinessType`, `PrepArea`, `BusinessCapabilityService`, `BusinessLabelService`, `EnsureBusinessCapability`, `BusinessContextController` | `BusinessProvider`, `useBusinessCapability`, `BusinessGate`, `BusinessTypeSelector` | (reusa `branches.manage`) |
| Aislamiento de sede (#192) | (cross-ref Empresas) | `BranchScope`, middleware | tooltips, ocultar acciones | `chats.reassign_branch`, `cash_register.bypass_switch_lock`, `inventory.transfer_cross_branch` |
| Usuarios, roles, permisos | [Usuarios-Roles-Permisos](../../docs/wiki/Usuarios-Roles-Permisos.md) | `UserRoleController`, `FeaturePermissionService`, `EnsureFeaturePermission` | `pages/Identities/*`, `UserPermissionsEditor.tsx`, `RoleBadge.tsx` | `users.*`, `roles.*` |

### 3. Inventario y compras

| Módulo | Wiki | Backend clave | Frontend clave | Permisos |
|---|---|---|---|---|
| Inventario | (BACKEND_FILES) | controllers inventory | `pages/Inventory/*` | `inventory.*`, `inventory.transfer_cross_branch` |
| Bodegas (#120) | (BACKEND_FILES) | controllers warehouses | `pages/Inventory/Warehouses.tsx` | `warehouses.manage` |
| Proveedores | (BACKEND_FILES) | controllers suppliers | `pages/Suppliers/*` | `suppliers.*` |
| Órdenes de compra | (BACKEND_FILES) | controllers purchases (`receive`, `pay`, anulación) | `pages/Purchases/*` | `purchases.*` |

### 4. Chats e integraciones

| Módulo | Wiki | Backend clave | Frontend clave | Permisos |
|---|---|---|---|---|
| Chats WhatsApp | [WhatsApp-Bot](../../docs/wiki/WhatsApp-Bot.md) | `ChatController` | `pages/Chats/*` | `chats.*`, `chats.reassign_branch` |
| Conexión WhatsApp Cloud | (cross-ref WhatsApp-Bot) | `WhatsappAccountPolicy`, controllers WA | `pages/Settings/Whatsapp.tsx` | `whatsapp.read/connect/update/swap_phone/disconnect` |

### 5. CRM y fidelización

| Módulo | Wiki | Backend clave | Frontend clave | Permisos |
|---|---|---|---|---|
| Clientes (CRM #123) | (cross-ref FUNCIONALIDADES_APP) | `ClientController` | `pages/Clients/*` | `clients.*` |
| Fidelización (#122) | (cross-ref FUNCIONALIDADES_APP) | `LoyaltyController`, `LoyaltyAccountPolicy`, `LoyaltyReportController` | `pages/Loyalty/*` | `loyalty.read/update` |

### 6. Colaboradores y planificador (#182)

| Módulo | Wiki | Backend clave | Frontend clave | Permisos |
|---|---|---|---|---|
| Colaboradores | (BACKEND_FILES) | `EmployeeController`, `EmployeePositionController` | `pages/Employees/*` | `employees.*`, `employees.view_salary` |
| Planificador de turnos | (BACKEND_FILES) | `ShiftController` | `pages/Scheduler.tsx`, `pages/Me/Agenda.tsx` | `shifts.read/manage/suggest` |
| Reportes de jornada | (BACKEND_FILES) | `WorkforceReportController`, `WorkforceSettingsController` | `pages/Workforce/*` | `workforce.reports`, `workforce.settings` |

### 7. Reportes y métricas

| Módulo | Wiki | Backend clave | Frontend clave | Permisos |
|---|---|---|---|---|
| Dashboard | [Dashboard-Métricas](../../docs/wiki/Dashboard-Métricas.md) | `DashboardController`, `ReportsPermissionService` | `pages/Dashboard.tsx`, `BranchFilterTabs` | `reports.read`, `metrics.view_all_branches` |
| Exports PDF | (cross-ref Dashboard) | `PdfExportController` | flujo de descarga | `reports.read` |
| Alertas | (BACKEND_FILES) | `AlertController`, `AlertRuleController`, `AlertEventPolicy` | `pages/Alerts/*` | (sin permiso dedicado — owner/admin only por policy) |

### 8. Autenticación y onboarding

| Módulo | Wiki | Backend clave | Frontend clave | Permisos |
|---|---|---|---|---|
| Login Google OAuth | [Autenticación](../../docs/wiki/Autenticación.md) | Socialite + JWT custom | `pages/Login.tsx` | — |
| Onboarding empresa | (cross-ref Autenticación) | flujo create-from-template, registro | `pages/Onboarding/*` | — |
| Documentos legales | (CLAUDE.md §11) | `config/legal.php` (URLs wiki externo), `user_acceptances` (registro append-only sin snapshot) | enrollment links `target="_blank"` a `bootstrap.legalUrls` | — |

### 9. Seguridad y auditoría

| Módulo | Wiki | Backend clave | Frontend clave | Permisos |
|---|---|---|---|---|
| Sanitización de inputs | [SECURITY_INPUT_HANDLING](../../docs/wiki/SECURITY_INPUT_HANDLING.md) | `NormalizeStrings`, `SanitizesInput` trait, `SafePlainText` rule | `lib/input-sanitize.ts`, `SanitizedInput` primitive | — |
| Audit log | (cross-ref BACKEND_FILES) | `AuditService::log`, tabla `audit_logs` | — | — |
| Headers de seguridad | (cross-ref Variables-de-Entorno) | `SecurityHeaders` middleware | — | — |

### 10. PWA y experiencia móvil

| Módulo | Wiki | Backend clave | Frontend clave | Permisos |
|---|---|---|---|---|
| PWA shell | [Frontend](../../docs/wiki/Frontend.md) | Vite manifest, service worker | `app.tsx`, install prompts | — |
| Mobile-first /my-deliveries (#119) | (cross-ref Repartidores) | `DeliveryController` filters | `pages/MyDeliveries.tsx` | `deliveries.self_assign` |

---

## Errores y operaciones cross-cutting

- **Errores API**: ver [Errores-API](../../docs/wiki/Errores-API.md). 401/403/422/500 con shape consistente.
- **Variables de entorno**: ver [Variables-de-Entorno](../../docs/wiki/Variables-de-Entorno.md). `.env.example` es referencia.
- **Guía de contribución**: ver [Guía-de-Contribución](../../docs/wiki/Guía-de-Contribución.md).
- **Home**: ver [Home](../../docs/wiki/Home.md) para entrada al wiki.

---

## Cómo añadir un módulo funcional nuevo

1. Crear el wiki del módulo en `docs/wiki/<Nombre>.md` (prosa narrativa, capturas, flujo del usuario).
2. Si introduce permisos: actualizar `bistro/backend/constants/PERMISSIONS_CATALOG.md`, `FeatureSeeder.php`, `PermissionTemplateSeeder.php`.
3. Si introduce middleware: actualizar `bistro/backend/constants/MIDDLEWARE_MAP.md`.
4. Si introduce auditoría: actualizar `bistro/backend/constants/AUDIT_EVENTS.md`.
5. Si toca contabilidad: actualizar `bistro/backend/constants/ACCOUNTING_RULES.md` + `CLAUDE.md` §12 + cross-ref a `PAYMENT_METHODS.md`.
6. Si toca multi-tenancy: actualizar `bistro/backend/constants/BRANCH_RBAC.md`.
7. Agregar fila al dominio correspondiente en este `.md`. Si no encaja en ninguno, abrir sección nueva.
8. Actualizar `docs/wiki/FUNCIONALIDADES_APP.md`, `BACKEND_FILES.md`, `FRONTEND_FILES.md`.

---

## Histórico / deprecaciones

- _(vacío — al cierre de HU #202)_

---

## Referencias cruzadas

- `docs/wiki/Home.md` — entrada principal al wiki.
- `docs/wiki/FUNCIONALIDADES_APP.md` — manual funcional consolidado.
- `bistro/backend/constants/PERMISSIONS_CATALOG.md` — permisos por dominio.
- `CLAUDE.md` raíz §9 "Documentación viva".
