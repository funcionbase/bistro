# BUSINESS_TYPES — Verticales por sede (#237)

> **Antes de modificar el catálogo o las capabilities, lee este archivo.**
> **Después de modificar, sincroniza este `.md` + las constantes del frontend
> (`hooks/use-business-types.ts`, `lib/business-context.tsx`,
> `components/business-type-selector.tsx`) + el seed en la migración
> `2026_05_24_120000_create_business_types_block.php` en el mismo PR.**

## Concepto

Cada **sede** (`branches.business_type_id`) pertenece a un vertical del catálogo
`business_types`. El vertical define:

- Las **capabilities** habilitadas por defecto (mesas, KDS, domicilios,
  recetas, inventario, etc.).
- Las **áreas de preparación** (`prep_areas`) que se siembran al crear la sede.
- Los **labels visibles** (roles, estados de orden, módulos) que el frontend
  resuelve por sede activa.

Una empresa puede tener N sedes **con diferentes verticales** (ej. un grupo que
opera un café en el centro y un food truck los fines de semana). Cada sede
mantiene su configuración independiente.

## Catálogo

| Slug | Label ES | Áreas preparación default | Capabilities habilitadas |
|---|---|---|---|
| `restaurant` | Restaurante | Cocina · Barra | mesas, KDS, prep_areas, domicilios, recetas, reservas |
| `bakery` | Panadería | Horno · Repostería | KDS, prep_areas, recetas, domicilios |
| `cafe` | Café | Barra · Cocina | mesas, KDS, prep_areas, recetas |
| `fast_food` | Comidas rápidas | Plancha · Freidora | KDS, prep_areas, domicilios, recetas |
| `food_truck` | Food truck | Cocina | KDS, prep_areas, recetas |
| `ghost_kitchen` | Dark kitchen | Cocina | KDS, prep_areas, domicilios, recetas, multi-menú |
| `bar` | Bar | Barra · Cocina | mesas, KDS, prep_areas, recetas, reservas |
| `catering` | Catering | Cocina | prep_areas, domicilios, recetas, programación eventos |
| `dark_store` | Tienda dark | _(sin áreas)_ | domicilios |

Capabilities comunes a todos: `pos_orders`, `inventory` (excepto cuando el
owner desactive por override). `counter_orders` se hereda salvo
`ghost_kitchen` (sólo delivery).

## Capabilities canónicas

| Flag | Significado |
|---|---|
| `pos_orders` | Toma de pedidos desde POS (siempre true). |
| `counter_orders` | Tomar orden directa en mostrador. |
| `tables` | Mesas físicas, QR de mesa, sesiones de mesa. |
| `kds` | Pantalla de cocina (KDS). |
| `prep_areas` | Áreas de preparación dinámicas. |
| `delivery` | Módulo de domicilios. |
| `recipes` | Recetas (BOM) → consumo de ingredientes. |
| `inventory` | Módulo de inventario completo. |
| `reservations` | Reservas de mesa (futuro). |
| `catering_scheduling` | Eventos programados con anticipación. |
| `multi_menu` | Múltiples menús simultáneos (ghost kitchen). |

## Mecanismos

- **Catálogo**: `business_types` (PK `slug`), `default_capabilities` JSON,
  `prep_area_defaults` JSON.
- **Pertenencia por sede**: `branches.business_type_id` (FK).
- **Override por sede**: `branches.capabilities_override` JSON.
- **Resolución backend**: `App\Services\BusinessCapabilityService` (merge default + override).
- **Labels dinámicos**: `App\Services\BusinessLabelService` (roles, statuses, módulos por vertical).
- **Gate middleware**: alias `business.capability:<flag>` registrado en `bootstrap/app.php`.
- **Endpoint**: `GET /api/v1/me/active-context` devuelve `branch + business_type + capabilities + labels + prep_areas`.
- **Catálogo**: `GET /api/v1/business-types`.
- **Cambio por sede**: `POST /api/v1/company/branches/{branch}/change-business-type`.
- **Frontend**: `<BusinessProvider>` (lib/business-context.tsx), hooks
  `useBusinessContext`, `useBusinessCapability(flag)`, `useBusinessLabel(...)`,
  `usePrepAreas()`, componentes `<BusinessGate>` y `<BusinessTypeSelector>`.

## RBAC

### Endpoints y permisos

| Endpoint | Permiso requerido | Owner bypass | Notas |
|---|---|---|---|
| `GET /api/v1/business-types` | — (sólo JWT) | n/a | Catálogo público autenticado, sin company context. |
| `GET /api/v1/me/active-context` | — (sólo `company.access`+`branch.access`) | sí (vía `branch.access`) | Contexto propio de la sede activa. |
| `POST /api/v1/company/branches/{branch}/change-business-type` | `branches.manage,update` | sí (vía `FeaturePermissionService`) | Sembrado por defecto en owner + admin. |
| `POST /api/v1/company/branches` (crear con vertical) | `branches.manage,create` | sí | El campo `business_type_id` viaja en el body protegido por el mismo gate. |
| Rutas `/api/v1/inventory/*` + `/suppliers/*` + `/purchases/*` | `inventory.*` + capability `inventory` | parcial (ver abajo) | Owner bypassea el permiso RBAC pero NO la capability del vertical. |

### Permiso `branches.manage`

Ya existía (FeatureSeeder.php:318). El seed lo entrega con `can_update=true` a
`owner` y `admin`. La ruta `change-business-type` lo reusa intencionalmente:
cambiar vertical es una operación de gestión de sede, no requiere permiso
nuevo, y mantiene la matriz RBAC simple.

### Capabilities NO bypassean por owner

El middleware `EnsureBusinessCapability` valida `capabilities[$flag]` para la
sede activa sin mirar `role.is_system`. Esto es **intencional**:

- Las capabilities son señales del **modelo de negocio de la sede**, no
  permisos del usuario. Si una sede `dark_store` no tiene `tables`, ni el
  owner debería tomar órdenes de mesa allí porque conceptualmente no aplica.
- El owner que quiera habilitar una capability puntual en su sede tiene la
  herramienta correcta: editar `branches.capabilities_override`
  (ej. `{"tables": true}`) desde la pantalla de "Cambiar tipo de negocio".
  Eso es configuración de la sede, no privilegio del usuario.
- Análogo a otros gates conceptuales: un owner tampoco puede emitir DIAN en
  una empresa que no tiene `dian_resolution` activa — el bypass RBAC del
  owner no convierte una configuración faltante en una autorización.

## Reglas de cambio de vertical

1. Cambiar vertical NO modifica órdenes históricas, menús, recetas, ni receipts.
2. Sólo cambia `branches.business_type_id` + siembra `prep_areas` faltantes
   (preserva las existentes — para no romper KDS o asignaciones de items).
3. Las capabilities resultantes se recalculan en runtime; los gates de UI y
   middleware se aplican de inmediato.
4. Genera audit event `branch.business_type_changed` con before/after.
5. Si el nuevo vertical NO incluye `inventory` y la sede sí tiene ingredientes
   cargados, la BD los preserva — el módulo sólo deja de aparecer en UI.
   Cambiar de vertical no es una operación destructiva.

## Cómo agregar un vertical

1. Editar la migración `2026_05_24_120000_create_business_types_block.php`
   (método `businessTypeSeed`) o crear una migración aditiva con
   `DB::table('business_types')->insert(...)`.
2. Decidir `default_capabilities` desde el set canónico (no inventar flags
   nuevos sin actualizar `BusinessCapabilityService::DEFAULT_FLAGS`).
3. Decidir `prep_area_defaults` (slug único por sede, label visible).
4. Si introduce labels específicas, actualizar `BusinessLabelService` (mapas
   `ROLE_LABELS`, `ORDER_STATUS_LABELS`, `MODULE_LABELS`).
5. Actualizar la tabla de catálogo y la lista de capabilities de este `.md`.

## QA manual

Pre-merge para cambios que afecten capabilities, vertical, o el seed:

- [ ] Endpoint `GET /api/v1/business-types` devuelve los 9 verticales con
  `default_capabilities` consistentes con la tabla de este doc.
- [ ] Endpoint `GET /api/v1/me/active-context` devuelve `capabilities` + `labels`
  + `prep_areas` para la sede activa.
- [ ] Crear empresa nueva con vertical `cafe`:
  - El paso "Tipo de negocio" del wizard muestra todos los verticales con
    tooltip de capabilities y áreas.
  - La sede principal queda con `business_type_id='cafe'`.
  - `prep_areas` tiene `barra` + `cocina`.
- [ ] Cambio de vertical de una sede: el badge de la card cambia, el modal
  invoca `change-business-type`, y `prep_areas` se respetan + nuevas se agregan.
- [ ] **MIX de verticales por empresa**: una misma empresa puede operar una
  sede `restaurant` + una sede `food_truck` simultáneamente. Cada sede tiene
  su propio `business_type_id`, `capabilities`, `labels`, `prep_areas`.
- [ ] Gate de inventory: una sede con `{"inventory": false}` en
  `capabilities_override` recibe 403 BUSINESS_CAPABILITY_DENIED al intentar
  acceder a `/api/v1/inventory/*`, `/api/v1/suppliers/*`, `/api/v1/purchases/*`.

## Referencias cruzadas

- `database/migrations/2026_05_24_120000_create_business_types_block.php` —
  schema + seed canónico.
- `app/Models/BusinessType.php`, `app/Models/PrepArea.php`.
- `app/Services/BusinessCapabilityService.php`,
  `app/Services/BusinessLabelService.php`.
- `app/Http/Middleware/EnsureBusinessCapability.php`.
- `app/Http/Controllers/Api/BusinessContextController.php`.
- `bistro/frontend/src/lib/business-context.tsx`.
- `bistro/frontend/src/components/business-type-selector.tsx`.
- `bistro/frontend/src/components/business-gate.tsx`.
- `constants/ORDER_STATUSES.md` — labels dinámicos de estados por vertical.
