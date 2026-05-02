# Frontend

> Estado: Estable
> Versión React: 19 / Inertia: v2 / Tailwind: v4
> Owner: equipo de plataforma

---

## Stack

| Capa | Tecnología | Notas |
|------|-----------|-------|
| UI | React 19 (hooks, sin clases) | Strict mode |
| SPA bridge | Inertia.js v2 | Routing server-side desde Laravel |
| Tipado | TypeScript 5.x | Strict |
| Estilos | Tailwind CSS v4 | Variables CSS de [`FRONTEND_UI_GUIDELINES`](../../application/FRONTEND_UI_GUIDELINES.md) |
| Bundler | Vite | Entry: `resources/js/app.tsx` |
| Drag & Drop | @dnd-kit | Menú |
| Iconos | lucide-react | Tamaño `size-4` (16px) o `size-5` |
| Primitivas | Radix UI | Bajo `components/ui/` |
| Markdown | react-markdown | Documentos legales |

---

## Arquitectura y estructura de carpetas

```
resources/js/
├── app.tsx                 # Punto de entrada: registra páginas y resuelve token de Inertia
├── ssr.tsx                 # Server-side rendering (opcional)
├── pages/                  # Páginas Inertia — un archivo por ruta del backend
│   ├── auth/               # welcome, login, register, forgot-password, ...
│   ├── enrollment/         # user, company
│   ├── company/            # settings, preferences
│   ├── menu/               # index, show
│   ├── deliveries/         # index, metrics
│   ├── billing/            # index
│   ├── coupons/            # index, show
│   ├── hours/              # index
│   ├── metrics/            # index
│   ├── reports/            # index
│   ├── roles/              # Roles, RoleEditor
│   ├── users/              # Users
│   ├── settings/           # profile, password, appearance
│   ├── me/                 # index
│   └── dashboard.tsx
├── components/             # Componentes reutilizables (kebab-case)
│   ├── ui/                 # Primitivas (Button, Card, Dialog, ...) sobre Radix
│   ├── menu/               # Específicos del dominio menú
│   ├── deliveries/         # Específicos del dominio domicilios
│   ├── coupons/            # Específicos del dominio cupones
│   ├── billing/            # OverdueBanner, InvoiceList, SubscriptionCard
│   ├── dashboard/          # LiveIndicator, HeatmapChart, TopItemsChart
│   └── ...
├── hooks/                  # Hooks personalizados (use-*)
├── layouts/                # Layouts: app, auth, app-header
├── lib/                    # api, token, utils, formatters
└── types/                  # index.ts + dominios (billing, business-hours, coupon)
```

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
- Variables CSS de `FRONTEND_UI_GUIDELINES.md` (paleta, espaciados, bordes).
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

`lib/token.ts`:

```ts
import { setToken, getToken, subscribeToken } from '@/lib/token';

setToken(jwt);                    // persiste en localStorage + URL ?token=
const token = getToken();         // lee
const unsub = subscribeToken(t => console.log('nuevo token', t));
```

`lib/api.ts`:

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

`hooks/use-token.ts`:

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

| Layout | Cuándo usar |
|--------|-------------|
| `AppLayout` | Páginas autenticadas con sidebar |
| `AuthLayout` (variantes simple/card/split) | Páginas de auth (login, register, forgot, reset) |
| `AppHeaderLayout` | Páginas autenticadas que prefieren topbar a sidebar |

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

## Notas

- Todo cambio que toque navegación, permisos o nuevas pantallas debe actualizar `FRONTEND_FILES.md` y, si afecta funcionalidades visibles, `FUNCIONALIDADES_APP.md`.
- Los hooks de polling deben limpiar su `interval` en `useEffect` cleanup.
- Evitar fetch en componentes hoja; centralizar en hooks o en la página.
