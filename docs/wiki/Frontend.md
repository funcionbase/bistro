# Frontend

> Estado: Estable
> Versión React: 19 / React Router: v7 / TanStack Query: v5 / Tailwind: v4
> Owner: equipo de plataforma

> **Arquitectura (post #220):** el frontend es un **SPA standalone con React Router v7 + TanStack React Query**, desacoplado de la API Laravel (`bistro/frontend/`, build y deploy propios en Cloudflare). **NO usa Inertia en el lado React** — `@inertiajs/react` ni siquiera está instalado. El backend solo sirve `/api/v1/*`. Quedan 4 controllers web que usan `Inertia::render` (dashboard legacy, kds-standalone, company-preferences, public-menu-page), pero el SPA no los consume y su `Inertia::defer()` está inerte. Cualquier referencia a "deferred props", `useForm`, `usePage` o `<Deferred>` de Inertia en este documento es **legacy** y no aplica al SPA actual.

---

## Stack

| Capa | Tecnología | Notas |
|------|-----------|-------|
| UI | React 19 (hooks, sin clases) | Strict mode |
| Routing/SPA | SPA puro contra API Laravel | Proyecto independiente (`bistro/frontend/`), build separado, deploy en Cloudflare Worker. Inertia v2 sigue presente para algunas pantallas server-driven, pero el grueso navega client-side |
| Tipado | TypeScript 5.x | Strict |
| Estilos | Tailwind CSS v4 | Variables CSS y DS v3.1 en [`FRONTEND_UI_GUIDELINES`](../../bistro/frontend/FRONTEND_UI_GUIDELINES.md) |
| Bundler | Vite | Entry: `bistro/frontend/src/spa/main.tsx`; SW: `src/sw.ts` (workbox injectManifest) |
| PWA | vite-plugin-pwa + Workbox | Service Worker custom con Web Push (#149) |
| Tests | Vitest | `vitest.config.ts` + `vitest.setup.ts` |
| Drag & Drop | @dnd-kit | Menú |
| Iconos | lucide-react | Tamaño `size-4` (16px) o `size-5` |
| Primitivas | Radix UI | Bajo `components/ui/` |
| Markdown | react-markdown + rehype-sanitize | Documentos legales (`components/ui/markdown.tsx`) |

---

## Arquitectura y estructura de carpetas

```
bistro/frontend/src/
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

> El paths antiguos `resources/js/...` corresponden al monolito Inertia previo. El frontend actual vive en `bistro/frontend/src/` como proyecto separado y se referencia con el alias `@/` que apunta a `src/`.

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
- Tokens semánticos del DS v3.1 (`bistro/frontend/FRONTEND_UI_GUIDELINES.md` §6.2 y §11): `bg-card`, `text-muted-foreground`, `border-border`, `var(--color-status-*)`. Cero hex hardcoded en paletas semánticas, cero `bg-red-50`/`text-blue-500`.
- Modo oscuro: clase `dark` en `<html>`, controlada por `useAppearance()`.
- Patrones comunes:
  - Card: `rounded-xl border border-border bg-card shadow-sm p-6`.
  - Botón primario: `bg-primary text-primary-foreground rounded-md px-4 py-2 hover:bg-primary/90 transition`.
  - Input: `border-border rounded-md px-3 py-2 focus:ring-2 focus:ring-ring outline-none`.

---

## Patrones de carga (React Router + React Query)

> Esta sección reemplaza a la antigua "Patrones Inertia v2". El SPA **no usa Inertia**: la navegación es client-side con React Router y los datos se cargan con TanStack React Query (`src/lib/query-client.ts`, defaults: `staleTime 30s`, sin refetch on focus).

### Routing + skeleton al navegar

Las páginas se importan `lazy()` (code-split por ruta) en `src/spa/router.tsx`. El `<Suspense>` vive en `src/layouts/spa-app-layout.tsx` y muestra el fallback de carga mientras llega el chunk de la ruta destino. El sidebar/header permanecen montados; solo el área de contenido cambia.

```tsx
// src/layouts/spa-app-layout.tsx
<AppSidebarLayout>
  <Suspense fallback={<RouteSkeleton />}>
    <Outlet />
  </Suspense>
</AppSidebarLayout>
```

> **Regla (#269):** el fallback de navegación debe calcar la silueta de la pantalla destino (skeleton del shell de página), no un spinner genérico. Cada ruta pesada expone su `*-skeleton.tsx` en `components/ui/`.

### Carga progresiva por sección (equivalente SPA a "deferred")

En vez de bloquear toda la página hasta que el último endpoint responda, se descompone en **una query crítica** (contenido principal) + **N queries secundarias** (KPIs, charts, agregados), cada una con su propio skeleton. Patrón de referencia: `src/pages/dashboard.tsx`.

```tsx
import { useQuery } from '@tanstack/react-query';

const summary = useQuery({ queryKey: ['dashboard','summary', period], queryFn: ... });
// ... render: {summary.isPending ? <MetricCardSkeleton /> : <KpiCard data={summary.data} />}
```

### Refetch / cambio de filtro

```tsx
// Re-ejecuta solo las queries cuyo queryKey incluye el filtro cambiado.
const q = useQuery({ queryKey: ['inventory', branchFilter], queryFn: ... });
// o invalidación explícita tras una mutación:
queryClient.invalidateQueries({ queryKey: ['inventory'] });
```

### Polling

```tsx
// Por query (preferido para freshness real: kanban, KDS, comanda):
useQuery({ queryKey: ['orders'], queryFn: ..., refetchInterval: 30_000 });
// Para widgets sueltos: useWidgetFetch (polling configurable, auto-pausa sin foco).
```

### Prefetch al hover

`AppLink` (`src/components/app-link.tsx`) mapea a `prefetch='intent'` de React Router. Para precalentar **datos** además del chunk, usar `queryClient.prefetchQuery` en el handler de hover (ver #269 Fase 4).

```tsx
<AppLink href={`/menu/${id}`} prefetch>Editar</AppLink>
```

### Toasts / mensajes

No hay `flash` de Inertia. Los mensajes de éxito/error se disparan tras la respuesta de `apiFetch`/mutación con el toaster del DS.

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
import { usePermissions } from '@/hooks/use-permissions';
import { AppLink } from '@/components/app-link';

const { permissions } = usePermissions();
{permissions.includes('menu') && (
  <SidebarMenuButton asChild>
    <AppLink href="/menu"><Utensils /> Menú</AppLink>
  </SidebarMenuButton>
)}
```

---

## Patrón estándar de formulario

El SPA usa un único patrón: **`apiFetch`/`useQuery` + `useState`** contra la API JSON. (El antiguo patrón Inertia `useForm` ya no aplica — `@inertiajs/react` no está instalado.)

### `apiFetch` + `useState` (el endpoint devuelve JSON)

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
| `permissions` array (vía `GET /api/v1/bootstrap` → `SpaSharedDataBridge`) | `usePermissions()` / `useSharedData().permissions` (`src/lib/shared-data.tsx`) | Mostrar/ocultar items del sidebar |
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
