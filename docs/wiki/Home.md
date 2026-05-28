# flexyflow Restaurante — Wiki

> Documentación técnica y funcional de la plataforma SaaS de gestión de restaurantes.
> Última actualización: 2026-05-28.

---

## ¿Qué es flexyflow?

Plataforma multi-empresa y multi-sede para la gestión integral de restaurantes en Colombia. Cubre el ciclo operativo completo: desde el onboarding del negocio, la captura de pedidos por mesa / mostrador / domicilio / WhatsApp, la cocina con KDS, la caja y la facturación electrónica DIAN, hasta el inventario, las compras, los reportes y el programa de fidelización.

Dominios funcionales principales:

- Multi-tenancy por **NIT** y aislamiento por **sede** (`branch_id`).
- RBAC granular: roles de sistema (`owner`/`admin`/`employee`), plantillas operativas (`waiter`/`cook`/`cashier`/`manager`/`accountant`/`marketing`/`inventory_manager`/`supervisor`) y ~82 permisos asignables por dominio.
- Pedidos en tiempo real con tablero Kanban, mesas, caja POS, KDS por estación e impresión térmica de comandas.
- Carta digital programable con menús por sede y consumo público vía QR.
- Cupones, fidelización por puntos y CRM básico de clientes.
- Domicilios con autoasignación de repartidores y modo courier.
- Facturación recurrente del SaaS + facturación electrónica DIAN para los restaurantes.
- Integración con WhatsApp Cloud API (bot de pedidos + chats con clientes + notificaciones).
- Dashboard operativo y métricas con vista consolidada multi-sede.
- PWA con notificaciones push, soporte offline parcial y sincronización.

---

## Stack tecnológico

| Capa | Tecnología |
|------|-----------|
| Backend | Laravel 12 + PHP 8.2 |
| SPA | React 19 + TypeScript + Inertia.js v2 (capa parcial) |
| Build SPA | Vite + Cloudflare Worker (entry `application/frontend/src/spa/main.tsx`) |
| Estilos | Tailwind CSS v4 + Design System v3.1 |
| Base de datos | PostgreSQL |
| Caché y colas | Redis (PDN) / Database (local) |
| Autenticación | Google OAuth + JWT custom (HS256, payload AES-256-CBC) |
| Almacenamiento | S3 (PDN) / local (dev) |
| PDF | DomPDF |
| Email | AWS SES |
| Notificaciones push | Web Push API + VAPID |
| Hosting | AWS (EC2 + ASG + ALB + RDS) |

---

## Mapa funcional por área

### 1. Acceso y multi-tenancy

- [Autenticación](Autenticaci%C3%B3n.md) — Google OAuth, JWT con `active_company_nit` + `active_branch_id`, switch de sede, middleware.
- [Multi-tenancy](Multi-tenancy.md) — Aislamiento por NIT y por sede, `BranchScope`, middlewares (`company.access`, `branch.access`, `branch.consolidate`).
- [Empresas](Empresas.md) — Modelo `companies`, estados (`pending_activation`/`active`/`past_due`/`suspended`/`rejected`/`inactive`), perfil fiscal DIAN, banca y branding.
- [Sucursales](Sucursales.md) — CRUD de sedes (`/company/branches`), soft-archive, `branch_users`, copia de menú, verticalización por sede (#237).
- [Usuarios, Roles y Permisos](Usuarios-Roles-Permisos.md) — Catálogo RBAC, plantillas de rol, invitaciones, editor de permisos.
- [Onboarding](Onboarding.md) — Flujo de enrollment (`/enrollment/*`), verificación de propiedad (#154), primer login.

### 2. Operación diaria

- [Pedidos](Pedidos.md) — Tablero Kanban (`/orders/board`), 10 estados canónicos, `order_items` con ciclo independiente, `closeWithPayment`, `refund`, `cancel`.
- [Caja / POS](Caja-POS.md) — Apertura/cierre de caja (`/orders/cashier`), `cash_register_sessions`, egresos, cobros con `lockForUpdate`, devoluciones, ESC/POS.
- [Mesas](Mesas.md) — Sesiones grupales por mesa (`/orders/tables`, `/orders/table-sessions`), buffer de aprobación, cobro parcial / total, QR de mesa.
- [Cocina (KDS)](Cocina.md) — Kitchen Display System por estación, SLA semáforo, device tokens, layout standalone (#115).
- [Impresoras](Impresoras.md) — Impresoras térmicas, ruteo por categoría, comandas, `CommandTicketService`, `EscposTicketBuilder` (#116).
- [Repartidores](Repartidores.md) — Asignación manual y autoasignación, modo courier (`deliveries.self_assign`), métricas, razones canónicas.
- [Horarios](Horarios.md) — Horarios comerciales base + excepciones por sede.
- [Carrito público](Carrito-Publico.md) — Carrito QR del comensal (`/cart/{jwt}`, `/api/v1/public/table/{qr_token}/*`), JWT corto de sesión.

### 3. Catálogo y promociones

- [Menú](Men%C3%BA.md) — CRUD de categorías e ítems, menús por sede, recetas (BOM), imágenes, menú público QR.
- [Cupones](Cupones.md) — Tipos, alcance (`scope`), validación temporal, single-use con `locked_to_phone`, auto-apply, export PDF.
- [CRM de clientes](CRM-Clientes.md) — Listado y perfil (`/clients`), `contacts`, notas y tags, segmentación heurística.
- [Fidelización por puntos](Fidelizacion-Puntos.md) — `/loyalty/reports`, libro mayor `loyalty_movements`, ganancia idempotente, canje en carrito, job de expiración.
- [Chats con clientes](Chats-Clientes.md) — Conversaciones WhatsApp (`/chats`), asignación por sede, estados de conversación.

### 4. Métricas, reportes y facturación

- [Dashboard y Métricas](Dashboard-M%C3%A9tricas.md) — `/dashboard` y `/company/metrics`, KPIs, heatmaps, caché por TTL, vista consolidada multi-sede.
- [Informes de pedidos](FUNCIONALIDADES_APP.md#5-informes-de-pedidos-companyreports) — `/company/reports`, exportes PDF/CSV (sección de FUNCIONALIDADES_APP).
- [Facturación del SaaS](Facturaci%C3%B3n.md) — Plan único `default`, facturas mensuales, schedule `0 3 1 * *`, política de mora (`past_due → suspended`), `payment-proofs`.
- [Facturación electrónica DIAN](Facturaci%C3%B3n-Electr%C3%B3nica-DIAN.md) — Facturas a clientes finales, CUFE, `DianProviderFactory`, conservación 5/10 años, notas crédito.

### 5. Inventario, compras y proveedores

- [Inventario multi-bodega](Inventario.md) — `warehouses`, `ingredients`, `ingredient_movements`, recetas, consumo automático al cocinar, transferencias, alertas de stock.
- [Compras](Compras.md) — Órdenes de compra con máquina de estados (`draft→pending→received→paid`), recepción de mercancía, notas crédito inmutables.
- [Proveedores](Proveedores.md) — CRUD con soft-archive, `supplier_ingredients`, último costo neto cacheado.

### 6. Recursos humanos

- [Planificador de turnos](Planificador-Turnos.md) — `/planner/week` y `/planner/month` (#182), colaboradores (`employees`), overlap-check, integración con caja.

### 7. Integraciones

- [WhatsApp Bot](WhatsApp-Bot.md) — JWT del bot (`BOT_JWT_TTL=3600s`), endpoints externos `/api/external/*`, sesión de carrito (`CartJwtService`), guard de empresa bloqueada.
- [Email (AWS SES)](EMAIL_SES_SETUP.md) — Configuración de SES, plantillas, alertas.
- [PWA y Push Notifications](PWA-Push-Notifications.md) — Service worker, VAPID, manifest, suscripciones por usuario, digest de inventario.

### 8. Configuración

- [Configuración de empresa](Configuracion-Empresa.md) — `/company/settings`, `/company/preferences`, `/company/dian`, `/company/whatsapp` (Embedded Signup + NaaS + OTP).
- [Configuración personal](Configuracion-Personal.md) — `/settings/profile`, `/settings/appearance`, `/settings/notifications` (#149), `/settings/password` deshabilitado (#231 OAuth-only).
- [Variables de entorno](Variables-de-Entorno.md) — Catálogo completo de `.env` por dominio.

---

## Referencias técnicas transversales

- [Manual funcional completo (FUNCIONALIDADES_APP)](FUNCIONALIDADES_APP.md) — Documento maestro con todos los flujos paso a paso (~7400 líneas).
- [Frontend — Arquitectura](Frontend.md) — Estructura SPA, layouts, componentes, hooks, tokens del DS.
- [Backend — Inventario de archivos](BACKEND_FILES.md) — Catálogo de controllers, services, jobs.
- [Frontend — Inventario de archivos](FRONTEND_FILES.md) — Catálogo de páginas y componentes.
- [Errores API](Errores-API.md) — Códigos HTTP, códigos de aplicación canónicos.
- [Sanitización de inputs](SECURITY_INPUT_HANDLING.md) — Política de saneo en persistencia y escape en render.
- [Guía de contribución](Gu%C3%ADa-de-Contribuci%C3%B3n.md) — Flujo de trabajo, Definition of Done, checklist de PR.

---

## Convenciones del wiki

Cada página comienza con un encabezado canónico:

```
> Estado: Estable | En desarrollo | Deprecado
> Versión API: v1
> Owner: equipo de plataforma
```

Los endpoints se documentan en tablas con el formato:

| Método | Ruta | Auth / Middleware | Permiso | Descripción |
|--------|------|-------------------|---------|-------------|

Los permisos RBAC se documentan en matriz por rol con notación `RCUD` (Read, Create, Update, Delete). Los modelos de datos se exponen en tablas con tabla, campos clave y notas.

Los ejemplos de request / response usan bloques `http` y `json` con datos representativos pero sin información sensible.

---

## Mantenimiento del wiki

Los archivos `.md` viven bajo `docs/wiki/` en el repositorio principal y se versionan junto con el código. La publicación al wiki externo de GitHub (`{repo}.wiki.git`) se realiza con `git push wiki main` desde un script de operación (CI cuando `WIKI_AUTO_UPDATE=true`).

Cuando una funcionalidad cambia en el código, la página correspondiente debe actualizarse en el **mismo PR**. La regla de drift es estricta: si la documentación no coincide con el código, **el código gana** — actualizá la documentación en el mismo cambio.

Las fuentes canónicas para drift de RBAC, estados de orden, métodos de pago, middlewares, eventos de auditoría y catálogos viven en `application/backend/constants/*.md`. Consultá esos archivos antes de modificar permisos, roles, estados o reglas contables.
