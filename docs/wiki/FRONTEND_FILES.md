# FRONTEND_FILES.md — Inventario Técnico Frontend

> Referencia técnica completa del frontend React 19 + TypeScript + React Router v7 + TanStack Query v5 + Tailwind v4.
> Documento canónico para desarrollo, troubleshooting y manuales operativos.
> Cubre: páginas, componentes, hooks, layouts, librerías, tipos, autenticación, polling, RBAC y limitaciones conocidas.

> ⚠️ **Corrección de arquitectura (post #220):** el frontend es un **SPA standalone con React Router v7 + TanStack React Query** en `bistro/frontend/src/`; **NO usa Inertia** (`@inertiajs/react` no está instalado). Las menciones a "Inertia", "deferred props Inertia", "Inertia shared props" y las rutas `resources/js/...` de este documento son **legacy** del monolito previo y están pendientes de limpieza. Hoy: rutas en `src/spa/router.tsx` (lazy + Suspense), datos vía `useQuery`/`apiFetch`, contexto global vía `GET /api/v1/bootstrap` → `SpaSharedDataBridge`/`useBootstrap()`. Ver `Frontend.md` para los patrones vigentes.

---

## Stack técnico

| Componente | Versión | Notas |
|-----------|---------|-------|
| React | 19 | Sólo function components + hooks; sin class components |
| TypeScript | 5.x | strict mode |
| React Router | v7 | SPA client-side; rutas lazy + Suspense en `src/spa/router.tsx` |
| TanStack React Query | v5 | Carga de datos, cache y polling (`src/lib/query-client.ts`) |
| Tailwind CSS | v4 | Utility-first; tokens en `tailwind.config.ts` |
| Vite | — | Bundler; entry `bistro/frontend/src/spa/main.tsx` |
| @dnd-kit | — | Drag-and-drop (menú, kanban) |
| @tanstack/react-table | — | Tablas (parcial) |
| lucide-react | — | Iconos |
| Radix UI | varios | Primitivas accesibles (Dialog, Select, DropdownMenu, ...) |
| react-markdown | — | Render de TOS, política, contrato |
| date-fns | — | Manipulación de fechas |
| Ziggy (`tightenco/ziggy`) | v2 | `route('name')` en JS; archivo `resources/js/ziggy.js` autogenerado |
| ESLint | v9 | Flat config |
| Prettier | v3 | Format |

---

## Resumen del inventario

| Categoría | Conteo | Ubicación |
|-----------|--------|-----------|
| Páginas | 60 | `resources/js/pages/` |
| Componentes | 156 | `resources/js/components/` (incluye `alerts/`, `branch-switcher`, `cash-register/`, `clients/`, `coupons/`, `company/`, `loyalty-badge`, `offline/`, `orders/`, `printing/`, `pwa/`, `reports/`, además de `ui/`, `dashboard/`, `menu/`, `metrics/`, `deliveries/`, `hours/`, `billing/`, `chats/`, `whatsapp/`) |
| Hooks | 44 | `resources/js/hooks/` (`.ts` + `.tsx`) — incluye `use-payment-methods.ts` (#203), `use-employee-statuses.ts` (#204) |
| Layouts | 8 | `resources/js/layouts/` |
| Librerías utilitarias | 15 | `resources/js/lib/` |
| Type files | 9 | `resources/js/types/` (`index`, `billing`, `business-hours`, `coupon`, `inventory`, `purchases`, `recipes`, `suppliers`, `vite-env.d`) |

---

## Catálogos canónicos compartidos

Antes de declarar uniones de strings literales para cualquier estado, tipo, método o acción RBAC, **consultar `bistro/backend/constants/`** (fuente única documental — ver `BACKEND_FILES.md` para el inventario completo). Si el catálogo se sirve desde backend, leerlo vía Inertia shared props en lugar de hardcodearlo.

| Catálogo | Shared prop | Hook frontend | Fallback embebido |
|---|---|---|---|
| Estados de orden | `orderStatuses` | `useOrderStatuses()` | `lib/order-status.ts` (`ORDER_STATUS_FALLBACK`) |
| Métodos de pago (#203) | `paymentMethods` | `usePaymentMethods()` | `hooks/use-payment-methods.ts` (`PAYMENT_METHODS_FALLBACK`) |
| Acciones RBAC (#203) | `rbacActions` | `usePage().props.rbacActions` | `permissions-matrix.tsx` (`ACTIONS_FALLBACK`) |
| Vinculation status (#204) | `employeeStatuses` | `useEmployeeStatuses()` | `hooks/use-employee-statuses.ts` (`EMPLOYEE_STATUSES_FALLBACK`) |
| Company status (#205) | n/a — viaja en `activeCompany.status` | helpers de `@/lib/company-status` (`companyStatusLabel`, `companyStatusBadgeVariant`, `isVerified`, `isFullyBlocked`, etc.) | `lib/company-status.ts` (`COMPANY_STATUS_FALLBACK`) |

Tipos canónicos en `resources/js/types/index.ts`:

- `OrderStatus`, `OrderStatusCategory`, `OrderStatusesConfig`
- `PaymentMethod`, `PaymentReceiptMethod`, `PaymentMethodsConfig`
- `UserStatus`, `DeliveryReason`, `DeliveryRowReason`, `DELIVERY_REASON_LABELS`
- `RbacActionKey`, `RbacActionDescriptor`
- `EmployeeStatus`, `EmployeeStatusBadge`, `EmployeeStatusesConfig`

Tipos canónicos en `resources/js/lib/company-status.ts` (no en `types/`):

- `CompanyStatus`, `CompanyStatusBadgeVariant`, `CompanyStatusCatalog`

---

## Variables de entorno (`VITE_*`)

| Variable | Default | Descripción |
|----------|---------|-------------|
| `VITE_APP_NAME` | flexyflow | `<title>` y branding |
| `VITE_API_URL` | `/api/v1` | Base URL para llamadas REST |
| `VITE_PUSHER_APP_KEY` | — | WebSocket Pusher (futuro) |
| `VITE_PUSHER_HOST` | — | Pusher |
| `VITE_PUSHER_PORT` | — | Pusher |
| `VITE_PUSHER_SCHEME` | http/https | Pusher |
| `VITE_PUSHER_APP_CLUSTER` | — | Pusher |

Sólo las variables prefijadas con `VITE_` son accesibles en el cliente (`import.meta.env`).

---

## Niveles de autenticación

```
Público
  └─ welcome, login, register, forgot/reset-password

Autenticado (sesión Laravel via Inertia shared props)
  └─ verify-email, confirm-password, settings/*, /me, enrollment/*

Autenticado + JWT (HttpOnly cookie + Bearer fallback)
  └─ dashboard, menu/*, identities/*, orders/*, deliveries/*,
     hours, coupons/*, chats, billing, company/*, metrics
```

### Flujo del token JWT

1. Backend devuelve un marker opaco (`'__authenticated__'`) en los props compartidos de Inertia tras login. **El JWT real nunca llega al JS** — vive en cookie HttpOnly `flexyflow_jwt`.
2. `app.tsx` llama a `setToken()` en `lib/token.ts` para sincronizar el marker.
3. `useToken()` suscribe componentes a cambios.
4. `apiFetch()` en `lib/api.ts`:
   - Hace request con `credentials: 'include'` (cookie viaja automáticamente).
   - Para back-compat con tokens legacy en localStorage, también inyecta `Authorization: Bearer <token>` y `?token=` query.
   - Si la respuesta incluye `X-Cookie-Migrated: 1`, llama a `markCookieMigrated()` y limpia el bearer.
   - Si la respuesta es 401 con mensaje de "revoc"/"invalid"/"expired", limpia el token y redirige a `/`.
5. Auto-refresh: si `exp - now < 300s`, el backend reissue automáticamente y devuelve `Set-Cookie` con el token nuevo + header `X-Refresh-Token`.
6. Cambio de empresa (`POST /api/v1/auth/select-company`): emite nuevo JWT con `active_company_nit` distinto y re-renderiza toda la app.

---

## PÁGINAS (60)

### Autenticación (`pages/auth/`, 7 páginas)

| Archivo | URL | Layout | APIs | Notas |
|---------|-----|--------|------|-------|
| `welcome.tsx` | `/` | sin layout | — | Landing pública estilo homerun.co en una pantalla sin scroll (fv1.46.0): nav con funcionalidades clave (→ manual) + "Iniciar sesión"/"Agendar demo"/"Prueba gratis" (`Button asChild`), hero 2 columnas con collage SVG (`public/images/landing/hero-collage.svg`) y marquesina infinita de restaurantes locales (keyframes `logo-marquee`, wordmarks + logos reales en `public/images/landing/`). Si autenticado, CTA "Ir al panel". SEO (fv1.47.0): meta/OG/JSON-LD estáticos en `index.html`, sitemap con landing, robots allow-by-default (bots + LLMs), `llms.txt`, og-image. Intro de carga en el shell (`#intro-shell` en index.html): cortina azul en `/` y verde tras seleccionar empresa (flag `ff.intro` vía `lib/intro.ts`; salida al responder bootstrap, mínimo 1.1s, fallback 4s, reduced-motion la salta) |
| `login.tsx` | `/login` | `AuthHeroLayout` | `POST /api/v1/auth/login` | Acceso dual: form email+password + divisor "o" + `GoogleAuthButton`. La respuesta `{redirect}` (cookie JWT ya seteada) decide destino: `/verify-email`, `/enrollment/*`, dashboard o company-selector. Lee `?verified=1`, `?reset=1`, `?verify_error=1` |
| `register.tsx` | `/register` | `AuthHeroLayout` | `POST /api/v1/auth/register` | Nombres/apellidos (sanitizados §5), email, password ×2 + honeypot `website` oculto + alternativa Google. Al crear → `/verify-email` (JWT en cookie). Correo duplicado → 422 con mensaje "entra con Google o recupera tu contraseña" |
| `forgot-password.tsx` | `/forgot-password` | `AuthHeroLayout` | `POST /api/v1/auth/forgot-password` | Respuesta siempre genérica (anti-enumeración). También es cómo una cuenta Google FIJA contraseña (acceso dual) |
| `reset-password.tsx` | `/reset-password/:token` | `AuthHeroLayout` | `POST /api/v1/auth/reset-password` | `?email=` precargado del enlace. El reset marca el correo verificado (probó posesión) → `/login?reset=1` |
| `verify-email.tsx` | `/verify-email` | `AuthHeroLayout` | `GET /api/v1/auth/verification/status` (poll 5s + focus), `POST .../resend` | Pantalla "revisa tu correo" del registro por email. Cuando el poll ve verified → avanza sola a `/enrollment/user` (continuidad). Sin JWT → `/login`. Reenvío limitado 3/10min server-side |
| `confirm-password.tsx` | `/confirm-password` | `GoogleOnlyAuthGate` | — | Único uso restante del gate HU #231 (re-auth sensible, fuera del alcance del acceso dual) |
| `company-selector.tsx` | `/auth/company-selector` | sin layout | `POST /api/v1/auth/select-company` | Multi-empresa: lista companies del JWT, llama `setToken()` con nuevo JWT; botón "Cerrar sesión" (`useLogout`) junto a "Registrar otra empresa" |
| `branch-selector.tsx` | `/auth/branch-selector` | sin layout | `POST /api/v1/auth/switch-branch` | Multi-sede (#117): tarjetas de sedes accesibles. Persiste última en `localStorage['flexyflow.last_branch_id:<nit>']` y auto-selecciona si sigue accesible. Redirige a `/dashboard` |

### Manual de usuario (`pages/manual/` + `data/manual/`, fv1.46.0)

Contenido en **markdown**: `src/data/manual/<slug>.md` (frontmatter: `title`, `description`, `metaTitle`, `metaDescription`, `section`, `readingTime`, `lastUpdated`) servido por la página genérica `pages/manual/page.tsx` (ruta `/manual/:slug`, `import.meta.glob ?raw`, slug inexistente → redirect al índice). Renderiza con `components/ui/markdown.tsx` (remark-gfm + rehype-raw + rehype-sanitize con schema extendido: `div.callout-*`, `kbd`, `figure`/`img`; links internos → `<Link>` SPA, `![alt](src "caption")` → figure con caption). Cada página abre con una ilustración SVG del panel (`public/images/manual/<slug>.svg`, abstractas para no desactualizarse con la UI). Quedan como JSX: `index.tsx` (grids), `faq.tsx` (acordeón `details`) y `legal-contrato.tsx` (ya era md). `manual-layout.tsx`: diseño editorial estilo homerun — H1 display `font-brand`, rail derecho xl con meta + TOC autogenerado desde los `h2`, TOC mobile en `<details>`, CTA "Entrar al panel" también en el sidebar móvil, prev/next (`PagerLink`).

### Enrollment (`pages/enrollment/`, 2 páginas)

| Archivo | URL | APIs | Notas |
|---------|-----|------|-------|
| `user.tsx` | `/enrollment/user` | `POST /api/v1/enrollment/user` | Wizard 3 pasos: datos personales (nombre, apellido, cédula) → aceptación TOS+privacidad (links a `flexyflow.co` en pestaña nueva, URLs desde `useBootstrap().data.legalUrls`) → vinculación |
| `company.tsx` | `/enrollment/company` | `POST /api/v1/enrollment/company` | Wizard 2 pasos: contrato (link a `/legal/contract` en pestaña nueva, URL desde `bootstrap.legalUrls.contract`) → datos empresa (NIT, nombre, banco, QR). Upload QR (PNG/JPG, máx 5 MB). `availableBanks` viene del bootstrap |

### Dashboard, Métricas, Reportes

| Archivo | URL | Permiso (gate web) | APIs | Notas |
|---------|-----|---------------------|------|-------|
| `dashboard.tsx` | `/dashboard` | `reports.read` | Deferred props: `summary`, `heatmap`, `abandonment`, `deliveries`. Polling REST: `GET /api/v1/metrics/orders/active` (30s) | Hooks: `usePoll` (Inertia, 60s para reload deferred), `useWidgetFetch` (active orders 30s), `usePeriodFilter`. KPIs + heatmap + abandono + entregas. Skeletons animados |
| `metrics/index.tsx` | `/company/metrics` | `reports.read` (gate web) | `GET /api/v1/metrics/{summary,kpis/today,orders/active,orders/heatmap,orders/heatmap/weekly,items/top,dishes/ranking,dishes/margin,carts/abandonment,activity/heatmap}` | Filtros período (today/week/month/custom). Live toggle 60s con auto-off 5min. Export PDF: `POST /api/v1/exports/metrics/pdf`. Panel "Margen por plato" (`dish-margin-panel.tsx`) lista platos con costo registrado y muestra margen % con semáforo. Breadcrumb: Dashboard › Mi Empresa › Métricas |
| `reports/index.tsx` | `/company/reports` | `reports.read` (gate web) | `GET /api/v1/reports/orders` (paginado), `POST /api/v1/exports/orders/pdf`, `GET .../orders/csv` | Lista paginada + summary (total, exitosos, cancelados, abandonados, ingresos). Filtros: período (daily/weekly/monthly/custom), estado (8 valores: Pendiente, En cocina, Para entrega, En domicilio, Completado, Exitoso, Cancelado, Abandonado). Export PDF respeta período + estado seleccionados (usa `periodRange` ya resuelto por backend). Sin uploads |

### Pedidos (`pages/orders/` + `pages/caja/`)

| Archivo | URL | Permiso (gate web) | APIs | Notas |
|---------|-----|---------------------|------|-------|
| `orders/board.tsx` | `/orders/board` | `orders.read` (vía API en hooks) | `GET /api/v1/orders` (5s), `PATCH /api/v1/orders/{id}/status`, `POST /api/v1/deliveries/{id}/reassign`, `POST /api/v1/orders/{id}/assign-courier` | Tablero kanban 5 columnas: Pendientes, En Cocina, Para Entrega, En Domicilio, Completado. Drag-and-drop entre columnas (dnd-kit). Animación drop-bounce. Mobile (v1.40.0, patrón KDS): chips de estado con contador siempre visibles + botón "→ siguiente estado" en cada card (forward-only, salta in_transit para no-domicilio). Hooks: `useOrders`, `useCourierAssignment`. Cálculo de total: server-side; el frontend no inyecta precios. **`completed`**: permitido sin cobro desde el drag, el modal de detalle y el avance mobile (decisión abc270d3/b39f3320: completed = entrega operativa, cobro independiente vía closeWithPayment). Hasta fv1.43.2 el modal/mobile lo excluían — remanente del gate BUG-020 revertido que dejaba las órdenes `ready` sin camino a Completado en mobile, donde no hay drag (fix fv1.43.3). El modal y el avance mobile excluyen `in_transit` para no-domicilios (mismo gate del drag; el backend lo valida server-side desde bv1.30.3). |
| `caja/index.tsx` | `/orders/cashier` | `orders.read` (gate web), `orders.create` para enviar | `GET /api/v1/menus`, `POST /api/v1/orders`, `GET /api/v1/orders/tables`, `POST /api/v1/cart/apply-coupon`, `GET/POST /api/v1/cash-register/*` | POS: 3 tipos (Mesa, Domicilio, Para llevar). **Envuelto en `<CashRegisterPanel>`**: si caja cerrada, pantalla de apertura con efectivo inicial. Selector de mesa basado en `localStorage[tables.grid_size]` con bloqueo de mesas ocupadas. Preview tributario con `tax_rate` por ítem (override en menú) y aggregator `lib/tax.ts`. Input de cupón con validación en tiempo real (`useCouponValidation`); descuento aplicado reduce base gravable. Acepta `?table=N` para precargar mesa desde `/orders/tables` |
| `orders/tables/index.tsx` | `/orders/tables` | `orders.read` (gate web) | `GET /api/v1/orders/tables` (8s polling), `POST /api/v1/orders/{id}/items`, `POST /api/v1/orders/{id}/close-with-payment`, `PATCH /api/v1/orders/{id}/status`, `GET /api/v1/menus`, `GET /api/v1/company` | Grid de mesas (cantidad configurable, persistida en `localStorage[tables.grid_size]`, default 12). **Envuelto en `<CashRegisterPanel>`**. Disponibles vs ocupadas según órdenes `order_type=table` abiertas. Click en disponible → caja con `?table=N`. Click en ocupada → modal con detalle (items, desglose subtotal/tax/total, estado). Botón "Agregar productos" (preview con tax por ítem). Botón "Cerrar y cobrar" → modal con método (cash/card/transfer), input propina opcional con sugerencias 10/15/20%, QR de la empresa para transferencias, devuelta calculada contra `total + tip`. Issue #89 |
| `deliveries/index.tsx` | `/orders/deliveries` | `deliveries.read` (gate web) | `GET /api/v1/deliveries`, `PATCH .../complete`, `POST .../reassign`, `DELETE .../{id}`, `POST /api/v1/orders/{id}/cancel`, `POST /api/v1/orders/{id}/refund` | Renombrado a "Pedidos del día". Lista paginada con filtros. Modal detalle ahora incluye: cancelación (sin pago) y devoluciones parciales o totales (con pago). Para card/transfer exige número de comprobante de devolución. Refund parciales acumulan; orden pasa a `refunded` solo cuando remanente = 0 |
| `orders/show.tsx` | `/orders/{id}` | `orders.read` (acciones gateadas por `orders.update`/`orders.delete`) | `GET /api/v1/orders/{id}`, `GET /api/v1/table-sessions/{sid}` + `caja/table-sessions/{sid}` (+timeline, auto-refresh 10s data-only), `POST /api/v1/orders/{id}/items`, aprobación/cancelación de items, pay-partial/pay-all/refund-item, close-with-payment | Detalle unificado de orden. Para sesiones QR: comensales, tandas por aprobar, **Pedidos de la mesa por tanda** (v1.41.0: card por orden aprobada con estado, items con estado individual, total por tanda y total acumulado de la mesa — reemplaza los tabs planos por estado), cobro por comensal, timeline. **Auto-refresh 10s** de orden + sesión con la pestaña visible (v1.39.8); se detiene con orden terminal y sesión cerrada; botón "Refrescar" fuerza fetch inmediato. **Botón "Agregar productos"** (v1.39.7) para órdenes de mesa abiertas — tradicionales y de sesión QR (reutiliza `useAddItems` + `AddItemsSheet`); oculto en terminales, buffer `pending_approval` y sesión cerrada. Destino del click en mesa "En sesión" desde `/orders/tables` |

### Menú (`pages/menu/`)

| Archivo | URL | Permiso | APIs | Notas |
|---------|-----|---------|------|-------|
| `index.tsx` | `/menu` | `menu.read` | `GET /api/v1/menus`, `POST /api/v1/menus`, `PATCH /api/v1/menus/{id}/activate`, `DELETE /api/v1/menus/{id}`, `POST /api/v1/menus/{id}/duplicate`, `PATCH /api/v1/menus/{id}/schedule`, `POST /api/v1/menus/sync-schedule` | Cuadrícula de menús. Crear/duplicar/publicar/programar/eliminar. Sin polling automático: botón "Actualizar" |
| `show.tsx` | `/menu/{id}` | `menu.read` (acciones gateadas por `canCreate/canUpdate/canDelete`) | CRUD categorías + ítems, `PATCH /menus/{id}/categories/{cat}/items/{item}/availability`, `POST /menus/{id}/items/{item}/image` | Editor 2 paneles. Drag-drop con `useMenuDrag` (debounce 300ms). Upload de imagen (JPG/PNG, máx 2 MB) con preview. Toggle disponibilidad optimista |
| `public.tsx` | `/menus/{nit}` y `/menus` (`?table=`, `?branch=`, `?cart=`) | **Pública (sin auth)** | `GET /api/v1/public/menu/{nit}` (incluye `restaurant` block con branding), `POST /api/v1/public/menu/{nit}/scan`, `GET /api/v1/public/cart-resolve/{token}` (`?cart=`: sesión de carta desde /chats — resuelve sede, prellena nombre/celular y liga el pedido al chat vía `cart_token` en el POST del checkout) | Página destino del QR fijo del restaurante (issue #95). Layout sin sidebar. Al recibir `nit` por URL, lo guarda en `localStorage['menu_last_nit']` y reemplaza la URL a `/menus/` via `history.replaceState` — el cliente ve el NIT por un instante y luego una URL limpia. En `/menus` (sin NIT), resuelve desde localStorage; si tampoco hay, muestra empty state "Escanea el QR de un restaurante". Branding (logo/color/nombre) se hidrata desde la respuesta del API (no como prop server-side, para soportar la ruta sin NIT). Maneja 423 (cerrado/horario, caja) y 404 (sin menú activo). Lee `?table=N` y persiste en `sessionStorage['cart:preselected_table:'+nit]` para preselección de checkout. Telemetría: 1 POST por sesión a `/scan` con `keepalive: true`; con QR opaco (`?branch=`/`?table=token`) espera la resolución del token y envía `branch_id` + número de mesa real. Flujo `?branch={menu_qr_token}`: carrito local (botón "+" por plato) + checkout (para llevar/domicilio, nombre + celular, dirección + barrio con aviso "solo domicilios en {ciudad de la sede}", fee de envío de `restaurant.delivery_fee`) → `POST /api/v1/public/branch/{token}/orders` → confirmación "pendiente de aprobación". Token opaco (`?table=`/`?branch=`) que resuelve 404 (QR inválido o mesa/sede archivada) y sin `nit` en URL → empty state "Escanea el QR" en lugar del menú stale de la última empresa visitada en localStorage (v1.42.1); errores de red conservan el fallback. UX móvil (v1.43.1): banner de sesión, CTA sticky y diálogo de unirse solo con `state.kind === 'menu'` (con 423 cerrado/caja no se empuja a pedir); `pb-28` cuando hay barra fija para no tapar los últimos platos/footer; items `available=false` sin botón "+" en el pedido de sede; hint inline de celular inválido (3XXXXXXXXX) en el checkout |

### Identidades (`pages/users/`, `pages/roles/`)

| Archivo | URL | Permiso (gate web) | APIs | Notas |
|---------|-----|---------------------|------|-------|
| `users/Users.tsx` | `/identities/users` | `users.read` | `GET /api/v1/users`, `PUT /api/v1/users/{id}/role`, `PATCH /api/v1/users/{id}/status`, `DELETE /api/v1/users/{id}`, `POST /api/v1/invitations` | Tabla miembros con `RoleBadge`. Invitar (modal). Cambiar rol/permisos/estado. Bulk actions. Watch `activeCompany` para refrescar al cambiar empresa. Modales: `InviteUserModal`, `UserPermissionsEditor` |
| `roles/Roles.tsx` | `/identities/roles` | `roles.read` | `GET /api/v1/roles`, `GET /api/v1/features`, `DELETE /api/v1/roles/{id}` | Tabla de roles con columna "Usuarios" (`users_count`). Editar/eliminar oculto si `is_system=true`. Botón Crear visible si `can_manage=true`. Confirmación de delete advierte cuántos usuarios tiene asignados. |
| `roles/RoleEditor.tsx` | (modal, no es ruta) | `roles.create/update` | `POST /api/v1/roles`, `PUT /api/v1/roles/{id}` | Editor con `PermissionsMatrix` + color picker (swatches + nativo). Validación pre-submit de nombre único por empresa. Color aleatorio por defecto en modo creación. Selector "Plantilla / Clonar permisos de…" copia permisos de cualquier rol existente (incluidos `is_system`). Bulk-toggle por columna CRUD desde el header de la matriz. Vista previa del badge en vivo. |

### Domicilios (`pages/deliveries/`)

| Archivo | URL | Permiso | APIs | Notas |
|---------|-----|---------|------|-------|
| `index.tsx` | `/orders/deliveries` | `deliveries.read` (gate web) | `GET /api/v1/deliveries`, `PATCH .../complete`, `POST .../reassign`, `DELETE .../{id}` | Lista paginada con filtros (status, courier, fechas). Live toggle 30s con auto-off 5min. Modal asignar/reasignar. Hook: `useDeliveryList` |
| `metrics.tsx` | `/deliveries/metrics` | `deliveries.read` | `GET /api/v1/deliveries/metrics?period=` | Tabla por repartidor: número entregas, tiempo promedio, tasa éxito. Selector período (today/week/month). Export PDF: `POST /api/v1/exports/couriers/pdf`. Hook: `useDeliveryMetrics` |
| `mine.tsx` | `/my-deliveries` | `deliveries.read` (gate web) | `GET /api/v1/deliveries/mine`, `GET .../available`, `POST .../orders/{id}/self-assign`, `PATCH .../{id}/complete`, `PUT .../{id}/revert`, `PUT .../{id}/reject` | **#119**: vista mobile-first del domiciliario. Tabs `Asignadas / Disponibles / Historial hoy`. Polling 30s en `Disponibles` (visible si `deliveries.self_assign`). Cards usan `MyDeliveryCard` con tap-to-call (`tel:`) y tap-to-maps (`maps.google.com`). Bottom-sheets para acciones secundarias (revertir, rechazar). Tokens DS, sin colores hardcoded |

#### Componentes nuevos en `components/deliveries/` (#119)

- `my-delivery-card.tsx` — card mobile-first con CTA grande "Marcar entregado" + menú "..." para acciones secundarias. Tap-to-call y tap-to-maps. Acento lateral por estado (`var(--color-status-*)`).
- `available-order-card.tsx` — card de orden sin domicilio. CTA "Tomar entrega" + busy state. Currency formatter COP sin decimales.
- `delivery-action-sheet.tsx` — bottom-sheet (`@/components/ui/bottom-sheet`) con acciones secundarias según estado.
- `reject-reason-sheet.tsx` — bottom-sheet de motivo con chips de razones rápidas + textarea opcional. Envía `reason` libre al backend.

### Empresa (`pages/company/`)

| Archivo | URL | Permiso (gate web) | APIs | Notas |
|---------|-----|---------------------|------|-------|
| `preferences.tsx` | `/company/preferences` | `company.update` | `GET/PATCH /api/v1/companies/settings` | 3 secciones: Regional (timezone, currency, language), Pedidos (delivery_area_km, min_order_amount, payment_methods, order_auto_confirm), Notificaciones (order_notify_customer_email). WhatsApp-Privacidad y Bot se movieron a `/company/whatsapp`. Container `mx-auto max-w-6xl` |
| `settings.tsx` | `/company/settings` | `company.update` | `GET/PUT /api/v1/company`, `GET/PATCH /api/v1/companies/settings` | Tabs (con iconos): Información (`Building2`) y Facturación (`Receipt`). Datos: nombre comercial, razón social, banco, cuenta, BREB. Logo (PNG/JPG/WEBP/SVG, **máx 5 MB**), QR pagos (PNG/JPG, **máx 5 MB**). Color principal del menú público (`menu_primary_color` validado regex hex). NIT inmutable. **Tarjeta "QR del Menú"**: render cliente-side de un poster (canvas) con headline "Consulta la carta aquí", logo, nombre comercial, color primario y QR apuntando a `/menus/{nit}`. Reactivo: edita logo o color y la preview se actualiza al instante. **Select de mesa** (1..tableCount) leído de `localStorage['tables.grid_size']` — sólo permite las mesas que existen en `/orders/tables`; "Sin mesa" → QR genérico. Botón Descargar PNG. Sin almacenamiento en backend (componente: `components/company/menu-qr-poster.tsx`). **Tab "Facturación" — Tarjeta "Contrato de servicio aceptado" (#170)**: muestra `version`, `accepted_at`, `accepter_name` y un modal con el snapshot inmutable (componente `<Markdown>` sobre `document_content`). Badge informativo "Hay una versión más reciente" si `legal_documents` tiene una version posterior. Prop Inertia `acceptedContract` resuelto en `resolveAcceptedCompanyContract()` (`app/Support/web_helpers.php`). |
| `branches/index.tsx` | `/company/branches` | `branches.manage` (write); todos los miembros pueden ver | `GET /api/v1/company/branches`, `POST`, `PATCH/{id}`, `DELETE/{id}` (archive), `GET/{id}/users`, `POST/{branch}/menu/copy` | Multi-sede (#117): listado con badges (principal, archivada), modal crear/editar (slug regex), botones por sede (Usuarios, Copiar menú, Editar, Archivar). Toggle `Ver archivadas`. Permisos: `branches.manage`, `branches.assign_users`, `branches.copy_menu` |
| `whatsapp.tsx` | `/company/whatsapp` | `whatsapp.read` (gate web) | `GET/POST /api/v1/whatsapp/channels`, `GET .../channels/{id}/{state,qr,metrics}`, `POST .../channels/{id}/{pairing-code,test-message}`, `DELETE .../channels/{id}`, `GET/PATCH /api/v1/companies/settings`, `GET/POST/PUT/DELETE .../whatsapp/automation-flows*` | **Rediseño F3 sobre Evolution (Baileys/QR)**: lista de canales multi-sede (`ChannelCard`), wizard de conexión (`ConnectWizard` con QR/pairing-code), respuestas rápidas (`QuickRepliesManager`), **sección Automatización** (`AutomationSection`, F6) mostrada **deshabilitada** mientras n8n no se despliegue. Bloque "Preferencias" (privacidad `whatsapp_read_receipts`, bot). **Sin SDK de Facebook** (removido en F3). El camino Meta legado del backend coexiste hasta F4 |
| `whatsapp/automation-section.tsx` | (componente de `/company/whatsapp`) | `whatsapp.update` (acciones) | `.../whatsapp/automation-flows` CRUD + `/rotate-token`, `/rotate-secret`, `/test`, `/deliveries` | F6 (§9.5): tarjetas de flujo n8n por empresa/sede, token/secreto revelados una-sola-vez (patrón PAT), eventos, prueba de webhook, tabla de entregas, ejemplo de payload. Hook `use-automation-flows.ts`. Renderizada deshabilitada (n8n a futuro) vía prop `available` |

### Horarios y Cupones

| Archivo | URL | Permiso | APIs | Notas |
|---------|-----|---------|------|-------|
| `hours/index.tsx` | `/hours` | `hours.read` | `GET /api/v1/hours`, `GET .../exceptions`, `GET .../status`, `PUT .../{}`, CRUD excepciones | Editor semanal (Dom–Sáb). Calendar de excepciones (festivos, mantenimiento). Estado en tiempo real (abierto/cerrado + próximo cambio). Hook: `useBusinessHours` |
| `coupons/index.tsx` | `/coupons` | `coupons.read` | vía `useCoupons`: `GET /api/v1/coupons`, `POST`, `PUT`, `PATCH /status`, `DELETE` | Lista con filtros y búsqueda. Crear (código manual o `generateCouponCode()`), tipo (percentage/fixed), valor, validez, max_uses, min_order, first_order_only |
| `coupons/show.tsx` | `/coupons/{id}` | `coupons.read` | `GET /api/v1/coupons/{id}`, `GET /api/v1/coupons/{id}/redemptions` | Detalle completo + historial paginado de redenciones (50/página) con teléfono enmascarado |

### Facturación, Chats, Carrito, Perfil

| Archivo | URL | Permiso | APIs | Notas |
|---------|-----|---------|------|-------|
| `billing/index.tsx` | `/billing` | `billing.read` (solo owner/admin) | `GET /api/v1/billing/subscription`, `GET /api/v1/billing/invoices`, `GET .../{id}/download` | Tarjeta de suscripción + tabla paginada (15/página) de facturas con tipo (Mensual/Prorrateo), período, monto, vencimiento, estado. Banner de mora si `company.status=mora|delinquent`. Comparación de planes vía `GET /api/v1/billing/plans`. Export CSV: `GET .../export.csv` |
| `chats.tsx` | `/chats` | `chats.read` | `GET /api/v1/chats` (5s), `GET /api/v1/chats/{id}`, `POST /api/v1/chats/{id}/messages`, `POST /api/v1/chats/{id}/mark-read`, `GET /api/v1/chats/{id}/client`, `POST /api/v1/chats/{id}/menu-link` | Panel 2 columnas: lista (5s polling) + conversación. Búsqueda con `?q=` (debounce 300ms). `ChatSourceBadge` (whatsapp/instagram/facebook). Última orden clickeable abre `OrderDetailModal`. `ClientDetailModal` con historial. Mark-read despacha al backend solo si chat abierto + tab visible + hay `meta_message_id`. Reproductor audio (`AudioPlayer`) estilo WhatsApp. "Enviar la carta" (unificada, reemplaza carta estática + carrito JWT): link corto `/menus?cart={uuid}` con sesión ligada al chat; fallback al link estático si el backend no puede |
| `cart.tsx` | `/cart/{jwt}` | sin auth (CartJwt) | `POST /api/v1/cart/migrate-jwt/{jwt}`, `GET /api/v1/cart/{jwt}` | Lectura del carrito anónimo del cliente. Items y total persisten en `cart_items`. Aplicación de cupón vía `POST /api/v1/cart/apply-coupon`. Solo lectura desde frontend (los items los pobla el bot/backend) |
| `me/index.tsx` | `/me` | autenticado | `GET /api/v1/me` | Vista solo lectura: nombre, email, cédula, rol en empresa activa |

### CRM básico de clientes (`pages/clients/`, issue #123)

| Archivo | URL | Permiso | APIs | Notas |
|---------|-----|---------|------|-------|
| `clients/index.tsx` | `/clients` | `clients.read` | `GET /api/v1/clients?search=&segment=&tag=&page=&per_page=` | Listado con búsqueda (debounce 300ms, matchea nombre / documento / teléfono), filtros por segmento (`vip`, `recurrent`, `new`, `inactive`, `at_risk`, `regular`) y por tag. Tabla con nombre, documento, teléfono, pedidos, ticket promedio, total gastado, última orden, segmento, tags. Paginación 25/página. Refactor #235: identidad canónica = `contacts.id`; phone puede repetirse entre familiares. Con `clients.delete`: checkboxes de selección + barra "Unificar (N)" que abre `MergeClientsDialog` (`components/clients/merge-clients-dialog.tsx`, `POST /api/v1/clients/{principal}/merge`) para fusionar duplicados eligiendo el principal por radio. |
| `clients/show.tsx` | `/clients/{contact}` | `clients.read` | `GET /api/v1/clients/{contact}`, `POST/DELETE .../notes`, `POST/DELETE .../tags`, fidelización via `LoyaltyPanel` (#122) | Perfil consolidado cross-sede. Param de ruta = `contacts.id` (refactor #235). Header con nombre + documento (CC/NIT/CE…) + phone + segmento + 8 KPIs (órdenes totales, total gastado, ticket promedio, primera/última orden, órdenes 60d, gasto 90d, % cancelaciones). `TagsEditor` chips agregar/quitar. **Panel de fidelización** (`<LoyaltyPanel>`) entre header y tabs si tiene `loyalty.read` y el contact tiene phone. Tabs: historial de órdenes (50 últimas, link a chat si tiene), chats (cross-sede), notas privadas (`NotesPanel`, soft-delete con confirmación). Botón "Ver chat" si existe al menos una conversación. |

### Fidelización con puntos (#122)

| Archivo | Ruta / Hook | Permiso | Endpoints | Descripción |
|---|---|---|---|---|
| `pages/loyalty/reports.tsx` | `/loyalty/reports` | `loyalty.read` | `GET /api/v1/loyalty/reports/summary` | Reportes del programa. KPIs (otorgados, canjeados, expirados, clientes activos), tasa de canje, distribución de cuentas por tier con `LoyaltyBadge`, tabla ARPU por tier (revenue + ARPU desde `orders`), top 20 clientes por lifetime, panel de expiraciones. Filtros `from`/`to` con default últimos 30 días. |
| `components/loyalty-badge.tsx` | — | — | — | Badge reutilizable bronze/silver/gold con icono (Medal/Award/Crown). Tamaños `sm`/`md`. Cae a `bronze` si el tier no se reconoce. |
| `components/clients/loyalty-panel.tsx` | embebido en `clients/show.tsx` | `loyalty.read` (view) / `loyalty.update` (ajustar+canjear) | `GET/POST /api/v1/loyalty/accounts/{phone}/*` | Saldo, lifetime, tier, progreso a siguiente, catálogo de rewards (canjeable/bloqueado), modal "Ajustar manualmente" (puntos + motivo, tope `LOYALTY_MAX_MANUAL_ADJUST`), modal "Canjear en nombre del cliente" con banner verde + `coupon_code` resultante, historial de 50 movements con badge de tipo + delta coloreado. |
| `components/coupons/loyalty-card.tsx` | embebido en `cart.tsx` | público | `POST /api/v1/public/loyalty/{nit}/{lookup,redeem}` | Tarjeta dorada en checkout. Lookup automático si el cart trae `client_phone`. 404 silencioso si el programa está deshabilitado. Saldo grande + barra de progreso + lista de rewards (deshabilita las que no superan `min_order_amount` del pedido actual). Al canjear, callback `onCouponIssued(code)` → el padre prefilla el `CouponInput` para que el cliente solo presione "Aplicar". |
| `hooks/use-loyalty.ts` | `useLoyalty(token, phone)` | JWT autenticado | `GET/POST /api/v1/loyalty/accounts/*` | Carga `account` con movements + rewards + config. Expone `adjust(points, reason)` y `redeem(rewardKey)`. Combina mensajes de error de validación. |

**Integración en `cart.tsx`**: aparece sólo si `cart.client_phone` y `cart.company_nit`. El `coupon_code` emitido se inyecta como `initialCode` al `CouponInput` (CouponInput acepta `initialCode` agregado para este flujo).

**Extender tiers**: agregar el tier en `config/loyalty.php` `tiers` Y registrar su estilo en `TIER_STYLES` de `loyalty-badge.tsx`. Si no, el badge cae a bronze.

### Alertas accionables de margen y costos (issue #124)

| Archivo | URL/embebido | Permiso | APIs | Resumen |
|---------|--------------|---------|------|---------|
| `components/alerts/alerts-feed.tsx` | embebido en `pages/dashboard.tsx` | `reports.read` | `GET /api/v1/alerts`, `POST /api/v1/alerts/{id}/{dismiss,action}` | Card con alertas activas (ordenadas por severity crítico→info). Cada alerta muestra título por tipo (Margen bajo / Costo en alza / Sin ventas / Stock bajo), descripción human-readable con cifras, color por severity, botón "X" para descartar y "Marcar revisado" con prompt para nota. Deep-link a `/menu` (target=menu_item) o `/inventory` (target=ingredient). Auto-poll cada 5 min. Renderiza nada si no hay alertas. |
| `components/alerts/alert-rules-config.tsx` | embebido en `pages/company/preferences.tsx` (solo si `!readOnly`) | `company.update` | `GET /api/v1/alert-rules`, `PUT /api/v1/alert-rules/{type}` | Card con las 4 reglas. Por cada tipo: toggle "Activa", input de threshold (en %, oculto para low_volume y low_stock que no usan threshold), input period_days, botón "Guardar" individual. Threshold display en porcentaje (UI) ↔ fracción 0-1 (API). |
| `hooks/use-alerts.ts` | `useAlerts(status, pollMs)` y `useAlertRules()` | JWT + permisos | `GET/POST /api/v1/alerts/*`, `GET/PUT /api/v1/alert-rules/*` | `useAlerts` mantiene `alerts`, `summary`, `dismiss`, `action`, `refresh`. `useAlertRules` mantiene `rules`, `update(type, data)`. |

**Integración en `/dashboard`**: `<AlertsFeed />` renderiza arriba de los KPIs solo si el usuario tiene `reports.read`. Si no hay alertas activas, el componente no ocupa espacio.

**Integración en `/company/preferences`**: `<AlertRulesConfig />` se añade después del grid principal de cards. La validación final del permiso la hace el backend.

### Inventario de insumos (`pages/inventory/`, issue #111)

| Archivo | URL/embebido | Permiso | APIs | Notas |
|---------|--------------|---------|------|-------|
| `inventory/index.tsx` | `/inventory` | `inventory.read` | `GET /api/v1/inventory/{valuation,ingredients,movements,history/valuation}`, mutaciones vía `IngredientMovementController` | Hub de inventario: tabla de insumos con filtros (búsqueda, archivados, bajo stock), cards de valorización por bodega, gráfico de serie histórica (`InventoryValuationChart`), botones rápidos (Crear, Entrada, Merma, Ajuste, Transferir). Sin polling automático. Hook: `useInventory` |
| `inventory/components/IngredientFormModal.tsx` | modal | `inventory.create/update` | `POST/PATCH /api/v1/inventory/ingredients/*` | Crear/editar insumo. Campos: nombre, unidad (kg/lt/un/etc), min_stock_qty, default_unit_cost, descripción. Validación de unidad consistente con receta vinculada |
| `inventory/components/RecordEntryModal.tsx` | modal | `inventory.create` | `POST /api/v1/inventory/movements/entry` | Registrar entrada por compra ad-hoc. Sin OC. Campos: insumo, bodega, qty, unit_cost, ref opcional, notas. Recalcula `current_avg_cost` ponderado |
| `inventory/components/RecordWasteModal.tsx` | modal | `inventory.create` | `POST /api/v1/inventory/movements/waste` | Registrar merma. Campos: insumo, bodega, qty (positivo, signo lo aplica backend), motivo (vencimiento/derrame/calidad), notas |
| `inventory/components/AdjustStockModal.tsx` | modal | `inventory.update` | `POST /api/v1/inventory/movements/adjustment` | Ajuste manual (conteo físico vs sistema). Captura `new_on_hand` y backend calcula delta. Exige nota |
| `inventory/components/TransferStockModal.tsx` | modal | `inventory.update` | `POST /api/v1/inventory/transfers` | Mover entre bodegas (multi-bodega #120). Validación: ambas bodegas en la misma sede; origen tiene stock suficiente |
| `inventory/components/MovementsDrawer.tsx` | drawer | `inventory.read` | `GET /api/v1/inventory/movements?ingredient_id=` | Historial inmutable por insumo. Tipos: entry (verde), waste (rojo), adjustment (amber), recipe_consumption (azul), transfer_in/out (gris). Muestra qty, unit_cost, total_cost, ref, actor |
| `inventory/components/InventoryValuationChart.tsx` | embebido | `inventory.read` | `GET /api/v1/inventory/history/valuation` | Recharts: serie temporal de valorización por bodega. Tooltip con valor + delta vs día anterior |

### Compras y proveedores (`pages/purchases/`, issue #118 — página UNIFICADA 2026-07-01)

> `/purchases` fusiona las antiguas páginas de compras y proveedores en tabs
> **Órdenes | Proveedores** (`?tab=proveedores`). Un solo ítem en el sidebar
> ("Compras y proveedores", visible con `anyPermission: [purchases.read, suppliers.read]`).
> La ruta `/suppliers` se eliminó por completo (cae al 404 del SPA). Los
> catálogos (proveedores, insumos) se cargan una vez y se comparten entre
> tabs y el editor.

| Archivo | URL/embebido | Permiso | APIs | Notas |
|---------|--------------|---------|------|-------|
| `purchases/index.tsx` | `/purchases` (+`?tab=proveedores`) | `purchases.read` y/o `suppliers.read` (tabs gateados individualmente) | `GET /api/v1/purchases?status=&page=`, drawer detail | Contenedor con tabs. Tab Órdenes: tabla paginada de OC con filtros + KPIs. El botón "Nueva orden" solo se bloquea si falta catálogo Y el usuario no puede crearlo inline (antes bloqueaba siempre). Hooks: `usePurchases`, `useSuppliers`, `useInventory` compartidos |
| `purchases/components/suppliers-panel.tsx` | tab Proveedores | `suppliers.read` | `GET /api/v1/suppliers?search=&archived=`, CRUD vía modal | Contenido de la antigua `/suppliers`: KPIs, búsqueda, filtro archivados, tabla con editar/archivar/restaurar. "Crear proveedor" gateado por `suppliers.create` |
| `purchases/components/PurchaseOrderEditor.tsx` | modal | `purchases.create/update` | `POST/PATCH /api/v1/purchases/*` | Editor de OC. Selector de proveedor con **quick-create inline** ("+ Crear proveedor" en el footer del combobox, gateado por `suppliers.create` — crea y autoselecciona sin salir del editor, mismo patrón que insumos). Líneas (insumo + qty + unit_price) con quick-create de insumo, suma automática (subtotal/tax/total). Notas saneadas (§5, maxBytes 2000) |
| `purchases/components/supplier-form-modal.tsx` | modal (tab Proveedores + quick-create del editor) | `suppliers.create/update` | `POST/PATCH /api/v1/suppliers/*` | Crear/editar proveedor. Datos: nombre, NIT, contacto, email, tel, dirección, payment_terms_days. Validación NIT colombiano |
| `purchases/components/PurchaseOrderDetailDrawer.tsx` | drawer | `purchases.read` | `GET /api/v1/purchases/{id}` | Detalle drawer con líneas, totales, status timeline. Acciones según estado: Confirmar entrega (`receive`), Pagar (`pay`), Cancelar/Anular. Pestañas: Detalle, Adjuntos, Auditoría |
| `purchases/components/AttachmentsPanel.tsx` | embebido en drawer | `purchases.read/update` | `GET/POST/DELETE /api/v1/purchases/{id}/attachments/*` | Lista adjuntos con preview (image) o icono (PDF). Upload (10 MB max, pdf/jpg/png). Soft-delete |
| `purchases/components/MarkPaidModal.tsx` | modal | `purchases.pay` | `POST /api/v1/purchases/{id}/pay` | Registrar pago. Selector método (cash/card/transfer), referencia (obligatoria para card/transfer), monto (= total OC, no se acepta otro), fecha (default hoy) |
| `purchases/components/VoidPOModal.tsx` | modal | `purchases.delete` | `POST /api/v1/purchases/{id}/void` | Anular OC post-recepción. Crea nota crédito automática + revierte inventario. Exige motivo (≥ 10 chars). Confirmación destructiva |

### Empresa: sedes, bodegas, impresoras

| Archivo | URL | Permiso (gate web) | APIs | Notas |
|---------|-----|---------------------|------|-------|
| `company/warehouses/index.tsx` | `/company/warehouses` | `warehouses.manage` | `GET /api/v1/company/warehouses`, `POST/PATCH/DELETE`, `POST/DELETE .../{warehouse}/branches/{branch?}`, `PUT .../{warehouse}/branches/{branch}/default` | Multibodega company-scoped (refactor multibodega). Las bodegas pertenecen a la empresa y se asignan a N sedes vía pivot (`branches[]`). Cada card muestra "Sedes asignadas" con acciones asignar / marcar predeterminada / desasignar (maneja `WAREHOUSE_USED_BY_RECIPES`). KPI "Sedes con bodega" deriva de `branches[]`. Modal crear/editar (nombre/slug/tipo) + asignación inicial opcional. Archivar bloqueado si tiene stock > 0 |
| `company/printers.tsx` | `/company/printers` | `company.update` | `GET /api/v1/company/printers`, `POST/PATCH/DELETE` | Impresoras ESC/POS (#116). Listado por sede con `agent_uuid`, ancho de papel, target (cashier/kitchen/bar), is_active. Modal crear/editar con instrucciones del agente local |

### Configuración personal (`pages/settings/`)

| Archivo | URL | APIs |
|---------|-----|------|
| `profile.tsx` | `/settings/profile` | `PATCH route('profile.update')`, `DELETE route('profile.destroy')` |
| `password.tsx` | `/settings/password` | `PUT route('password.update')` |
| `appearance.tsx` | `/settings/appearance` | — (solo localStorage) |

---

## COMPONENTES (156)

### UI primitivas (`components/ui/`, ~32)

Sin lógica de auth ni permisos. Todas presentacionales, accesibles (Radix donde aplica).

| Archivo | Base | Notas |
|---------|------|-------|
| `alert.tsx` | custom | Banners contextuales |
| `avatar.tsx` | Radix Avatar | — |
| `badge.tsx` | custom | Variantes color |
| `bottom-sheet.tsx`, `bottom-sheet-dialog.tsx` | custom | Modal bottom para mobile |
| `breadcrumb.tsx` | custom | Trail con separadores |
| `button.tsx` | custom | Variantes (primary, outline, ghost, destructive) |
| `card.tsx` | custom | Header/Content/Footer |
| `checkbox.tsx` | Radix Checkbox | — |
| `collapsible.tsx` | Radix Collapsible | — |
| `confirm-dialog.tsx` | Radix Dialog | Confirmación destructiva con onConfirm |
| `dialog.tsx` | Radix Dialog | Base para todos los modales |
| `dropdown-menu.tsx` | Radix DropdownMenu | — |
| `icon.tsx` | wrapper | Wrapper de lucide-react |
| `input.tsx` | custom | Tipos text/email/password/number/etc |
| `label.tsx` | Radix Label | — |
| `live-polling-toggle.tsx` | custom | Toggle live + countdown auto-off |
| `markdown.tsx` | react-markdown | Render TOS/privacy/contract |
| `navigation-menu.tsx` | Radix NavigationMenu | — |
| `placeholder-pattern.tsx` | custom SVG | Empty state pattern |
| `select.tsx` | Radix Select | — |
| `separator.tsx` | Radix Separator | — |
| `sheet.tsx` | Radix Sheet | Drawer lateral (sidebar mobile) |
| `sidebar.tsx` | custom | Sistema sidebar con `SidebarTrigger` (Ctrl+B) |
| `skeleton.tsx` | custom | Loading state |
| `table.tsx` | custom | Componentes Table/TableRow/TableCell |
| `tabs.tsx` | Radix Tabs | — |
| `toast.tsx` | custom | Sistema de notificaciones (`useToast` hook) |
| `toggle.tsx` | Radix Toggle | — |
| `toggle-group.tsx` | Radix ToggleGroup | — |
| `tooltip.tsx` | Radix Tooltip | Delay 3s con portal |
| `field-hint.tsx` | custom (Tooltip + Info) | Ícono (i) + tooltip junto al `<Label>` de un campo ambiguo. Autocontenido y accesible; reemplaza el patrón `Tooltip`+`Info` inline |

### Componentes por dominio (~97)

#### `components/dashboard/` (~8)

| Archivo | Tipo | Notas |
|---------|------|-------|
| `live-indicator.tsx` | display | Punto verde pulsante con tooltip de timestamp |
| `heatmap-chart.tsx` | display | Heatmap horario o semanal (7×24) |
| `top-items-chart.tsx` | display | Lista/gráfica de platos más vendidos |
| `period-filter.tsx` | interactivo | Tabs today/week/month/custom |
| `widget-error-state.tsx` | display | Empty state con retry |
| `skeleton-chart.tsx`, `skeleton-deliveries.tsx`, `skeleton-heatmap.tsx`, `skeleton-metric-card.tsx` | display | Skeletons de cada panel |

#### `components/metrics/` (~10)

| Archivo | Tipo | Notas |
|---------|------|-------|
| `kpi-card.tsx` | display | Label, valor, tendencia, icono, skeleton |
| `dish-ranking-panel.tsx` | display | Wraps `TopItemsChart` |
| `dish-margin-panel.tsx` | display | Tabla de margen por plato con semáforo (rojo <20%, amarillo 20–39%, verde ≥40%); empty state si no hay platos con costo (#113) |
| `food-cost-panel.tsx` | display | Card con % food cost actual + delta vs período anterior + serie 30 días (recharts). Color rojo si supera `food_cost_threshold` (`company_settings`) (#113) |
| `menu-engineering-panel.tsx` | display | Matriz 2×2 popularidad × margen (Stars/Plowhorses/Puzzles/Dogs) con plato más representativo por cuadrante. Filtros de período (#114) |
| `abandonment-panel.tsx` | display | Tasa + revenue perdido |
| `active-orders-panel.tsx` | display | Conteos por estado |
| `heatmap-panel.tsx` | display | Wraps `HeatmapChart` |
| `offline-operation-panel.tsx` | display | KPIs de la sincronización offline (#140): órdenes pendientes, sincronizadas en 24h, conflictos. Color rojo si hay pendientes que bloqueen cierre de caja |
| `menu-scans-panel.tsx` | fetch propio | Escaneos del menú QR (#294): total + sesiones únicas, barras escaneos/día (recharts), desglose por mesa (top 10) y por sede (solo consolidado). Consume `GET /api/v1/metrics/menu-scans`; respeta filtro de sede y período de la página |

#### `components/alerts/` (~2, issue #124)

| Archivo | Notas |
|---------|-------|
| `alerts-feed.tsx` | Card del dashboard con alertas activas ordenadas por severidad. Acciones: Descartar (`X`) y Marcar revisado (con prompt para nota). Deep-link a `/menu` o `/inventory`. Auto-poll 5 min. No renderiza si no hay alertas |
| `alert-rules-config.tsx` | Embebido en `/company/preferences`. 4 reglas: margin_below, cost_increase, low_stock, item_low_volume. Toggle activa + input threshold (en %) + period_days. Botón Guardar por regla |

#### `components/clients/` y fidelización (~5)

| Archivo | Notas |
|---------|-------|
| `segment-badge.tsx` | Badge por segmento (VIP/Recurrente/Nuevo/Inactivo/En riesgo/Regular). Export `segmentLabel(seg)` |
| `notes-panel.tsx` | Notas con autor + fecha. Form inline (max 2000 chars). Confirmación destructiva al eliminar. Gates `canEdit`/`canDelete` (#123) |
| `tags-editor.tsx` | Chips de etiquetas con validación slug (`/^[a-z0-9_\-]+$/`). Idempotente (#123) |
| `loyalty-panel.tsx` | Saldo + tier + lifetime + progreso al siguiente + catálogo de rewards + modal Ajustar/Canjear + historial 50 movements. Embebido en `clients/show.tsx` (#122) |
| `loyalty-badge.tsx` (root) | Badge reutilizable bronze/silver/gold con icono (Medal/Award/Crown). Tamaños sm/md. Fallback a bronze si tier no reconocido (#122) |

#### `components/pwa/` (~3, issue #103)

| Archivo | Notas |
|---------|-------|
| `install-pwa-prompt.tsx` | Captura `beforeinstallprompt`, muestra prompt diferido al alcanzar 2da visita. Dismiss persistente en `localStorage['pwa.install.dismissed_at']` |
| `ios-install-hint.tsx` | Hint específico para Safari iOS (sin `beforeinstallprompt`). Tutorial inline "Compartir → Agregar a inicio" |
| `update-available-toast.tsx` | Toast con CTA "Actualizar" cuando el SW detecta una nueva versión. Llama `skipWaiting()` + `window.location.reload()` |
| `pwa-update-banner.tsx` | Barra fija inferior al detectar deploy nuevo con pestaña visible (`pwa:update-available`). No recarga sola — el staff decide con el botón "Actualizar" (evita perder un cobro a medio llenar). Montada en `app-sidebar-layout` |
| `app-footer-meta.tsx` | Footer con link al manual y versiones `fv`/`bv`. `fv` viene horneada en build (Vite `define`); `bv` la reporta el backend en runtime vía `/api/v1/bootstrap` → `versions.backend` (fallback al valor de build para backends < 1.30.2) |

#### `components/offline/` (~3, issue #140)

| Archivo | Notas |
|---------|-------|
| `offline-banner.tsx` | Banner ámbar persistente cuando `!navigator.onLine`. Muestra contador de operaciones pendientes en IndexedDB |
| `storage-quota-warning.tsx` | Card de advertencia si `navigator.storage.estimate()` reporta < 50 MB libres. Sugiere sincronizar y cerrar caja |
| `sync-toast.tsx` | Toast con resumen del sync al volver online: N órdenes confirmadas, M conflictos. Conflictos muestran botón "Ver detalles" |

#### `components/menu/` (~17)

`menu-manager.tsx`, `menu-card.tsx`, `category-card.tsx`, `category-form-modal.tsx`, `category-items-list.tsx`, `sortable-category.tsx`, `sortable-item.tsx`, `item-card.tsx`, `dish-card.tsx`, `dish-form-modal.tsx`, `item-form-modal.tsx`, `publish-modal.tsx`, `schedule-modal.tsx`, `menu-preview.tsx`, `expandable-category.tsx`, `availability-toggle.tsx`, `image-upload-zone.tsx`, `day-selector.tsx`.

Notas: `image-upload-zone` usa `useImageUpload` (valida JPG/PNG, máx 2 MB). `availability-toggle` aplica optimistic update con rollback. `dnd-kit` envuelve sortable-* para reordenar.

#### `components/orders/` (~3)

| Archivo | Notas |
|---------|-------|
| `order-detail-modal.tsx` | Modal con detalle completo. Muestra items con notas, desglose tributario (Subtotal base gravable / Impuesto / Total), propinas como línea informativa separada, datos de pago (método, referencia, monto recibido, cambio) y devoluciones (incl. parciales: acumulado y remanente). Botones contextuales: Asignar/Reasignar repartidor (delivery), Cambiar estado, Cancelar pedido (sin pago), Devolver (con pago). Status-aware: bloquea acciones en estados terminales. Teléfono y "Ir a chat" se ocultan cuando `order_type === 'table'` (En sitio: cliente presente). En el card del tablero también se omite `client_phone` para órdenes En sitio |
| `order-sms-failure-watcher.tsx` | Componente sin UI montado una vez en el layout autenticado (`app-sidebar-layout.tsx`, dentro de `ToastProvider`). Poll-ea `GET /api/v1/order-sms-failures` (cada 45s, pausa si la pestaña está oculta); si el SMS al cliente (#275) falló en el envío async, muestra un toast informativo **una sola vez** al usuario que disparó el cambio y hace ack vía `POST /api/v1/order-sms-failures/seen` (el "una vez" lo garantiza el servidor). No bloquea ni depende del flujo del tablero |

#### `components/cash-register/` (~3)

| Archivo | Notas |
|---------|-------|
| `cash-register-panel.tsx` | Wrapper que envuelve caja/mesas. Si NO hay sesión activa: pantalla "Caja cerrada" con input de efectivo inicial y botón "Abrir caja". Si SÍ hay sesión: banner verde compacto con apertura, usuario, monto inicial, **entradas cash, egresos cash, esperado en caja**, botones "Entrada", "Egreso" y "Cerrar caja". Modal de cierre con resumen del turno (incluye línea de egresos cash y desglose por categoría) + input de efectivo contado + diferencia proyectada en vivo (verde=cuadre, ámbar=sobrante, rojo=faltante). Polling 10s vía `useCashRegister` |
| `income-modal.tsx` | Modal para registrar una entrada de efectivo (aporte de socio, préstamo, ajuste positivo) contra la sesión activa. Espejo de `expense-modal`. Append-only. `onSubmit` → `useCashRegister().recordIncome` (online + cola offline `cash.income`) |
| `cash-register-alert-banner.tsx` | Banner global persistente en `AppSidebarLayout`. Aparece cuando `should_alert=true` (caja cerrada + menú activo + horario hábil). CTA "Abrir caja" linkea a `/orders/cashier`. Auto-resuelve al detectar apertura |
| `expense-modal.tsx` | Modal para registrar un egreso de caja (categoría + monto + método cash/card/transfer + descripción opcional). Append-only: el modal solo crea, nunca edita. POST a `/api/v1/cash-register/expenses`. Categorías desde `CASH_EXPENSE_CATEGORIES` en `use-cash-register.ts` |

#### `components/printing/` (~1)

| Archivo | Notas |
|---------|-------|
| `print-receipt-button.tsx` | Botón "Imprimir recibo" para órdenes ya pagadas. Pide `GET /api/v1/orders/{id}/receipt-escpos`, envía el binario a una térmica vía WebUSB (`lib/printing/escpos-printer.ts`). Sin WebUSB → fallback descarga `.bin`. Soporta `width=58|80` y `copy=true` (re-impresión marcada como COPIA, no muta `payment_receipts`) |

#### `components/reports/` (~5)

| Archivo | Notas |
|---------|-------|
| `cash-drawer-card.tsx` | Cierre de caja por fecha. Modo "Día específico" (default) con flechas ◀/▶ y atajos Hoy/Ayer; modo "Rango" con quick-buttons 7/30 días. Tabla por método (cobros/devoluciones/neto/propinas/conteo) + caja resaltada con efectivo esperado. Filtra por `paid_at` en TZ Bogotá. Botón Exportar PDF |
| `cash-sessions-card.tsx` | Historial paginado de sesiones de caja (turnos). Estado, apertura/cierre con usuario, inicial/esperado/contado/diferencia. Diferencia coloreada (verde=cuadre, ámbar=sobrante, rojo=faltante) |
| `export-pdf-button.tsx` | Botón con dropdown PDF/CSV; bloquea durante export y maneja errores |
| `sms-sent-card.tsx` | SMS enviados al cliente por cambios de estado (#275). Total de empresa + desglose por sede en el período (Hoy/Semana/Mes/Rango); consume `GET /api/v1/metrics/sms/counts` (permiso `reports.read`, consolidación multi-sede). Monitorea gasto SNS |

#### `components/deliveries/` (~9)

`assign-courier-modal.tsx`, `reassign-modal.tsx`, `confirm-complete-modal.tsx`, `courier-avatar.tsx`, `courier-metrics-panel.tsx`, `delivery-card.tsx`, `delivery-status-badge.tsx`, `performance-bar.tsx`, `timer.tsx`.

#### `components/coupons/` (~8)

`coupon-form.tsx` (#125: bloque de programación con `WEEKDAYS`, `toggleDay()`, time pickers, `auto_apply` toggle), `coupon-input.tsx` (acepta `initialCode` para inyectar canjes de loyalty), `coupon-status-badge.tsx`, `coupon-type-badge.tsx`, `coupon-applied-badge.tsx`, `discount-calculator.tsx`, `redemption-history-table.tsx`, `loyalty-card.tsx` (tarjeta dorada en cart público para canjear puntos, #122).

#### `components/billing/` (~13)

`InvoiceDetailModal`, `InvoiceList` (tabla paginada con filtros), `InvoiceStatusBadge`, `InvoiceTypeChip`, `SubscriptionCard`, `UploadPaymentProof`, **`ActivePromoCodeCard`, `PromoCodeEnrollForm` (#246)**, **`DianUsageCard`** (detalle de uso DIAN por resolución del período en curso + total estimado — Plan Plus, `dian_usage` de `GET /billing/subscription`), `StatBlock` (compartido por `SubscriptionCard`/`DianUsageCard`).

- `ActivePromoCodeCard` (#246) — DashboardPanel con código, % descuento, meses, vigencia, invoices afectadas (últimas 5). Botón "Cancelar código" (ConfirmDialog destructivo) si owner/admin. `applied_via` se muestra como label legible.
- `PromoCodeEnrollForm` (#246) — Input + "Validar código" → preview (starts_at primer día próximo mes, ahorro mensual) → "Confirmar inscripción". Sanitización CLAUDE.md §5 con `sanitizePlainText` maxBytes=50. Errores 422 mapeados a `<Alert>` con copy localizado.
- `OverdueBanner` — banner dentro de `/billing` con `Alert variant=warning|critical` según `company.status` ∈ `past_due|suspended`. Monto adeudado + fecha más próxima de vencimiento. Sólo se ve en la página de facturación.
- `PastDueBanner` (#193) — banner blando global en `app-layout.tsx`. `Alert variant="warning"` con countdown desde el día 1 hasta `expected_block_at` (TZ `America/Bogota`). CTA `Ir a Facturación`. Sólo se renderiza si `activeCompany.status === 'past_due'`. Tokens DS (`var(--color-status-warning)`), sin hex.
- `SuspendedBanner` (#193) — banner persistente global en `app-layout.tsx`. `Alert variant="critical"` con días desde `payment_blocked_at` + monto adeudado (fetch a `/api/v1/billing/subscription`, skeleton inline) + CTA prominente. Sólo si `activeCompany.status === 'suspended'`. Tokens DS, sin hex.
- `components/branches/missing-branch-banner` (#193) — banner global en `app-layout.tsx` cuando `!activeBranch`. Tres sub-estados según conteo de sedes y permisos: empresa sin sedes con CTA `Crear primera sede` (si puede manage), empresa sin sedes sin permiso (mensaje a admin), o hay sedes pero JWT sin sede activa (CTA al `branch-selector`). Antes cada banner operativo (`PendingApprovalsBanner`, `PendingCancellationsBanner`) replicaba un fallback "Sede activa fuera de fecha"; ahora se centraliza acá para que el mensaje sea único y accionable.
- `SuspendedBlockedView` — vista completa que reemplaza el dashboard de `/billing/index.tsx` cuando la empresa está `suspended`. Muestra monto adeudado, datos de pago flexyflow y `UploadPaymentProof` + historial de comprobantes enviados (deuda técnica conocida: aún usa hex hardcoded, pendiente migrar a tokens del DS).

#### `components/company/`

| Archivo | Notas |
|---------|-------|
| `menu-qr-poster.tsx` | Genera un poster con QR + branding (logo, nombre comercial, color primario) en un `<canvas>` del cliente. El QR codifica `${origin}/menus/{nit}` con `?table=N` opcional. Reactivo a props (logo/color/mesa cambian → redraw). Botón "Descargar PNG" exporta vía `canvas.toBlob`. Sin almacenamiento backend. Dependencia: `qrcode` |

#### `components/hours/` (~5)

`exception-modal.tsx`, `exceptions-calendar.tsx`, `menu-priority-banner.tsx`, `open-status-badge.tsx`, `weekly-schedule-editor.tsx`.

#### `components/chats/` (~4)

`chat-message-media.tsx` (incluye `AudioPlayer` estilo WhatsApp), `chat-message-status-ticks.tsx` (doble chulito sent/delivered/read), `chat-source-badge.tsx` (whatsapp/instagram/facebook), `client-detail-modal.tsx` (historial + KPIs).

#### `components/whatsapp/`

| Archivo | Notas |
|---------|-------|
| `whatsapp-verification-code-modal.tsx` | OTP 6 dígitos. Auto-solicita al abrir, muestra correo del owner enmascarado, contador de expiración (10 min), reenvío con cooldown 60s |

#### `components/reports/`

| Archivo | Notas |
|---------|-------|
| `export-pdf-button.tsx` | Botón de export. Hace POST con `filters`, abre PDF en pestaña nueva, maneja errores con toast |

#### Componentes de identidades

| Archivo | Notas |
|---------|-------|
| `invite-user-modal.tsx` | Email + selector de rol. Auto-dismiss tras éxito |
| `role-badge.tsx` | Badge con color de rol. Calcula contraste (luminancia) para texto. Acento azul para `is_system` |
| `user-permissions-editor.tsx` | Modal con `PermissionsMatrix`. `disabledCheck` impide otorgar más allá del scope del actor |
| `permissions-matrix.tsx` | Tabla CRUD por feature group. `readonly` y `disabledCheck` configurables. Tooltip por feature mostrando `feature.description`. Header tri-state opcional (`onBulkToggleColumn`) para activar/desactivar toda una columna CRUD a la vez. |

#### Navegación y chrome (~varios)

| Archivo | Notas |
|---------|-------|
| `app-sidebar.tsx` | Sidebar principal. Grupo "Órdenes" anida {Caja, Tablero, Mesas, Entregas}. Grupo "Mi Empresa" anida {Información, **Sedes** (multi-sede #117), Configuraciones (sub-anidado: General, WhatsApp), Métricas, Informes}. Grupo "Identidades" anida {Usuarios, Roles}. Top-level: Dashboard, Menú, Chats, Cupones, Horarios. SidebarHeader monta `<RestaurantIdentity>` + `<BranchSwitcher>`. **Mora #193**: cuando `activeCompany.status === 'suspended'` el sidebar se reduce a Dashboard + Administración → Mi empresa; el resto de grupos (Catálogo y clientes, Operaciones, Equipo) y los demás items se ocultan. |
| `branch-switcher.tsx` | Multi-sede (#117): dropdown bajo la identidad de empresa con todas las sedes accesibles del usuario. Muestra ícono `MapPin`, badge `Star` para la default. Si el usuario tiene `branches.manage`, incluye link "Gestionar sedes" → `/company/branches`. Persiste última sede en `localStorage` y refresca via `POST /api/v1/auth/switch-branch` |
| `app-sidebar-header.tsx` | Header sticky con `backdrop-blur`. Trigger sidebar + breadcrumbs. Altura `h-14 sm:h-16` |
| `nav-footer.tsx` | Footer del sidebar con enlaces externos |
| `nav-main.tsx` | Lista de items recursiva (con sub-children). **#268**: `filterByPermissions` es la única fuente de verdad de visibilidad RBAC — los items sin permiso se ocultan por completo (no se tachan) antes de renderizar; los componentes de render (`CollapsibleNavGroup`, `CollapsedFlyoutGroup`, etc.) asumen el árbol ya filtrado y no re-chequean permisos. `comingSoon` se conserva como "Pronto disponible". Owner (`is_system`) bypasea vía `canAccess` |
| `nav-user.tsx` | Avatar + dropdown con Settings + Logout |
| `user-menu-content.tsx` | Contenido del dropdown |
| `user-info.tsx` | Avatar + nombre + email opcional |
| `restaurant-identity.tsx` | Logo + nombre comercial en sidebar header |
| `breadcrumbs.tsx` | Render de `BreadcrumbItem[]` |
| `app-shell.tsx` | Shell raíz |
| `app-content.tsx` | Wrapper del área principal |
| `app-logo.tsx`, `app-logo-icon.tsx` | Logo SVG (variante completa y cuadrada) |
| `appearance-tabs.tsx`, `appearance-dropdown.tsx` | Selector tema (light/dark/system) |
| `heading.tsx`, `heading-small.tsx` | Encabezados |
| `text-link.tsx` | Wrapper de `<Link>` |
| `input-error.tsx` | Mensaje de error de campo |
| `delete-user.tsx` | Confirmar y eliminar cuenta actual |
| `google-auth-button.tsx` | Botón "Continuar con Google" con SVG logo |

#### CRM (`components/clients/`, issue #123)

| Archivo | Notas |
|---------|-------|
| `segment-badge.tsx` | Badge con colores por segmento: VIP (ámbar), Recurrente (verde), Nuevo (azul), Inactivo (gris), En riesgo (rojo), Regular (slate). Exporta `segmentLabel(seg)` para usar el label canónico en español. |
| `notes-panel.tsx` | Lista de notas con autor + fecha. Form inline para agregar (max 2000 chars). Confirmación destructiva al eliminar (acción visible en hover). Gateado por props `canEdit`/`canDelete` (vienen de `permissions` shared). |
| `tags-editor.tsx` | Chips con etiquetas. Editor inline con validación de slug (`/^[a-z0-9_\-]+$/`, lowercase forzado). Idempotente: re-agregar la misma tag no falla. Enter para confirmar, Escape para cancelar. |

---

## HOOKS (42)

### Auth y data fetching

| Hook | Retorna | Side effects | Propósito |
|------|---------|--------------|-----------|
| `use-token.ts` | `string \| null` | Lee/escribe `localStorage`. Suscribe a `subscribeToken()`. Sincroniza con Inertia page props | JWT marker. Usado por todo componente que llame `apiFetch` |
| `use-active-branch.ts` | `{activeBranch, branches}` | Lee shared props (multi-sede #117) | Sede activa actual + lista de sedes accesibles. Si `activeBranch` es null y el usuario tiene N sedes, redirigir a `/auth/branch-selector` |
| `use-orders.ts` | `{orders, loading, error, lastUpdated, refresh, updateStatus}` | **Polling 5s** a `/api/v1/orders` | Tablero kanban con actualización optimista de status |
| `use-tables.ts` | `{tableOrders, loading, error, lastUpdated, refresh, appendItems, closeWithPayment}` | **Polling 8s** a `/api/v1/orders/tables` | Mesas ocupadas + append de ítems + cobro con método (cash/card/transfer) + propina opcional. Issue #89 |
| `use-cash-register.ts` | `{session, context, loading, error, refresh, openSession, closeSession}` | **Polling 10s** a `/api/v1/cash-register/current` | Sesión de caja transversal por empresa. `context.should_alert` indica si mostrar el banner global. Cualquier user que abre/cierra ve el cambio reflejado en otros usuarios |
| `use-order-statuses.ts` | `OrderStatusesConfig` | — | Lee shared props `orderStatuses` (config canónico desde backend). Fallback embebido en `lib/order-status.ts` |

**Estados de orden (canónicos)**: `pending`, `in_kitchen`, `ready`, `in_transit`, `completed`, `failed`, `cancelled`, `refunded`, `abandoned`. La fuente de verdad es `config/orders.php` en el backend, expuesta al frontend vía Inertia shared props. Componentes consumen `useOrderStatuses()` + helpers `statusLabel/statusBadgeClass` de `lib/order-status.ts`. **Prohibido declarar listas/labels propios en componentes**.
| `use-courier-assignment.ts` | `{couriers, loading, fetchCouriers, assignCourier}` | Fetch on demand | Asigna repartidores a órdenes |
| `use-coupons.ts` | `{coupons, loading, error, fetchCoupons, createCoupon, updateCoupon, updateCouponStatus, deleteCoupon, fetchCouponRedemptions}` | Carga inicial | CRUD completo de cupones |
| `use-coupon-validation.ts` | `{result, loading, validate, reset}` | Fetch on demand | Valida código sin redimirlo |
| `use-active-auto-apply.ts` | `ActiveAutoApply \| null` | Poll cada 60s | Anuncia happy hour activo en POS (#125) |
| `use-delivery-list.ts` | `{deliveries, pagination, loading, filters, setFilters, fetchDeliveries, completeDelivery, reassignDelivery, completingId}` | Polling configurable (default 30s) | Filtros (status, user_id, fechas, página) |
| `use-delivery-metrics.ts` | `{metrics, loading, period, changePeriod, fetchMetrics}` | Carga inicial | Períodos: today/week/month |
| `use-business-hours.ts` | `{hours, exceptions, status, canUpdate, loading, error, fetchHours, fetchExceptions, fetchStatus, updateHours, createException, updateException, deleteException}` | Carga paralela inicial | CRUD completo `/api/v1/hours/*` |
| `use-cart.ts` | `{cart, loading, addItem, removeItem, clear}` | apiFetch | Carrito (frontend pasivo) |
| `use-chats.ts` | `{chats, messages, loading, sendMessage, markRead}` | Polling 5s | Chats panel |
| `use-clients.ts` | `{clients, meta, loading, error, refresh}` | apiFetch on filter change | Listado CRM con paginación, búsqueda, segmentos, tags (issue #123) |
| `use-client.ts` | `{profile, loading, error, refresh, addNote, deleteNote, addTag, deleteTag}` | apiFetch + mutaciones | Perfil consolidado del cliente con notas/tags (issue #123) |
| `use-widget-fetch.ts` | `{data, loading, error, retry}` | Polling configurable; retry exponencial (máx 3) | Genérico para widgets dashboard |
| `use-period-filter.ts` | `{period, setPeriod, isLoading}` | `router.reload({only: [...]})` | Recarga deferred props del dashboard |
| `use-live-polling.ts` | `{enabled, toggle, activatedAt, autoOffMs}` | setInterval + auto-off (5min) | Live mode user-toggleable (deliveries, dashboard, metrics) |
| `use-permissions.ts` | `{can(feature, action): bool, canAny(feature): bool}` | Lee shared props `permissions` | Helper de RBAC en frontend. Verifica scope del actor activo. Idéntica semántica al backend (`FeaturePermissionService::hasPermission`) |
| `use-loyalty.ts` | `{account, movements, rewards, config, loading, error, refresh, adjust, redeem}` | apiFetch | Fidelización (#122). Staff: cargar cuenta cross-sede por phone, ajustar manualmente, canjear a nombre del cliente |
| `use-alerts.ts` | `useAlerts(status, pollMs) → {alerts, summary, dismiss, action, refresh}`; `useAlertRules() → {rules, update}` | apiFetch + poll 5 min | Feed de alertas (#124) y configuración de reglas. Mantiene severidades ordenadas crítico→info |
| `use-day-sales.ts` | `{totals, loading, refresh}` | apiFetch | Ventas del día por método de pago para `/orders/tables` (cierre rápido) |

### Inventario, compras, recetas, food cost

| Hook | Retorna | Side effects | Propósito |
|------|---------|--------------|-----------|
| `use-inventory.ts` | `{ingredients, warehouses, valuation, history, loading, error, refresh, recordEntry, recordWaste, recordAdjustment, createIngredient, updateIngredient, archiveIngredient, restoreIngredient}` | apiFetch | Fuente única para `/inventory` (#111, #120). Polling off — botón "Actualizar" manual |
| `use-suppliers.ts` | `{suppliers, loading, error, refresh, create, update, archive, restore}` | apiFetch | CRUD de proveedores (#118) |
| `use-purchases.ts` | `{purchases, pagination, loading, error, filters, refresh, create, update, submit, receive, pay, cancel, void, settleRefund}` | apiFetch | Hub de órdenes de compra (#118). Atómicidad backend; el hook solo orquesta |
| `use-recipes.ts` | `{recipe, cost, loading, error, refresh, upsert}` | apiFetch | BOM por plato (#112). Embebido en editor de menú |
| `use-food-cost.ts` | `{summary, history, loading, error, period, setPeriod}` | apiFetch | Food cost en tiempo real (#113). `summary` = % costo/ventas actual + delta vs período anterior |
| `use-menu-engineering.ts` | `{matrix, quadrants, loading, error, period}` | apiFetch | Matriz popularidad × margen (#114). Cuatro cuadrantes con clasificación automática |

### UI/UX

| Hook | Retorna | Side effects | Propósito |
|------|---------|--------------|-----------|
| `use-image-upload.ts` | `{preview, selectedFile, error, handleImageSelect, clearImage}` | FileReader → data URL | Valida JPG/PNG, máx 2 MB |
| `use-menu-drag.ts` | `{updateCategoryOrder, updateItemOrder}` | PUT debounced 300ms | Persiste orden tras drag-drop |
| `use-currency-formatter.ts` | `(price) => string` | — | COP con `Intl.NumberFormat`. Hardcoded a COP |
| `use-date-formatter.ts` | `(isoDate) => string` | — | Etiquetas relativas en español |
| `use-relative-time.ts` | `string` | `setInterval(1s)` | "hace X seg/min" |
| `use-timer.ts` | `{elapsed}` | `setInterval(1s)` | Cronómetro ascendente desde startTime |
| `use-appearance.tsx` | `{appearance, updateAppearance}` | localStorage + clase `<html>` | Light/Dark/System |
| `use-initials.tsx` | `(name) => string` | — | Hasta 2 iniciales |
| `use-mobile.tsx` | `boolean` | `resize` listener | `true` si viewport < 768px |
| `use-mobile-navigation.ts` | `{isOpen, toggle, close}` | — | Sidebar móvil |
| `use-bottom-sheet.ts` | `{isOpen, open, close}` | — | Bottom sheet móvil |
| `use-keyboard-shortcut.ts` | `useKeyboardShortcut`, `RESERVED_SHORTCUTS`, `isReserved`, `normalizeKeys`, `isFocusInInput` | Acorde con modificador + utilidades | Registra acordes puntuales (no la navegación — esa va por `GlobalShortcuts`). Valida contra `RESERVED_SHORTCUTS` (navegador + SO). Inactivo en inputs/textareas |

### Resumen de polling

| Hook / Fuente | Endpoint | Intervalo | Auto-off | Páginas |
|---------------|----------|-----------|----------|---------|
| `useOrders` | `/api/v1/orders` | 5s | — | `orders/board.tsx` |
| `useTables` | `/api/v1/orders/tables` | 8s | — | `orders/tables/index.tsx` |
| `useCashRegister` | `/api/v1/cash-register/current` | 10s | — | `caja/index.tsx`, `orders/tables/index.tsx`, `<CashRegisterAlertBanner>` (todas las páginas) |
| `useChats` | `/api/v1/chats` | 5s | — | `chats.tsx` |
| `useWidgetFetch` (active orders) | `/api/v1/metrics/orders/active` | 30s | — | `dashboard.tsx`, `metrics/index.tsx` |
| `useDeliveryList` | `/api/v1/deliveries` | 30s | 5min | `deliveries/index.tsx` |
| `useLivePolling` (dashboard summary) | recarga deferred props | 60s | 5min | `dashboard.tsx`, `metrics/index.tsx` |
| Inertia `usePoll` | reload deferred props | 300s | — | `dashboard.tsx` |

**Polling reducido (off por default):** `users`, `menu`, `hours`, `company-settings` no hacen polling automático — sólo botón "Actualizar".

---

## LAYOUTS (8)

| Archivo | Auth | Props | Hierarchy |
|---------|------|-------|-----------|
| `app-layout.tsx` | autenticado | `children`, `breadcrumbs?` | Render `AppLayoutTemplate` (sidebar) + atajos + help modal. Monta banners globales de billing (#193): `SuspendedBanner` cuando `activeCompany.status === 'suspended'`, `PastDueBanner` cuando `'past_due'` (mutuamente excluyentes). Incluye `PaymentBlockedFlashListener` que lee `props.flash.payment_blocked` (emitido por `EnsureCompanyNotBlocked` al redirigir desde una ruta web bloqueada) y dispara un toast `error` accionable. |
| `auth-layout.tsx` | público | `children`, `title`, `description` | Wrapper para auth pages |
| `layouts/app/app-sidebar-layout.tsx` | autenticado | `children`, `breadcrumbs?` | Variante con sidebar persistente. Renderiza `<CashRegisterAlertBanner>` debajo del header — banner global cuando caja cerrada + menú activo + horario hábil |
| `layouts/app/app-header-layout.tsx` | autenticado | `children`, `breadcrumbs?` | AppShell + AppHeader + AppContent |
| `layouts/auth/auth-simple-layout.tsx` | público | `children`, `title`, `description` | Una columna centrada |
| `layouts/auth/auth-card-layout.tsx` | público | `children`, `title`, `description` | Tarjeta centrada con logo |
| `layouts/auth/auth-split-layout.tsx` | público | `children`, `title`, `description` | Dos columnas (decorativa + form) |
| `layouts/settings/layout.tsx` | autenticado | `children` | Layout de subnavegación de `/settings/*` |

### Atajos de teclado (motor `GlobalShortcuts`)

> Rediseño anti-conflictos (#50). Antes eran `Alt+<letra>` (chocaban con macOS Option-chars y mnemónicos de menú de Firefox/Windows) y además estaban inertes tras la migración SPA. Ahora la navegación usa **secuencias con tecla líder `G`** ("go to"): se pulsa `G` y luego la tecla del destino. Sin modificadores e inactivos en inputs → no se cruzan con navegador/SO.

| Atajo | Acción | Ruta |
|-------|--------|------|
| `G` luego `D` | Dashboard | `/dashboard` |
| `G` luego `O` | Órdenes › Tablero | `/orders/board` |
| `G` luego `C` | Órdenes › Caja | `/orders/cashier` |
| `G` luego `V` | Órdenes › Ventas del día | `/orders/deliveries` |
| `G` luego `M` | Menú | `/menu` |
| `G` luego `S` | Chats | `/chats` |
| `G` luego `P` | Cupones | `/coupons` |
| `G` luego `H` | Horarios | `/hours` |
| `G` luego `E` | Mi Empresa › Información | `/company/settings` |
| `G` luego `F` | Mi Empresa › Configuraciones | `/company/preferences` |
| `G` luego `T` | Mi Empresa › Métricas | `/company/metrics` |
| `G` luego `R` | Mi Empresa › Informes | `/company/reports` |
| `G` luego `U` | Identidades › Usuarios | `/identities/users` |
| `G` luego `L` | Identidades › Roles | `/identities/roles` |
| `Ctrl/Cmd + .` | Toggle barra lateral | — (en `ui/sidebar.tsx`) |
| `?` | Modal de ayuda | — |

Mapa canónico en `src/lib/shortcuts.ts` (`APP_SHORTCUTS`). Motor de secuencias en `src/components/global-shortcuts.tsx`, montado en `src/layouts/spa-app-layout.tsx`. Acordes con modificador validados contra `RESERVED_SHORTCUTS` (`src/hooks/use-keyboard-shortcut.ts`). Inactivos cuando el foco está en input/textarea/contenteditable (guard en `keydown` + `focusin` cancela cualquier secuencia armada al entrar a un campo).

**Sostener `G`** (~350ms) abre `src/components/shortcut-palette.tsx`: overlay que oscurece la UI y lista los destinos con su segunda tecla (para elegir sin soltar `G`). Se cierra al soltar `G`, con `Esc`, o al perder foco la ventana.

---

## LIB UTILITIES (15)

| Archivo | Exports | Notas |
|---------|---------|-------|
| `lib/api.ts` | `apiFetch(url, options)` | Wrapper de fetch. Inyecta `Authorization: Bearer`, `?token=`, `credentials: include`. Maneja `X-Cookie-Migrated`, `X-Refresh-Token`, 401 de sesión revocada. `console.debug` activo |
| `lib/token.ts` | `getToken()`, `setToken()`, `clearToken()`, `subscribeToken()`, `markCookieMigrated()`, `AUTH_MARKER` | localStorage key `company_token`. Sincroniza con suscriptores. Escucha eventos `navigate` de Inertia |
| `lib/utils.ts` | `cn(...inputs)` | Combina `clsx` + `tailwind-merge` |
| `lib/formatters.ts` | `formatCOP`, **`formatCurrency`** (canónico COP con símbolo, trunca a peso §13), `formatMonthYear`, `formatDate`, `formatInvoicePeriod`, `nextBillingDate`, `formatNumber`, `formatPhoneCO`, `formatDelta` | Todos en `es-CO`. `formatCurrency` reemplazó ~29 copias locales (auditoría 2026-07-01); `use-currency-formatter` quedó deprecado como alias. `formatPhoneCO` convierte `573XXXXXXXXXX` ↔ `+57 3XX XXX XXXX` |
| `lib/calculate-discount.ts` | `calculateDiscount(coupon, total)` | Cálculo monto descuento |
| `lib/coupon-helpers.ts` | `getCouponStatus`, `getDiscountLabel`, `formatScheduleSummary`, `WEEKDAYS_ES` | Helpers de UI para cupones (formato tipo, valor, estado, resumen de programación happy hour #125) |
| `lib/generate-coupon-code.ts` | `generateCouponCode()` | Random alfanumérico mayúscula |
| `lib/shortcuts.ts` | `APP_SHORTCUTS`, `LEADER_KEY`, `SEQUENCE_TIMEOUT_MS`, `TOOLTIP_DELAY_MS` | Mapa canónico de atajos (navegación por secuencias `G`+tecla). La lista de combinaciones reservadas (navegador+SO) vive en `hooks/use-keyboard-shortcut.ts` (`RESERVED_SHORTCUTS`) |
| `lib/order-status.ts` | `ORDER_STATUS_FALLBACK`, `statusLabel`, `statusBadgeClass`, `isOperational`, `isRevenue`, `isTerminal` | SSoT de estados de orden en frontend. Fallback embebido espejo de `config/orders.php`. Consume shared props vía `useOrderStatuses()` |
| `lib/tax.ts` | `calculateTaxLine(price, qty, rate, included)`, `aggregateTax(lines)` | Espejo del backend `TaxCalculator` para preview UX en caja/mesas. El backend recalcula al persistir |
| `lib/datetime.ts` | `toBogotaDate`, `parseBogotaIso`, `formatBogotaTime`, `dayOfWeekShort` | Helpers timezone `America/Bogota`. Evita drift del navegador del operador |
| `lib/route-compat.ts` | `route(name, params)`, `routeBackend(name, params)`, `routeExists(name)` | Resolución de rutas con nombre (#220). `route()` → path relativo del SPA; `routeBackend()` → URL absoluta al backend (prefija `VITE_API_URL`) para `<a href>` cross-origin como el OAuth de Google. Ver sección "ROUTES" abajo |
| `lib/route-map.ts` | `ROUTE_MAP` | Mapa `nombre → template` autogenerado desde `php artisan route:list`. Lo consume `route-compat.ts` |

Otros archivos del directorio (`escpos-printer.ts`, helpers internos de cupones) se usan exclusivamente dentro de componentes específicos y no exponen API a otras partes del frontend.

---

## TYPES (9 archivos)

Listado:
- `types/index.ts` — tipos compartidos (auth, navegación, RBAC, empresa, menú, deliveries, dashboard, métricas, reportes, facturación, clientes, alertas).
- `types/billing.ts` — modelos de plan, suscripción, factura, descuento.
- `types/business-hours.ts` — `BusinessHour`, `BusinessHourException`, `BusinessHoursStatus`.
- `types/coupon.ts` — `Coupon`, `CouponRedemption`, `CouponValidationResponse`, `CouponFormData`, `ActiveAutoApply` (#125).
- `types/inventory.ts` — `Ingredient`, `IngredientStock`, `IngredientMovement`, `Warehouse`, `InventoryValuation`, enums de tipos de movimiento (#111, #120).
- `types/purchases.ts` — `PurchaseOrder`, `PurchaseOrderItem`, `PurchaseOrderAttachment`, `PurchaseCreditNote`, enums de estado y método de pago (#118).
- `types/recipes.ts` — `Recipe`, `RecipeItem`, `RecipeCost` (#112).
- `types/suppliers.ts` — `Supplier`, `SupplierIngredient` (#118).
- `types/vite-env.d.ts` — referencias de tipo para `import.meta.env.VITE_*`.

### `types/index.ts`

| Interfaz | Dominio | Campos clave |
|----------|---------|--------------|
| `Auth` | Auth | `user: User` |
| `User` | Auth | `id, name, email, email_verified_at` |
| `BreadcrumbItem` | Navegación | `title, href` |
| `NavItem` | Navegación | `title, href?, icon?, permission?, children?` |
| `NavGroup` | Navegación | `title, items: NavItem[]` |
| `Company` | Empresa | `id, nit, commercial_name, status` |
| `CompanySettings` | Empresa | branding, contacto, datos bancarios |
| `CompanyRole` | RBAC | `id, name, color, is_system, permissions` |
| `CompanyRolePermission` | RBAC | `feature_id, can_create/read/update/delete` |
| `CompanyMember` | RBAC | `user_id, user, role, custom_permissions` |
| `RestaurantMenu` | Menú | `id, name, status, categories, schedule_days` |
| `MenuCategory` | Menú | `id, name, description, sort_order, items` |
| `MenuItem` | Menú | `id, name, description, price, available, image_url` |
| `Delivery` | Domicilios | `id, status, user_id, order_id, assigned_at` |
| `DeliveryMetric` | Domicilios | Métricas por repartidor/período |
| `DeliveryPagination` | Domicilios | `data[], current_page, last_page, total` |
| `Period` | Dashboard | `'today' \| 'week' \| 'month'` |
| `DashboardPageProps` | Dashboard | Todos los interfaces de métricas |
| `MetricKpis`, `MetricActiveOrders`, `MetricTopItems`, `MetricHeatmap`, `MetricCartAbandonment` | Métricas | Por endpoint |
| `Invoice` | Facturación | `id, type, period_from, period_to, status, amount` |
| `ReportSummary` | Reportes | `total_orders, successful, cancelled, abandoned, total_revenue` (`total_expenses` se eliminó) |

### `types/billing.ts`

| Tipo | Valores |
|------|---------|
| `InvoiceStatus` | `'draft' \| 'open' \| 'paid' \| 'void' \| 'uncollectible'` |
| `InvoiceType` | `'subscription' \| 'proration' \| 'one_time'` |
| `SubscriptionStatus` | `'active' \| 'past_due' \| 'canceled' \| 'trialing'` |
| `CompanyBillingStatus` | `'active' \| 'suspended' \| 'pending'` |
| `BillingPlan` | `id, name, price, interval, features[]` |
| `BillingInvoice` | `id, status, amount_due, paid_at, lines[]` |
| `PaginatedInvoices` | `data[], current_page, last_page, total` |

### `types/business-hours.ts`

| Tipo | Campos |
|------|--------|
| `BusinessHour` | `day_of_week (0–6, 0=domingo), open_time, close_time, is_enabled` |
| `BusinessHourException` | `exception_date, is_open, open_time?, close_time?, reason?` |
| `RestaurantStatus` | `is_open: boolean, reason: string, next_change: string` |
| `BusinessHourFormData` | DTO `updateHours` |
| `BusinessHourExceptionFormData` | DTO crear/editar excepción |

### `types/coupon.ts`

| Tipo | Valores / Campos |
|------|------------------|
| `CouponType` | `'percentage' \| 'fixed_amount'` |
| `CouponStatus` | `'active' \| 'inactive' \| 'exhausted'` |
| `Coupon` | `id, code, type, value, expires_at, max_uses, uses_count, first_order_only` |
| `CouponRedemption` | `id, coupon_id, order_id, redeemed_at, discount_amount` |
| `CouponFormData` | DTO crear/editar |
| `PaginatedResponse<T>` | Genérica |

### `types/vite-env.d.ts`

Ambient declarations para `import.meta.env.VITE_*`.

---

## CONTROLES DE PERMISOS EN EL FRONTEND

| Ubicación | Mecanismo | Alcance |
|-----------|-----------|---------|
| `NavItem.permission` | Sidebar oculta items que el usuario no tiene | Visibilidad navegación |
| Props `canCreate / canUpdate / canDelete` | Pasados desde response API a componentes | Visibilidad de botones |
| `actorPermissions` en `UserPermissionsEditor` | `disabledCheck` impide otorgar fuera del scope del actor | Edición granular |
| `can_manage` del response API | `Roles.tsx` muestra Crear sólo si `true` | Acceso a gestión de roles |
| `is_system` en `CompanyRole` | Edit/Delete ocultos en UI | Mutabilidad de roles |
| Gate web (route closure) | `FeaturePermissionService::hasPermission()` antes de renderizar | Bloqueo de página completa con redirect a `/dashboard` |

---

## SUBIDA DE ARCHIVOS (límites)

| Página/Componente | Tipo | Tamaño máximo | Formatos |
|-------------------|------|---------------|----------|
| `company/settings.tsx` (logo) | logo restaurante | **5 MB** | PNG, JPG, JPEG, WEBP, SVG |
| `company/settings.tsx` (QR) | QR pagos | **5 MB** | PNG, JPG |
| `enrollment/company.tsx` (QR) | QR pagos | **5 MB** | PNG, JPG |
| `menu/show.tsx` + `image-upload-zone.tsx` | imagen plato | **2 MB** | JPG, PNG |

`use-image-upload.ts` valida client-side antes de enviar; backend valida con `mimes:` + `max:5120` (KB) en form requests.

---

## SISTEMA DE NOTIFICACIONES (TOAST)

`useToast()` (en `components/ui/toast.tsx`) expone `showToast(level, message)` con niveles `'success' | 'error' | 'warning' | 'info'`. Usado por:
- Errores de export PDF
- Errores de validación inline (cuando no hay form errors estructurados)
- Confirmaciones de acciones (orden creada, cupón aplicado, etc.)

Inertia `flash` props (success/error/warning) también se renderizan automáticamente en el layout.

---

## ROUTES — Helpers `route()` y `routeBackend()`

La resolución de rutas con nombre vive en `lib/route-compat.ts` (#220) y
reemplaza al `route()` global de Ziggy. Resuelve nombres contra `ROUTE_MAP`
(`lib/route-map.ts`), el mapa autogenerado desde `php artisan route:list`.

```ts
import { route, routeBackend } from '@/lib/route-compat';

route('orders.board');         // → /orders/board   (path relativo del SPA)
route('menu.show', { id: 42 }); // → /menu/42
routeBackend('auth.google');   // → https://panel-api.flexyflow.co/auth/google
```

### Cuándo usar `route()` vs `routeBackend()`

| Helper | Devuelve | Usar para |
|--------|----------|-----------|
| `route(name, params)` | Path relativo (`/dashboard`) | `<a href>` y navegación a rutas del SPA — las sirve el Worker de Cloudflare |
| `routeBackend(name, params)` | URL absoluta al backend (prefija `VITE_API_URL`) | `<a href>` o `window.location.href` top-level que debe ir **cross-origin al backend Laravel**: hoy solo el flujo OAuth de Google (`auth.google`) |

El SPA (`bistro.flexyflow.co`) y la API (`panel-api.flexyflow.co`)
viven en hosts distintos. Un `<a href>` con path relativo a `/auth/google`
caería en el Worker SPA, que no maneja esa ruta → **404**. `routeBackend()`
antepone `VITE_API_URL` para que la navegación llegue al backend real.

`routeBackend()` **no** se usa para llamadas a la API (`fetch`/XHR): `apiFetch`
(`lib/api.ts`) ya antepone el host del backend por su cuenta.

En dev `VITE_API_URL` queda vacío → `routeBackend()` devuelve un path relativo
que resuelve el proxy de Vite (same-origin). El valor de PDN vive en
`bistro/frontend/.env.production`.

---

## LIMITACIONES CONOCIDAS

| Área | Limitación |
|------|-----------|
| `pages/chats.tsx` | Polling 5s; sin WebSocket todavía |
| `pages/cart.tsx` | El frontend no permite agregar/quitar items (los pobla el bot/backend) |
| `hooks/use-relative-time.ts` | Sólo español |
| `hooks/use-currency-formatter.ts` | Hardcoded a COP |
| `lib/api.ts` | `console.debug` activo en producción |
| `lib/token.ts` | Token marker en localStorage; cookie HttpOnly es la fuente de verdad |
| `pages/billing/index.tsx` | Warning si conteo de facturas supera umbral configurable |
| `components/metrics/dish-ranking-panel.tsx` | Depende del padre para datos; sin fetch propio |
| `use-period-filter.ts` | Sólo funciona con deferred props del dashboard |
| Polling reducido | `users/menu/hours/company-settings` sin polling automático — sólo botón "Actualizar" para reducir carga del backend |

---

## Impresoras térmicas (issue #116)

| Archivo | Propósito |
|---------|-----------|
| `resources/js/pages/company/printers.tsx` | Lista + modal CRUD de impresoras (`/company/printers`); botón "Probar" encola comanda de prueba |
| `resources/js/pages/company/settings.tsx` | Tab "Impresoras" agregado: redirige a `/company/printers` |

Endpoints consumidos: `GET/POST/PUT/DELETE /api/v1/company/printers` y `POST /api/v1/company/printers/{id}/test`.

---

## Tipografía de marca — FlexyFont (issue #109)

| Archivo | Propósito |
|---------|-----------|
| `public/fonts/FlexyFont.{otf,woff2}` | Fuente de marca (woff2 ~22 KB primario, otf ~50 KB fallback). Servida con `Cache-Control: max-age=31536000, immutable` desde `.htaccess` |
| `storage/fonts/FlexyFont.otf` | Original leído por el comando `php artisan fonts:install-brand` para generar las variantes y métricas que dompdf necesita |
| `storage/fonts/flexyfont_{normal,bold,italic,bold_italic}.{otf,ufm}` + `installed-fonts.json` | Generados por `fonts:install-brand`. dompdf resuelve `font-family: 'flexyfont'` desde aquí (no usa `@font-face` inline porque interpreta `D:\…` como protocolo no permitido). |
| `resources/css/app.css` | 4 declaraciones `@font-face` separadas por peso (400/500/600/700, woff2 primero, otf fallback, `font-display: swap`) + token `--font-brand` en `@theme` → genera utility `font-brand` de Tailwind v4 |
| `resources/views/app.blade.php` | `<link rel="preload" href="/fonts/FlexyFont.woff2" crossorigin>` antes del CSS para evitar FOUT |
| `resources/views/pdf/partials/_fonts.blade.php` | Solo declara `.font-brand { font-family: 'flexyfont', Arial, sans-serif }` (incluido en cada plantilla `pdf/*.blade.php`); el registro real de la fuente está pre-instalado en `storage/fonts/` |

**Lugares donde se aplica `font-brand`** (verificado con Playwright vs el patrón del wiki público):

| Componente | Texto | Por qué |
|------------|-------|---------|
| `pages/welcome.tsx` | Wordmark `restaurantes flexyflow` + H1 `Accede a tu cuenta` | Punto de entrada de marca antes del login |
| `layouts/auth/auth-{simple,split}-layout.tsx` | H1 del título de pantalla | Auth flujos secundarios (registro, password reset) |
| `pages/**/*.tsx` (27 páginas) | H1 principal de cada página (`Dashboard`, `Caja`, `Pedidos`, `Menú`, `Cupones`, etc.) | Identidad consistente en titular de cada vista |
| `components/app-logo.tsx` | Wordmark del header móvil | Brand moment en navegación |
| `components/app-sidebar.tsx` (`<SidebarHeader>`) | Nombre del restaurante activo en el sidebar | Brand moment validado visualmente con el usuario |

**Lugares donde NO se aplica** (decisión de legibilidad y densidad): tablas, KPI numéricos, formularios, badges, botones, tooltips, `CardTitle`, breadcrumbs, items del nav del sidebar, items de pedidos, fechas, NITs, montos. Body sigue en `Instrument Sans` (web) y `Arial`/`Courier New` (PDFs).

---

## Inventario de insumos (issue #111)

| Archivo | Propósito |
|---------|-----------|
| `pages/inventory/index.tsx` | Página principal `/inventory` con tabla de insumos, filtros (búsqueda, categoría, bajo mínimo, archivados), resumen (total / bajo / valorización visible) y acciones por fila. Honra `?low_stock=1` proveniente del banner del dashboard. |
| `pages/inventory/components/IngredientFormModal.tsx` | Alta/edición. En alta opcional `initial_stock` + `initial_cost` que se traduce a un movimiento `entry` inicial. |
| `pages/inventory/components/RecordEntryModal.tsx` | Entrada (cantidad + costo unitario + referencia). El costo se promedia ponderado contra el stock existente. |
| `pages/inventory/components/RecordWasteModal.tsx` | Merma (cantidad + motivo obligatorio). |
| `pages/inventory/components/AdjustStockModal.tsx` | Ajuste manual ± con motivo obligatorio. No recalcula costo. |
| `pages/inventory/components/MovementsDrawer.tsx` | Sheet lateral con historial paginado por insumo. |
| `hooks/use-inventory.ts` | Hook con state de filtros + CRUD + endpoints de movimientos (`recordEntry`, `recordWaste`, `recordAdjustment`) + `fetchMovements`. Refrescado tras cada mutación. |
| `types/inventory.ts` | Tipos `Ingredient`, `IngredientMovement`, `IngredientFormPayload`, `IngredientListResponse`, `MovementResponse`. |
| `components/app-sidebar.tsx` | Ítem top-level "Inventario" (icono `Package`) entre "Menú" y "Chats", `permission: 'inventory.read'`. |
| `pages/dashboard.tsx` | Banner amarillo `lowStockInventory` con top-5 insumos bajo mínimo + CTA → `/inventory?low_stock=1`. Solo aparece si la prop diferida llega con `count > 0` (oculto si el usuario carece de `inventory.read`). |

**Convenciones de UI**
- Cantidades formateadas con `Intl.NumberFormat('es-CO', { maximumFractionDigits: 3 })`.
- Costos formateados como `COP` sin decimales.
- Estados visuales: `✓ OK` (emerald), `⚠ Bajo` (amber), `Archivado` (slate).
- Acciones por fila con icon-buttons (`ArrowUpCircle` entrada, `ArrowDownCircle` merma, `Sliders` ajuste, `History` historial, `Pencil` editar, `Archive` archivar). Insumos archivados ocultan acciones de mutación y ofrecen botón "Restaurar".

---

## Compras a proveedores (issue #118)

| Archivo | Propósito |
|---------|-----------|
| `pages/suppliers/index.tsx` | Página `/suppliers`. Tabla CRUD con filtros (búsqueda, archivados). Restaurar 1-click. |
| `pages/suppliers/components/SupplierFormModal.tsx` | Alta/edición. Captura `document_type`, `document_number`, contacto, plazo de pago. |
| `pages/purchases/index.tsx` | Página `/purchases`. Tabla con filtros (estado, proveedor, búsqueda, reintegro pendiente). Click sobre fila abre el drawer de detalle. Banner si no hay proveedores/insumos. |
| `pages/purchases/components/PurchaseOrderEditor.tsx` | Modal de alta/edición de borrador. Líneas dinámicas (insumo, qty, costo neto, IVA %). Totales (subtotal/tax/total) en vivo. |
| `pages/purchases/components/PurchaseOrderDetailDrawer.tsx` | Sheet lateral con cabecera, líneas, notas crédito, adjuntos y acciones contextuales según estado (Confirmar / Recibir / Pagar / Anular / Cancelar / Saldar reintegro). |
| `pages/purchases/components/MarkPaidModal.tsx` | Selector de método (cash/card/transfer) + referencia obligatoria si ≠ cash. |
| `pages/purchases/components/VoidPOModal.tsx` | `ReasonPromptModal` reutilizable: prompt de motivo (≥5 chars) usado para anular y para cancelar. |
| `pages/purchases/components/AttachmentsPanel.tsx` | Subir/descargar/eliminar adjuntos PDF/JPG/PNG. Storage local. |
| `hooks/use-suppliers.ts` | CRUD + filtros + restore. |
| `hooks/use-purchases.ts` | CRUD + transiciones (`submit`, `receive`, `pay`, `cancel`, `void`, `settleRefund`) + adjuntos (`uploadAttachment`, `deleteAttachment`, `downloadAttachmentUrl`). |
| `types/suppliers.ts` | `Supplier`, `SupplierFormPayload`, `SupplierListResponse`. |
| `types/purchases.ts` | `PurchaseStatus`, `PurchasePaymentMethod`, `PurchaseOrderSummary/Detail`, `PurchaseOrderItem/Payload`, etiquetas (`STATUS_LABELS`, `PAYMENT_LABELS`, `ATTACHMENT_LABELS`). |
| `components/app-sidebar.tsx` | Grupo top-level "Compras" (icono `ShoppingCart`, `permission: 'purchases.read'`) con sub-items "Órdenes de compra" (`ShoppingCart`) y "Proveedores" (`Truck`, `permission: 'suppliers.read'`). |

**Convenciones de UI**
- Códigos en `font-mono` (`PO-000001`, `NC-000001`).
- Estados con `Badge` coloreado: `draft` gris, `pending` ámbar, `received` azul, `paid` esmeralda, `cancelled`/`voided` rosa.
- Bandera "Reintegro pendiente" como `Badge` rojo + ícono `AlertTriangle` cuando `pending_supplier_refund=true`.
- Costos en COP sin decimales; cantidades con hasta 3 decimales.
- Acciones contextuales — solo aparecen los botones legales para el estado actual (alineado con `config('purchases.transitions')`).
- Anulación post-recepción usa botón `destructive` y exige motivo ≥5 chars.

---

## Recetas (BOM) en el editor de menú + kanban forward-only (issue #112)

### Recetas

| Archivo | Rol |
|---------|-----|
| `resources/js/types/recipes.ts` | `RecipeLine`, `RecipeResponse`, `RecipeUpsertPayload` |
| `resources/js/types/index.ts` | `MenuItem` añade `cost_source` (`recipe|manual`) y `has_recipe` |
| `resources/js/hooks/use-recipes.ts` | `fetchRecipe(menuId, itemId)` y `upsertRecipe(menuId, itemId, payload)` |
| `resources/js/components/menu/recipe-editor-modal.tsx` *(nuevo)* | Modal con tabla editable de líneas, autocomplete de ingredientes (filtrado por unidades compatibles), recálculo en vivo de costo total + margen, badge `⚠ bajo` si margen < umbral. Reemplaza el set completo en backend. Costeo multibodega: cada línea tiene selector de **bodega** (limitado a las asignadas a la sede activa, default = bodega predeterminada de la sede) y envía `warehouse_id?` en el upsert; el costo unitario se toma del WAC por bodega (`stocks[].current_cost`); líneas sin stock en la bodega elegida se marcan con badge `Sin costo en esta bodega` (`misconfigured`). |
| `resources/js/components/menu/item-card.tsx` | Botón con ícono `ChefHat` por ítem que abre el `RecipeEditorModal`. Verde si tiene receta, gris si no. |
| `resources/js/components/menu/item-form-modal.tsx` | Campo `Costo (COP)` queda **read-only** + badge `desde receta` cuando `item.has_recipe`. La edición se delega al modal de receta. |

**Convenciones**
- Las cantidades se muestran con hasta 3 decimales; el costo en COP con `useCurrencyFormatter`.
- El selector de unidad por línea se filtra automáticamente a las dimensiones compatibles con el insumo elegido (g↔kg, ml↔l, un=un).
- Guardar receta vacía limpia todas las líneas activas (soft-archive en backend).

### Tablero de órdenes — regla forward-only

| Archivo | Cambio |
|---------|--------|
| `resources/js/pages/orders/board.tsx` | Cada estado del kanban tiene `rank` ordinal (sincronizado con `config('orders.kanban_rank')`). `handleDragEnd` valida `rankOf(target) >= rankOf(actual)` antes de invocar `updateStatus`; muestra alerta si se intenta ir hacia atrás. `DroppableColumn` deshabilita `useDroppable` para columnas con rank menor durante un drag activo y aplica `opacity-50` + `cursor-not-allowed`. |

**Comportamiento UX**
- Mientras se arrastra una orden, las columnas anteriores aparecen tenues y no aceptan el drop.
- El backend rechaza con `422` cualquier intento por API directo; la UI valida primero para evitar el round-trip y dar feedback inmediato.
- Si se desea anular o devolver una orden, se usan los endpoints dedicados `/cancel` y `/refund` (no el kanban).

---

## Food cost en /company/metrics + threshold en preferencias (issue #113)

Sección **Costo de alimentos (food cost)** en el dashboard de métricas, alimentada por `GET /api/v1/metrics/foodcost/summary` (snapshot histórico fiel de `orders.items[].cost`).

### Archivos

| Archivo | Tipo | Notas |
|---------|------|-------|
| `resources/js/types/index.ts` | type | Nuevos tipos: `FoodCostSummary`, `FoodCostItem`, `FoodCostTotals`, `FoodCostSnapshotMeta`, `FoodCostHistory`, `FoodCostHistoryPoint`. Añade `food_cost_alert_threshold: string` a `CompanySettings`. |
| `resources/js/hooks/use-food-cost.ts` | hook *(nuevo)* | `fetchSummary(period, dateFrom, dateTo)` y `fetchItemHistory(itemId, period, dateFrom, dateTo)`. |
| `resources/js/components/metrics/food-cost-panel.tsx` | componente *(nuevo)* | KPIs (gross, costo, % costo/ventas, cobertura), tabla con badge **✓ / ⚠ baja / N/D** según threshold, **scatter plot precio vs costo** con línea diagonal de equilibrio (recharts), modal con sparkline histórica por ítem, banner amarillo si `scheduler_lag_hours > 26`. Botón **Refrescar** (sin polling — carga inicial + manual). |
| `resources/js/pages/metrics/index.tsx` | page | Importa y monta `<FoodCostPanel>` después de `<DishMarginPanel>`. Recibe `foodCostAlertThreshold` como prop Inertia (resuelto server-side en `routes/web.php` para evitar fetch extra). |
| `resources/js/components/metrics/dish-margin-panel.tsx` | componente | Texto helper reformulado en lenguaje no-técnico: "Solo aparecen los platos a los que les pusiste un costo. Anotamos cuánto te costaba el plato en el momento de la venta…". |
| `resources/js/pages/company/preferences.tsx` | page | Nueva tarjeta **Reportes** con input de % (1–99) que persiste como decimal (`0.30`). Usa el mismo flujo `saveSection('reports', ['food_cost_alert_threshold'])` que las otras secciones. |

### Comportamiento UX

- **Items sin costo (N/D):** aparecen en la tabla con badge gris pero NO entran al scatter plot ni al `cost_ratio` agregado. La cobertura del cálculo se muestra explícita como "X de Y unidades con costo conocido".
- **Status del plato:**
  - ✓ verde: `margin_pct/100 ≥ threshold`.
  - ⚠ rojo "baja": `margin_pct/100 < threshold`.
  - N/D gris: `has_cost = false`.
- **Histórico:** botón "ver" en cada fila abre modal con sparkline de los últimos 30 días. Si el plato fue eliminado del menú activo, badge "plato eliminado" + datos del último snapshot conservado.
- **Sin polling:** `enabled` del panel solo se activa cuando hay token + custom dates aplicadas (consistente con el resto de paneles del módulo).
- **Tono:** copy del panel y del scatter explica conceptos sin jerga ("La línea diagonal es el punto en que el precio iguala al costo. Mientras más arriba a la izquierda esté un plato, más ganas con él").

---

## Menu engineering en /company/metrics (issue #114)

Sección **Menu engineering** justo debajo de Food cost. Cruza popularidad (% de unidades del plato sobre el total) contra margen unitario absoluto en COP (precio − costo) y clasifica en estrellas / vacas lecheras / puzzles / perros usando la mediana de cada eje como umbral.

| Archivo | Tipo | Cambios |
|---------|------|---------|
| `resources/js/types/index.ts` | type | Nuevos tipos: `MenuEngineeringQuadrant` (`star\|cow\|puzzle\|dog`), `MenuEngineeringDish`, `MenuEngineeringSummary`, `MenuEngineeringMatrix`. |
| `resources/js/hooks/use-menu-engineering.ts` | hook *(nuevo)* | `fetchMatrix(period, dateFrom, dateTo)` contra `GET /api/v1/metrics/menu-engineering`. |
| `resources/js/components/metrics/menu-engineering-panel.tsx` | componente *(nuevo)* | 4 tarjetas KPI (una por cuadrante con emoji + conteo), **scatter plot interactivo** con `ReferenceLine` en mediana X/Y dividiendo los 4 cuadrantes (recharts), tooltip con recomendación textual, tabla complementaria con badge de cuadrante y acción sugerida. Banner amarillo si hay platos sin costo (cobertura < 100%). Botón **Refrescar** (sin polling). |
| `resources/js/pages/metrics/index.tsx` | page | Importa y monta `<MenuEngineeringPanel>` después de `<FoodCostPanel>`. |

### Comportamiento UX

- **Items sin costo NO se muestran** en el scatter ni en la tabla — solo se reportan en el banner como contador ("N platos vendidos no tienen costo registrado"). Evita ruido visual y matemáticas engañosas (la mediana solo se calcula sobre items clasificables).
- **Recomendaciones por cuadrante** (textuales, no acciones ejecutables):
  - ⭐ Estrella: promociónalo y mantén su calidad.
  - 🐄 Vaca lechera: sube el precio gradual o reduce su costo.
  - 🧩 Puzzle: empújalo con happy hour o relocación en el menú.
  - 🐕 Perro: considera retirarlo o rediseñarlo.
- **Período por defecto:** hereda del selector de la página (`month` por default — mediana estable).
- **Tamaño del punto en el scatter** = unidades vendidas (`ZAxis range [40, 280]`).

---

## DOCUMENTACIÓN COMPLEMENTARIA

| Recurso | Ubicación | Propósito |
|---------|-----------|-----------|
| Wiki frontend | `../docs/wiki/Frontend.md` | Arquitectura, convenciones, catálogo de componentes, patrones (Inertia v2, formularios, polling, permisos) |
| Guía visual | `FRONTEND_UI_GUIDELINES.md` | Paleta, espaciados, ejemplos UI |
| Wiki por dominio | `../docs/wiki/` | Cada feature tiene su página con contratos API |

Cuando agregues una página, componente o hook reutilizable, actualiza este archivo y, si toca patrones de uso o convenciones, también `docs/wiki/Frontend.md`.

---

## PWA (issue #103 — Fase 1)

### Componentes PWA — `resources/js/components/pwa/`

| Archivo | Propósito |
|---|---|
| `install-pwa-prompt.tsx` | Banner sticky en `/dashboard` que captura `beforeinstallprompt` y dispara `prompt()`. Se oculta en `display-mode: standalone`. Dismiss persiste 14 días en `localStorage` (`pwa_install_dismissed_at`). |
| `ios-install-hint.tsx` | Modal-banner solo para iOS Safari (sin `beforeinstallprompt`). Muestra instrucciones "Compartir → Añadir a pantalla de inicio". Dismiss persiste 14 días (`pwa_ios_hint_dismissed_at`). |
| `update-available-toast.tsx` | Toast superior con botón "Recargar" cuando se detecta `pwa:update-available` (emitido por `app.tsx` cuando Workbox detecta un SW en `waiting`). |

### Registro del Service Worker

- `resources/js/app.tsx` importa `workbox-window` perezosamente y registra `/sw.js` solo en `import.meta.env.PROD`.
- Listener `waiting` emite `window.dispatchEvent(new CustomEvent('pwa:update-available'))`. El toast lo escucha en cualquier página donde esté montado.

### Build / Vite

- Plugin `vite-plugin-pwa` (Workbox `generateSW`) configurado en `vite.config.js`.
- `manifest: false` — el manifest lo sirve Laravel (ver `BACKEND_FILES.md`), no el plugin.
- Estrategias runtime: `NetworkFirst` para APIs GET del POS, `CacheFirst` para imágenes y fuentes.

### Iconos

- `public/icons/icon-{192,512}{,-maskable}.png`, `public/icons/apple-touch-icon-180.png`, `public/favicon.{ico,svg}`.
- **Fuente única: `bistro/branding/`** (ver `bistro/branding/README.md`). El set desplegable vive en `branding/web/**` y las apps lo **heredan** vía `branding/sync.mjs`, cableado en `predev`/`prebuild` del frontend (`npm run sync:branding` a mano). El backend hereda las mismas copias (`node branding/sync.mjs`). NO editar los assets dentro de `*/public/` — se pisan en cada sync.
- El ícono es la "b" de la FlexyFont al 90% del alto (5% margen). **Default: fondo blanco `#f6f5f3` + letra oscura `#1E232E`.** El favicon SVG (`/favicon.svg`) es **adaptativo al theme del sistema** vía `@media (prefers-color-scheme: dark)` embebido: en dark invierte a fondo oscuro + letra clara. `theme-color` también adapta (metas con `media` en `index.html`). Los PNG del PWA son estáticos (default blanco); el launcher instalado no adapta por limitación de plataforma, pero el manifest incluye `/favicon.svg` como ícono `any` para navegadores que sí lo rasterizan por theme.
- Cuando una empresa sube su logo, los iconos por-empresa se rasterizan server-side vía `App\Services\LogoIconRasterizer` y se sirven dinámicamente desde el manifest; `DynamicFavicon` (app-sidebar-layout) usa el logo de la empresa activa o cae a `/favicon.svg`.

### Modo offline — Fase 2 (issue #140)

#### Capa de datos `resources/js/lib/offline/`

| Archivo | Propósito |
|---|---|
| `db.ts` | Wrapper `idb` sobre IndexedDB. Object stores: `pending_orders` (key=client_uuid, índices by-company, by-created), `cached_menu`, `cached_cash_session`, `sync_log`. Helpers: `putPendingOrder`, `getPendingOrders`, `countPendingOrders`, `requestPersistentStorage`, `estimateStorageUsage`, `clearAllOfflineData`. |
| `sync-engine.ts` | Cola de sync con backoff exponencial (1s→2s→4s→8s→16s→32s→60s + jitter). Filtra por empresa activa. Listeners `online`/`offline` + polling cada 30s. API: `startSyncEngine`, `setActiveCompanyForSync`, `subscribeSyncState`, `runSync`, `refreshPendingCount`, `exportPendingsAsJson`. |
| `uuid.ts` | `uuidv4()` usando `crypto.randomUUID` con fallback puro JS. |

#### Componentes UI `resources/js/components/offline/`

| Archivo | Propósito |
|---|---|
| `offline-banner.tsx` | Banner sticky en `app-sidebar-layout`. Tres niveles: amarillo (online + pending), naranja (offline + pending), rojo (>5 min offline = riesgo de pérdida) con CTA Exportar JSON. |
| `sync-toast.tsx` | Toast efímero "✓ Sincronizadas N operaciones" cuando la cola se drena. |
| `storage-quota-warning.tsx` | Banner cuando `navigator.storage.estimate()` reporta uso >80% (amarillo) o >95% (rojo crítico). |

#### Métricas

| Archivo | Propósito |
|---|---|
| `resources/js/components/metrics/offline-operation-panel.tsx` | KPIs en `/company/metrics`: órdenes sincronizadas, cobros sincronizados, monto offline, fallos. Se oculta si la empresa nunca operó offline. Consume `GET /api/v1/metrics/offline/operation`. |

#### Integración cashier (`pages/caja/index.tsx`)

- En `submit()`, si `apiFetch('/api/v1/orders')` falla por red y `navigator.onLine === false`, encola en IndexedDB con `client_uuid` v4 y muestra "Orden encolada offline...".
- El cupón NO se aplica offline (requiere validación server-side).
- `lib/offline/sync-engine` se monta automáticamente desde `app-sidebar-layout`; arranca al iniciar la app (`startSyncEngine` + `requestPersistentStorage`) y se reconfigura al cambiar la empresa activa (`setActiveCompanyForSync`).

#### Cierre de caja con pendientes

- `useCashRegister.closeSession` chequea `countPendingOrders()` antes de pegarle al backend. Si > 0, lanza Error con el mensaje de bloqueo (defensa en profundidad — el backend también valida).

#### Dependencias agregadas

- `idb ^8.0.3` (devDep) — wrapper minimal de IndexedDB.

---

## Enrolamiento — Promo codes desde URL (#246)

### `pages/enrollment/company.tsx`

- `usePromoCodeFromUrl()` lee `?promo=SLUG` y consulta `GET /api/v1/promo-codes/{code}/preview` (público, throttle:30,1).
- `useDefaultPlan()` consume `GET /api/v1/billing/plans/default` (público).
- Render condicional del aside derecho:
  - **Sin `?promo=` o promo inválido**: `<HeroPanel>` (marketing) + `<PlanInfoBlock>` debajo con plan + IVA breakdown.
  - **Con `?promo=` válido**: `<PromoLandingPanel>` reemplaza el aside completo con código, % off, meses, precio tachado y ahorro mensual.
- Promo inválido (NOT_FOUND, EXPIRED, MAX_REACHED): se muestra `<Alert>` warning arriba del wizard pero NO bloquea el enrollment (continúa con plan default).
- En submit el form envía `promo_code` opcional al backend si hay promo válido.
- Backend en `CompanyEnrollmentController` crea la `Subscription` al plan default con snapshot inmutable y aplica el promo si llegó (`applied_via=enrollment`). Si el promo falla al aplicar, log + continúa.
- Componentes nuevos: `components/enrollment/plan-info-block.tsx`, `components/enrollment/promo-landing-panel.tsx`.

## Enrolamiento — Evidencia de propiedad (#154)

### `pages/enrollment/company.tsx`

- Paso 2 incorpora un bloque obligatorio **"Documento de propiedad"** con drag/drop y selector de archivo.
- Formatos aceptados (input HTML): `.pdf,.doc,.docx,.jpg,.jpeg,.png`. Tamaño máximo cliente: 10 MB. El backend revalida con `mimetypes:` (MIME real).
- Botón "Registrar empresa" queda deshabilitado mientras `proofFile` sea `null`.
- Validaciones de cliente antes del POST:
  - Archivo presente.
  - `file.type` en la lista de MIMEs permitidos.
  - `file.size <= 10 * 1024 * 1024`.
- Tras éxito, el callout intermedio dice **"Pendiente de verificación"** y explica que el equipo revisará la evidencia antes de habilitar la empresa.

### `pages/company/under-review.tsx` (nuevo)

- Pantalla a la que aterriza el dueño cuando su empresa está en `pending_activation` o `rejected`.
- Lee la prop `company` (`{ nit, name, status, label }`) que inyecta la ruta web `/company/under-review` (en `routes/web.php`).
- Variante `rejected`: ícono `XCircle`, paleta `rose`, copy que invita a contactar soporte.
- Variante por defecto (`pending_activation`): ícono `Clock`, paleta `amber`, copy "Estamos revisando tu solicitud".
- Acción única: **Cerrar sesión** → `router.post(route('logout'))`.

### `lib/api.ts`

- `apiFetch` ahora detecta `403 + { code: 'company_not_verified' }` y hace `router.visit('/company/under-review')` si no estás ya ahí. Esto cubre el caso de una ruta de negocio invocada con sesión válida pero empresa no verificada (e.g. botón que llama directo a `/api/v1/orders`).

---

## Colaboradores y planificador de turnos (#182)

### Páginas Inertia

- `pages/employees/index.tsx` — listado paginado con filtros (sede,
  cargo, estado, búsqueda) + acción "Nuevo colaborador" + acceso a Informes.
  Badge 🛡 para usuarios con cuenta en el sistema.
- `pages/employees/create.tsx` — usa `<EmployeeForm />` para POST a
  `/api/v1/employees`. Redirige al listado al guardar.
- `pages/employees/show.tsx` — detalle/edición + cambio de estado de
  vinculación (modal) + revelar salario (audita `employee.salary_viewed`)
  + botón archivar.
- `pages/employees/reports.tsx` — tabla con filtros de fecha y exports
  CSV/PDF (links directos a la API).
- `pages/planner/week.tsx` — vista semanal por colaborador. Click en
  turno scheduled abre modal de cancelación (motivo + nota). Botón
  "Asignar turno" abre modal con select de empleado + fecha + horas.
  Si fin ≤ inicio se asume cruce de medianoche y se mueve `ends_at`
  un día adelante.
- `pages/planner/month.tsx` — calendario mensual con horas planificadas
  vs canceladas por día. Click en celda → drill-down a planificador
  semanal con la semana correspondiente.
- `pages/me/agenda.tsx` — agenda del colaborador. Lee
  `/api/v1/me/shifts`. Read-only.
- `pages/me/perfil.tsx` — perfil con salario enmascarado. Botón 👁
  destapa salario llamando `/api/v1/me/salary` (audita).

### Componentes

- `components/employee-form.tsx` — formulario HHRR compartido entre
  create y edit. 5 fieldsets: Identidad, Contacto, Cargo y sede,
  Seguridad social, Pago, Jornada. Carga sedes y cargos al montar.

### Rutas web

- `/configuracion/colaboradores` (index), `/nuevo`, `/{id}` (show),
  `/informes` — gate RBAC con `employees.read` / `employees.create` /
  `workforce.reports`.
- `/planner`, `/planner/calendar` — gate `shifts.read`.
- `/me/agenda`, `/me/perfil` — sin gate específico; el endpoint backend
  responde 404 si el user no tiene perfil `employees`.

### Sidebar

Grupo "Colaboradores" agregado bajo "Configuración" con 4 ítems
(Colaboradores, Planificador, Calendario mensual, Informes) gobernados
por sus respectivos permisos `employees.read` / `shifts.read` /
`workforce.reports`. Iconos: UserCog, CalendarRange, BarChart2.

### Salario enmascarado

El backend devuelve `pay_rate: null, pay_rate_masked: true` cuando el
actor no tiene `employees.view_salary`. El frontend muestra `••••••`.
El botón 👁 hace una llamada separada al endpoint dedicado (que audita
la consulta) y el cliente guarda el valor en estado local sin volver a
recargar la vista completa.

### Texto contable visible

La página de informes incluye una nota explícita: "el costo estimado no
incluye prestaciones, parafiscales ni retención en la fuente". Esto
previene que el operador confunda el reporte con una nómina formal.

---

## HU #200 — Sanitización frontend

> Política completa en `docs/wiki/SECURITY_INPUT_HANDLING.md`. El frontend no garantiza nada — el backend revalida.

### Helper de sanitización

`resources/js/lib/input-sanitize.ts`:

- `sanitizePlainText(value, maxLength, allowWhitespace?)` — strip HTML +
  NFC + bloqueo de control chars y bidi overrides + límite por unit
  count.
- `assertNoControlChars(value, allowWhitespace?)` — devuelve `true` si
  el value NO contiene caracteres invisibles bloqueados. Útil para
  feedback de UI al pegar.
- `stripDangerousHtml(value)` — strip de tags como defensa en
  profundidad.
- `assertIdentifier(value, kind)` — valida `nit`, `email`, `phone`,
  `slug`, `coupon` con regex y casefold.

### Schemas zod

`resources/js/lib/schemas/`:

- `common.ts` — factories `plainTextShort(max)` y `plainTextLong(max)`
  con `.transform()` que aplica `sanitizePlainText` automáticamente.
- `auth.ts`, `menu.ts`, `company.ts`, `chat.ts`, `delivery.ts` —
  schemas por feature listos para componer con `useForm` de Inertia.

### Primitive `<SanitizedInput>`

`resources/js/components/ui/sanitized-input.tsx` — `<Input>` controlado
que aplica `sanitizePlainText` en cada `onChange`. Props: `value`,
`onChange(value)`, `maxLength`, `allowWhitespace?`.

### Markdown seguro

`resources/js/components/ui/markdown.tsx` — refactor con
`rehype-sanitize` + allowlist explícita (h1-h4, p, listas, código,
tablas, blockquote, links http/https/mailto) y `rehype-external-links`
(`rel="noopener noreferrer nofollow"`, `target="_blank"`). Bloquea
`<script>`, `<iframe>`, `<object>`, `<embed>`, eventos `on*`, `style`
inline, `javascript:`, `data:`, `vbscript:`.

### Hardening aplicado

- `pages/auth/register.tsx` — name (SanitizedInput, 255 short).
- `pages/settings/profile.tsx` — name (SanitizedInput, 255 short).
- `pages/company/settings.tsx` — commercial_name + legal_name (255
  short).
- `pages/menu/index.tsx` — form de nuevo menú: name (128 short) +
  description (512 long).
- `pages/chats.tsx` — contact name (120 short) + contact notes (2000
  long).
- `components/deliveries/reject-reason-sheet.tsx` — detalle (255 long).
- `components/ui/notes-editor.tsx` — `emit()` ahora pasa por
  `sanitizePlainText(value, maxLength, true)`.

### Dependencias nuevas

`package.json`:

- `rehype-sanitize`, `rehype-external-links` — schema-based markdown
  sanitization.
- `zod` — validación cliente schema-based con transform.

## HU #149 — Web Push notifications (PWA)

Guía completa: [`docs/wiki/PWA-Push-Notifications.md`](./PWA-Push-Notifications.md).

### Service Worker

- `resources/js/sw.ts` (nuevo) — SW custom compilado vía `injectManifest`
  de `vite-plugin-pwa`. Replica el runtime caching previo (NetworkFirst
  APIs, CacheFirst imágenes/fuentes) + agrega 3 listeners:
  - `push` — deserializa payload JSON y llama `showNotification`.
  - `notificationclick` — abre/foca la URL del payload.
  - `pushsubscriptionchange` — pide al cliente activo re-suscribir vía
    `postMessage`.
- `vite.config.js` migra `strategies: 'generateSW'` → `'injectManifest'`
  apuntando a `srcDir: 'resources/js'` + `filename: 'sw.ts'`.

### Hook

- `resources/js/hooks/use-push-subscription.ts` (nuevo) — encapsula el
  handshake `PushManager.subscribe()` → `POST /api/v1/push/subscriptions`.
  Expone `{isSupported, isStandalone, permission, isSubscribed, busy,
  error, subscribe(), unsubscribe(), refresh()}`. Detecta automáticamente
  Safari iOS < 16.4 vía feature detection.

### Componentes

- `resources/js/components/notifications/push-prompt-banner.tsx` (nuevo)
  — banner accent del DS que aparece SÓLO si PWA instalada +
  `permission='default'` + no dismissed en localStorage (7 días). Mobile
  first (botones apilados <640px, en línea >=640px).
- `resources/js/components/notifications/push-subscriptions-list.tsx`
  (nuevo) — lista de dispositivos suscritos del user con botón "Quitar".

### Página

- `resources/js/pages/settings/notifications.tsx` (nuevo) — usa
  `AppLayout` + `SettingsLayout`. Muestra estado actual (Activado /
  Bloqueado / No soportado / PWA no instalada) con `<Alert variant=
  {safe|critical|warning|accent}>` del DS y lista de devices.
- `routes/settings.php` agrega `Route::get('settings/notifications')`
  apuntando a `Inertia::render('settings/notifications')`.

### Integraciones layout

- `layouts/settings/layout.tsx`: nuevo item de sidebar
  `{ title: 'Notificaciones', url: '/settings/notifications', icon: Bell }`.
- `layouts/app-layout.tsx`: monta `<PushPromptBanner />` antes de
  `<PendingApprovalsBanner />` en el stack de banners globales.

### Tipos

- `types/index.ts`: `SharedData` agrega `vapidPublicKey?: string | null`.
  La clave pública no es secreta — se expone vía Inertia shared props.

### Browser support

- Chrome / Edge / Firefox / Opera desktop + Android: total.
- Safari macOS 16.4+: total.
- Safari iOS 16.4+: requiere PWA instalada (Add to Home Screen).
- iOS < 16.4: NO soportado; el hook lo detecta y oculta la UI.


---

## Facturación electrónica DIAN — `/company/dian` (HU #235, consulta 2026-07)

### `pages/company/dian.tsx`

Pantalla de configuración/consulta DIAN (permiso `dian.documents.read` / `dian.config.read`). 3 tabs:

| Tab | Default | Contenido |
|-----|---------|-----------|
| **Facturas** | ✅ | `DocumentsExplorer` — consulta de documentos emitidos por resolución. |
| **Resoluciones** | | Cards por resolución (rango, consumo, vigencia, badges Activa/Por vencer/Agotada) + alta (gateada por `DIAN_EDITABLE`). Botón "Consultar facturas" salta al tab Facturas con esa resolución preseleccionada. |
| **Contacto por defecto** | | Solo lectura: adquirente genérico CONSUMIDOR FINAL (NIT 222222222222). |

- El tab **Proveedor se retiró** (2026-07): el proveedor tecnológico es único para toda la plataforma y lo opera flexyflow; la config sigue en backend (`DianProviderConfig`).
- `DIAN_EDITABLE = false`: pantalla informativa mientras el módulo no se libera; el banner comunica que DIAN hace parte del **Plan Plus** ($300.000/mes + $10 por factura generada).
- El catálogo de resoluciones se carga una vez en la página y baja por props a ambos tabs; tabs controlados (`value`/`onValueChange`) para el salto Resoluciones → Facturas.
- Contenedor estándar `mx-auto max-w-7xl p-4 sm:p-6` (igual a `company/settings`).

### `components/dian/documents-explorer.tsx`

Flujo de consulta: **resolución (obligatoria) → alcance (toda la empresa o una sede del bootstrap) → tabla**.

- Tabla paginada **server-side** (25/página, `meta` de paginación con Prev/Next) con búsqueda debounced (400ms; número, CUFE/CUDE, track ID) y **ordenamiento por columna** (Número, Tipo, Estado, Fecha — `sort`/`dir` al backend). Mobile: cards apiladas `sm:hidden`.
- Línea de consumo de la resolución seleccionada: rango y `current_number` de `range_to`.
- Detalle por fila (Dialog): estado + motivo de rechazo, sede, fechas, CUFE/CUDE, ambiente, **resolución ligada** (número, prefijo, rango y el consecutivo que consumió el documento), botones PDF/XML (solo con `has_pdf`/`has_xml`, URL firmada S3) y **"Ver orden"** → `/orders/{order_id}`.
- Sedes desde `useBootstrap().data.branches`; el nombre de sede se resuelve client-side por `branch_id`.

### `lib/dian-api.ts` · `types/dian.ts`

- `listDocuments` acepta `resolution_id`, `q`, `sort`, `dir`, `branch` (`'all'` | uuid | ausente = sede activa).
- `DianElectronicDocument` incluye `dian_resolution_id`.
- `pages/dian/documents.tsx` (vista operativa por sede) sigue existiendo sin cambios.
