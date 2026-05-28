# Frontend

> Estado: Estable
> Versión React: 19 / Inertia: v2 / Tailwind: v4
> Owner: equipo de plataforma

---

## Stack

| Capa | Tecnología | Notas |
|------|-----------|-------|
| UI | React 19 (hooks, sin clases) | Strict mode |
| Routing/SPA | SPA puro contra API Laravel | Proyecto independiente (`application/frontend/`), build separado, deploy en Cloudflare Worker. Inertia v2 sigue presente para algunas pantallas server-driven, pero el grueso navega client-side |
| Tipado | TypeScript 5.x | Strict |
| Estilos | Tailwind CSS v4 | Variables CSS y DS v3.1 en [`FRONTEND_UI_GUIDELINES`](../../application/frontend/FRONTEND_UI_GUIDELINES.md) |
| Bundler | Vite | Entry: `application/frontend/src/spa/main.tsx`; SW: `src/sw.ts` (workbox injectManifest) |
| PWA | vite-plugin-pwa + Workbox | Service Worker custom con Web Push (#149) |
| Tests | Vitest | `vitest.config.ts` + `vitest.setup.ts` |
| Drag & Drop | @dnd-kit | Menú |
| Iconos | lucide-react | Tamaño `size-4` (16px) o `size-5` |
| Primitivas | Radix UI | Bajo `components/ui/` |
| Markdown | react-markdown + rehype-sanitize | Documentos legales (`components/ui/markdown.tsx`) |

---

## Arquitectura y estructura de carpetas

```
application/frontend/src/
├── spa/                    # Bootstrap del SPA (entry main.tsx, router)
├── sw.ts                   # Service Worker custom (Workbox injectManifest + Web Push)
├── pages/                  # Páginas — un archivo por ruta
│   ├── auth/               # login, register, forgot/reset/verify, company-selector, branch-selector, confirm-password
│   ├── enrollment/         # user, company
│   ├── company/            # settings, preferences, kds, branches/, tables/, warehouses/
│   ├── menu/               # index, show, public
│   ├── orders/             # tables/, table-sessions/, kanban
│   ├── caja/               # cajero, cierres, recibos
│   ├── kds/                # index (consolidado), station (standalone)
│   ├── chats.tsx           # buzón de chats por sede
│   ├── clients/            # CRM
│   ├── deliveries/         # index, metrics, mis entregas
│   ├── employees/          # nómina + estados
│   ├── inventory/          # stock, transfers, recipes, food-cost
│   ├── suppliers/          # proveedores
│   ├── purchases/          # compras, adjuntos
│   ├── planner/            # planeación operativa
│   ├── dian/               # DIAN electronic documents
│   ├── loyalty/            # programa de fidelización
│   ├── billing/            # facturación de la plataforma
│   ├── coupons/            # index, show
│   ├── metrics/            # index
│   ├── reports/            # index
│   ├── roles/              # roles.tsx, role-editor.tsx
│   ├── users/              # users.tsx
│   ├── settings/           # profile, password, appearance, notifications
│   ├── me/                 # index
│   ├── table/              # vista pública QR (comensal)
│   ├── error-boundary.tsx, not-found.tsx, welcome.tsx, hours.tsx, dashboard.tsx
├── components/             # Componentes reutilizables (kebab-case)
│   ├── ui/                 # Primitivas (Button, Card, Dialog, ...) sobre Radix + DS v3.1
│   ├── alerts/, billing/, branches/, cash-register/, chats/, clients/,
│   ├── company/, company-settings/, coupons/, dashboard/, deliveries/,
│   ├── dian/, employees/, enrollment/, hours/, kds/, menu/, metrics/,
│   ├── notifications/, offline/, orders/, order-tables/, planner/,
│   ├── printing/, pwa/, reports/, whatsapp/
│   └── shared widgets sueltos (RoleBadge, EmptyState, BusinessGate, etc.)
├── hooks/                  # Hooks personalizados (use-*)
├── layouts/                # app/, auth/, settings/, kds-standalone-layout.tsx, spa-app-layout.tsx
├── lib/                    # api, api-client, api-routes, token, shared-data, route-map, schemas/, offline/, printing/, formatters, etc.
├── css/                    # Theme tokens + globals
└── types/                  # billing, business-hours, coupon, dian, inventory, purchases, recipes, suppliers, index
```

> El paths antiguos `resources/js/...` corresponden al monolito Inertia previo. El frontend actual vive en `application/frontend/src/` como proyecto separado y se referencia con el alias `@/` que apunta a `src/`.

**Convenciones de nombrado:**

- Páginas y componentes: `kebab-case.tsx` (`menu/dish-card.tsx`).
- Hooks: prefijo `use-` (`use-token.ts`).
- Tipos: PascalCase para nombres, agrupados por dominio.
- Imports relativos al alias `@/` (configurado en `tsconfig.json` y `vite.config.ts`).

---

## Convenciones TypeScript

- `strict: true`. Sin `any` salvo justificación documentada.
- Cada respuesta de API tiene tipo TS en `types/`.
- Props de componentes: `interface` o `type` con sufijo del dominio (`MenuCardProps`, `InvoiceListProps`).
- Discriminated unions para estados (p. ej. `'loading' | 'success' | 'error'`).

---

## Convenciones Tailwind v4

- Utility-first; cero CSS escrito a mano salvo `@theme` en `app.css`.
- Tokens semánticos del DS v3.1 (`application/frontend/FRONTEND_UI_GUIDELINES.md` §6.2 y §11): `bg-card`, `text-muted-foreground`, `border-border`, `var(--color-status-*)`. Cero hex hardcoded en paletas semánticas, cero `bg-red-50`/`text-blue-500`.
- Modo oscuro: clase `dark` en `<html>`, controlada por `useAppearance()`.
- Patrones comunes:
  - Card: `rounded-xl border border-border bg-card shadow-sm p-6`.
  - Botón primario: `bg-primary text-primary-foreground rounded-md px-4 py-2 hover:bg-primary/90 transition`.
  - Input: `border-border rounded-md px-3 py-2 focus:ring-2 focus:ring-ring outline-none`.

---

## Patrones Inertia v2

### Deferred props con skeleton

```tsx
import { Deferred } from '@inertiajs/react';

<Deferred data="summary" fallback={<Skeleton className="h-32 w-full" />}>
  <SummaryPanel />
</Deferred>
```

### Recarga parcial

```tsx
router.reload({ only: ['summary', 'heatmap', 'abandonment'] });
```

Usado por `usePeriodFilter` para refrescar solo los props del dashboard al cambiar el período.

### Polling

```tsx
router.reload({ only: ['active_orders'], preserveScroll: true });
// invocado cada 30s vía useEffect + setInterval
```

Para datos del cliente (no de Inertia), usar `useWidgetFetch` con polling configurable.

### Prefetch al hover

```tsx
<Link href={`/menu/${id}`} prefetch>
  Editar
</Link>
```

### Flash data

```tsx
const { flash } = usePage().props;
useEffect(() => {
  if (flash.success) toast.success(flash.success);
}, [flash]);
```

---

## Patrón de token (clave del SPA multi-empresa)

`src/lib/token.ts`:

```ts
import { setToken, getToken, subscribeToken } from '@/lib/token';

setToken(jwt);                    // persiste en localStorage + URL ?token=
const token = getToken();         // lee
const unsub = subscribeToken(t => console.log('nuevo token', t));
```

`src/lib/api.ts`:

```ts
import { apiFetch } from '@/lib/api';

const res = await apiFetch('/api/v1/menus', { method: 'GET' });
const data = await res.json();
```

`apiFetch`:
- Inyecta `Authorization: Bearer ${token}` y `?token=` en query.
- Captura `X-Refresh-Token` y llama a `setToken()` automáticamente.
- En `401` con mensaje "revoc": limpia token y redirige a `/`.
- `credentials: 'include'` para cookies Laravel en paralelo.

`src/hooks/use-token.ts`:

```tsx
const token = useToken(); // string | null, reactivo a cambios
```

---

## Catálogo de componentes reutilizables

### Botones

```tsx
import { Button } from '@/components/ui/button';

<Button>Guardar</Button>
<Button variant="outline">Cancelar</Button>
<Button variant="destructive">Eliminar</Button>
<Button disabled>{processing && <LoaderCircle className="size-4 animate-spin" />}Procesando</Button>
```

### Card

```tsx
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';

<Card>
  <CardHeader>
    <CardTitle>Mi título</CardTitle>
  </CardHeader>
  <CardContent>Contenido</CardContent>
</Card>
```

### Input

```tsx
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/input-error';

<div className="grid gap-2">
  <Label htmlFor="email">Email</Label>
  <Input id="email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
  <InputError message={errors.email} />
</div>
```

### Modal

```tsx
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';

<Dialog>
  <DialogTrigger asChild><Button>Abrir</Button></DialogTrigger>
  <DialogContent>
    <DialogHeader><DialogTitle>Título</DialogTitle></DialogHeader>
    {/* contenido */}
  </DialogContent>
</Dialog>
```

### Badge de rol

```tsx
import RoleBadge from '@/components/role-badge';

<RoleBadge name="Owner" color="#0052FF" isSystem />
```

### Sidebar item con permiso

```tsx
const { permissions } = usePage<SharedData>().props;
{permissions.includes('menu') && (
  <SidebarMenuButton asChild>
    <Link href="/menu"><Utensils /> Menú</Link>
  </SidebarMenuButton>
)}
```

---

## Patrón estándar de formulario

Dos variantes usadas en el repo:

### A) Inertia `useForm` (cuando el endpoint redirige)

```tsx
import { useForm } from '@inertiajs/react';

const { data, setData, post, processing, errors } = useForm({
  email: '',
  password: '',
});

function handleSubmit(e: FormEvent) {
  e.preventDefault();
  post(route('login'), {
    onError: () => {/* errors[] poblado automáticamente */},
  });
}
```

### B) `apiFetch` + `useState` (cuando el endpoint devuelve JSON)

```tsx
const [errors, setErrors] = useState<FieldErrors>({});
const [processing, setProcessing] = useState(false);

async function handleSubmit(e: FormEvent) {
  e.preventDefault();
  setErrors({});
  setProcessing(true);
  try {
    const res = await apiFetch('/api/v1/coupons', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });
    const json = await res.json();
    if (!res.ok) {
      if (res.status === 422) {
        const fieldErrors: FieldErrors = {};
        for (const [k, v] of Object.entries(json.errors as Record<string, string[]>)) {
          fieldErrors[k as keyof FieldErrors] = v[0];
        }
        setErrors(fieldErrors);
      } else {
        setErrors({ general: json.message ?? 'Error inesperado.' });
      }
      return;
    }
    // éxito
  } finally {
    setProcessing(false);
  }
}
```

---

## Patrones de hooks personalizados

### Polling con retry

```tsx
const { data, loading, error, retry } = useWidgetFetch<Metric>('/api/v1/metrics/orders/active', {
  interval: 30_000,
  enabled: hasPermission,
});
```

### CRUD completo

`useCoupons`, `useDeliveryList`, `useBusinessHours` exponen el patrón:

```tsx
const { coupons, loading, error, fetchCoupons, createCoupon, updateCoupon, deleteCoupon } = useCoupons();
```

Carga inicial automática. Cada acción dispara refetch al estado fresco.

### Permisos calculados

```tsx
const { data, role, canCreate, canUpdate, canDelete } = useMenuDetail(menuId);
{canCreate && <Button onClick={openNewItem}>Añadir</Button>}
```

---

## Permisos en el frontend

| Mecanismo | Dónde | Para qué |
|-----------|-------|----------|
| `permissions` array (JWT → Inertia shared props) | `usePage<SharedData>().props.permissions` | Mostrar/ocultar items del sidebar |
| `NavItem.permission` en `app-sidebar.tsx` | Sidebar | Filtra rutas por feature |
| Props `canCreate/canUpdate/canDelete` | Pasadas página → componente | Mostrar/ocultar botones |
| `actorPermissions` + `disabledCheck` en `UserPermissionsEditor` | Edición de overrides | Limita al actor |
| `is_system` en `CompanyRole` | `Roles.tsx` | Bloquea Edit/Delete de roles base |

> El backend siempre re-verifica. El frontend solo es UX defensivo.

---

## Layouts

| Layout | Archivo | Cuándo usar |
|--------|---------|-------------|
| `AppSidebarLayout` | `layouts/app/app-sidebar-layout.tsx` | Páginas autenticadas con sidebar (default) |
| `AppHeaderLayout` | `layouts/app/app-header-layout.tsx` | Páginas autenticadas que prefieren topbar a sidebar |
| `AuthSimpleLayout` | `layouts/auth/auth-simple-layout.tsx` | Auth básico (login, forgot, reset) |
| `AuthHeroLayout` | `layouts/auth/auth-hero-layout.tsx` | Auth con hero/split (welcome, register) |
| `SettingsLayout` | `layouts/settings/layout.tsx` | Sub-navegación de `/settings/*` (profile, password, appearance, notifications) |
| `SpaAppLayout` | `layouts/spa-app-layout.tsx` | Wrapper de páginas SPA puras |
| `KdsStandaloneLayout` | `layouts/kds-standalone-layout.tsx` | KDS por estación en kiosk-mode (`min-h-dvh w-screen`, sin sidebar) |

```tsx
<AppLayout breadcrumbs={[
  { title: 'Dashboard', href: '/dashboard' },
  { title: 'Mi Empresa', href: '/company/settings' },
]}>
  <Head title="Mi Empresa" />
  {/* contenido */}
</AppLayout>
```

---

## Estados de carga

- **Skeleton:** preferido para placeholders de listas/cards (`<Skeleton className="h-32 w-full" />`).
- **Spinner:** `<LoaderCircle className="size-4 animate-spin" />` dentro de botones cuando `processing=true`.
- **Empty state:** ilustración o texto centrado con CTA.
- **Error state:** mensaje en `text-destructive` con botón de retry.

---

## PWA y Web Push

- Service Worker custom: `src/sw.ts` (vite-plugin-pwa con `strategies: 'injectManifest'`). Combina precaching de Workbox, runtime caching de APIs, y listeners de Web Push (#149).
- Hooks: `use-push-subscription.ts` gestiona suscripción/permission; `components/notifications/push-prompt-banner.tsx` invita a activar push; `components/notifications/push-subscriptions-list.tsx` lista/revoca devices.
- Página de gestión: `/settings/notifications` (`pages/settings/notifications.tsx`).
- Install prompt: `components/pwa/install-pwa-prompt.tsx`, `ios-install-hint.tsx`, `update-available-toast.tsx`.

---

## Notas

- Todo cambio que toque navegación, permisos o nuevas pantallas debe actualizar `FRONTEND_FILES.md` y, si afecta funcionalidades visibles, `FUNCIONALIDADES_APP.md`.
- Los hooks de polling deben limpiar su `interval` en `useEffect` cleanup. Para KDS se usan `use-live-polling` (con toggle) y `use-auto-polling` (auto-pause cuando la tab pierde foco).
- Evitar fetch en componentes hoja; centralizar en hooks o en la página.
- Sanitización de inputs (CLAUDE.md §5): usar `sanitizePlainText` de `src/lib/input-sanitize.ts` o el primitive `components/ui/sanitized-input.tsx`. `maxLength` del input = `maxBytes` del backend.
