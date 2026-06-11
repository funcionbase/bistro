# Roles demo (solo seeders de QA)

> **Fuente de verdad ejecutable**:
> `bistro/backend/database/seeders/RestauranteFlexySeeder.php::seedRoles()`
> (Domiciliario, Cocina) + bloques `syncRolePermissions` que les asignan
> el conjunto exacto de permisos.
> Este archivo es **espejo de referencia** — el código gana en caso de drift.

---

## Qué son

Roles que **solo existen en la empresa demo `SuperPapas`** sembrada por
`RestauranteFlexySeeder` para entornos QA / dev / sandbox. NO se crean en
empresas reales. NO son `system_roles` ni tienen `role_type` propio en
`PermissionTemplateSeeder` — sus permisos se asignan a mano dentro del
seeder.

| Nombre BD | Tipo | Color | Para qué se diseñó |
|---|---|---|---|
| `Domiciliario` | `is_system=false`, sin `role_type` | `#0EA5E9` | Demuestra el flujo *courier-only* (#119). Activa el sidebar reducido y el redirect post-login a `/my-deliveries`. |
| `Cocina` | `is_system=false`, sin `role_type` | `#EF4444` | Demuestra el KDS con un rol pre-armado (alternativo al `cook` de `ROLES_TEMPLATES.md`, usa permisos similares pero set un poco más amplio para el demo). |

## Por qué NO son `role_type`

- `role_type` se reserva para roles que **toda empresa** podría querer
  instanciar (vía `roles:sync-templates`). Estos dos viven solo en el demo.
- Si en el futuro se decide que `Domiciliario` debería estar disponible para
  cualquier empresa (no solo demo), promoverlo a `role_type=courier` requiere:
  1. Agregar al `foreach` de `PermissionTemplateSeeder` con su map.
  2. Agregar al default `--role` de `SyncRoleTemplatesCommand`.
  3. Migrar el bloque de `RestauranteFlexySeeder::seedRoles` para usar
     `seedSystemRoleFromTemplate('courier', ...)` (o `--no-system` equivalente
     porque NO debe llevar `is_system=true`).
  4. Mover a `ROLES_TEMPLATES.md`.

## Permisos exactos (espejo del seeder)

### `Domiciliario` (`#119`)

```php
syncRolePermissions(
    $roles['courier'],
    readableSlugs: ['deliveries.read', 'deliveries.self_assign'],
    updatableSlugs: ['deliveries.update']
);
```

| Slug | C | R | U | D |
|---|---|---|---|---|
| `deliveries.read` | - | ✅ | - | - |
| `deliveries.self_assign` | - | ✅ | - | - |
| `deliveries.update` | - | - | ✅ | - |

**Por qué este set mínimo**: el courier no ve menú, mesas, tablero, ni
chats. Los datos de la orden (cliente, dirección, total) llegan eagerly
desde `/deliveries/mine` sin necesidad de `orders.read`. Cualquier slug
extra activaría `FULL_NAV_PERMISSIONS` (ver `COURIER_MODE.md`) y rompería
el modo *courier-only*.

### `Cocina`

```php
syncRolePermissions(
    $roles['kitchen'],
    readableSlugs: ['orders.read', 'menu.read', 'hours.read'],
    updatableSlugs: ['orders.update']
);
```

| Slug | C | R | U | D |
|---|---|---|---|---|
| `orders.read` | - | ✅ | - | - |
| `orders.update` | - | - | ✅ | - |
| `menu.read` | - | ✅ | - | - |
| `hours.read` | - | ✅ | - | - |

> Set ligeramente más amplio que el `cook` de `ROLES_TEMPLATES.md` (este
> agrega `hours.read` para que el cocinero de demo vea el horario de su
> sede). Si se decide consolidar, mover a `cook` template.

## Pares espejo que deben mantenerse sincronizados

- `RestauranteFlexySeeder::seedRoles` (bloques courier/kitchen) ↔ tablas arriba.
- Lista de permisos del courier ↔ `bistro/frontend/src/lib/courier-mode.ts` (el courier NUNCA debe quedar con un `FULL_NAV_PERMISSIONS` activo).

> Última revisión: 2026-05-18 (#201)
