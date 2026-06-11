# Courier-only mode

> **Fuente de verdad ejecutable**:
> `application/app/Support/PostLoginRedirect.php` (constantes `FULL_NAV_PERMISSIONS`
> y `COURIER_PERMISSION`) +
> `application/resources/js/lib/courier-mode.ts` (constantes idénticas).
> **Las dos listas deben mantenerse alineadas literalmente.**
> Este archivo es **espejo de referencia** — el código gana en caso de drift.

---

## Qué es

Introducido en #119. Si un usuario tiene el permiso `deliveries.self_assign`
y **ningún** permiso que indique navegación completa (admin, owner, cocina,
caja con privilegios extra), la UI cambia:

- **Sidebar**: se oculta Dashboard, Menú, Mesas, Tablero, sección de
  Domicilios admin, Operaciones, Reportes, Admin. Solo queda "Mis entregas".
- **Redirect post-login** (después de elegir empresa + sede activa): se
  redirige a `/my-deliveries` en lugar de `/dashboard`.

La detección es **por permisos**, no por nombre de rol, para sobrevivir a
renombres del rol `Domiciliario` y a futuros roles tipo "Domiciliario
externo", "Mensajero", etc.

## Constantes (valores literales al 2026-05-18)

```php
// application/app/Support/PostLoginRedirect.php
private const FULL_NAV_PERMISSIONS = [
    'reports.read',     // owner / admin / cashier ven reportes
    'company.update',   // admin / owner editan empresa
    'orders.create',    // cashier toma órdenes
    'menu.update',      // admin / owner editan menú
    'inventory.read',   // chef / admin ven inventario
    'shifts.read',      // admin / employee ven planificador
];

private const COURIER_PERMISSION = 'deliveries.self_assign';
```

```ts
// application/resources/js/lib/courier-mode.ts
const COURIER_PERMISSION = 'deliveries.self_assign';

const FULL_NAV_PERMISSIONS = [
    'reports.read',
    'company.update',
    'orders.create',
    'menu.update',
    'inventory.read',
    'shifts.read',
] as const;
```

> Los dos arrays deben ser **idénticos elemento a elemento** (sin importar el
> orden). Si se agrega un nuevo permiso que activa navegación completa,
> editar **ambos** archivos en el mismo PR.

## Lógica de activación

| Condición | Resultado |
|---|---|
| `isSystemRole === true` (owner / admin / employee con `is_system=true`) | Modo NO activo (siempre full nav). |
| `permissions` vacío o `undefined` | Modo NO activo. |
| `permissions` no contiene `deliveries.self_assign` | Modo NO activo. |
| `permissions` contiene `deliveries.self_assign` **y al menos uno** de `FULL_NAV_PERMISSIONS` | Modo NO activo (el rol hace más cosas además de courier). |
| `permissions` contiene `deliveries.self_assign` y **ninguno** de `FULL_NAV_PERMISSIONS` | **Modo activo** → sidebar reducido + redirect a `/my-deliveries`. |

## Cuándo agregar / quitar de `FULL_NAV_PERMISSIONS`

**Agregar**: cuando se introduce un permiso nuevo cuya presencia indica que el
usuario debe ver el dashboard completo (ej. un futuro `kitchen.kds` que abra
la pantalla del KDS).

**Quitar**: solo si el permiso pierde sentido (deprecado) o se renombra. En
ese caso, hacer ambos cambios en el mismo PR.

> Si introducís un permiso que un courier podría tener legítimamente sin
> romper el modo (ej. `deliveries.read` extra), NO lo agregues acá.
> `FULL_NAV_PERMISSIONS` es la lista de "cosas que un courier puro NO tiene".

## Helper backend equivalente

`PostLoginRedirect::routeNameForUser(int $userId, string $companyNit)` —
útil cuando aún no se ha emitido el JWT (caso típico:
`GoogleAuthController` justo después del login). Lee los permisos de la
membresía desde BD y aplica la misma lógica.

## Pares espejo que deben mantenerse sincronizados

- `application/app/Support/PostLoginRedirect.php` (`FULL_NAV_PERMISSIONS`,
  `COURIER_PERMISSION`).
- `application/resources/js/lib/courier-mode.ts` (`FULL_NAV_PERMISSIONS`,
  `COURIER_PERMISSION`).
- El rol demo `Domiciliario` en
  `application/database/seeders/RestauranteFlexySeeder.php` (debe quedar
  con `deliveries.self_assign` + `deliveries.read` + `deliveries.update`
  únicamente — sin tocar `FULL_NAV_PERMISSIONS`). Ver
  [`ROLES_DEMO.md`](./ROLES_DEMO.md).

> Última revisión: 2026-05-18 (#201) — mirrors alineados literalmente.
