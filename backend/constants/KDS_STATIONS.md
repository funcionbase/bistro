# Estaciones KDS (Kitchen Display System) — #115

> **Fuente de verdad ejecutable**: `App\Models\KdsStation::defaultDefinitions()`
> (`application/app/Models/KdsStation.php`). Este `.md` es **espejo de
> referencia humana** — si hay drift entre el modelo y este archivo, el código
> gana y este archivo se corrige en el mismo PR.

---

## Concepto

Una **estación de cocina** representa un puesto físico dentro de una sede
(caliente, fría, barra, fritos, panadería, etc.). Cada `MenuCategory` puede
mapearse a una estación mediante el campo `kds_station_id` en
`restaurant_menus.structure.categories[]`. Los tickets del KDS se filtran
server-side por estación; cuando todos los items de una orden en una
estación quedan `ready`, se emite `markStationReady` y la lógica existente
de `KdsTicketService::maybePromoteOrderStatus` decide si la orden global
pasa a `ready`.

---

## Reglas estructurales

- `branch_id` **NOT NULL**: una estación pertenece siempre a una sede. Una
  empresa con 3 sedes tiene 3×N estaciones (no se comparten cocinas entre
  sedes físicas).
- `slug` único por `(company_nit, branch_id)`.
- `is_default=true`: la estación fallback para categorías sin
  `kds_station_id` mapeado. Por convención hay exactamente **una** estación
  default por sede (la primera del seed canónico — `caliente`).
- `archived_at` soft-archive. Antes de archivar una estación con tokens
  activos, el UI obliga a revocar los tokens.
- `color` formato `#RRGGBB` mayúsculas — usado en el indicador visual del
  KDS (NO como semáforo SLA, que se calcula server-side).
- `sla_warn_minutes` y `sla_alert_minutes`: enteros, con
  `sla_warn < sla_alert`. Modelo + FormRequest validan.

---

## Estaciones canónicas (seed default)

Cada sede recién creada (y cada sede del dataset demo
`RestauranteFlexySeeder`) recibe estas 4 estaciones automáticamente vía
`KdsStation::seedDefaultsForBranch($companyNit, $branchId)`:

| Slug | Nombre | Color | SLA warn | SLA alert | Default | Rationale |
|---|---|---|---|---|---|---|
| `caliente` | Caliente | `#EF4444` | 8 min | 15 min | ✓ | Platos calientes (parrilla, plancha, sartén). Default fallback. |
| `fria` | Fría | `#22C55E` | 5 min | 10 min |  | Ensaladas, ceviches, postres fríos, frutas. |
| `barra` | Barra | `#3B82F6` | 4 min | 8 min |  | Bebidas, jugos, smoothies, cocteles. |
| `fritos` | Fritos | `#F59E0B` | 6 min | 12 min |  | Frituras de freidora (papas, empanadas, aros). |

---

## Hooks de creación

El seed se dispara en estos 3 puntos del flujo:

1. **`CompanyEnrollmentController` (`app/Http/Controllers/Enrollment/`)** — al
   completar el enrollment de la empresa, la sede default recibe las 4
   estaciones.
2. **`BranchController::store` (`app/Http/Controllers/Company/`)** — cada
   sede adicional creada por el owner recibe las 4 estaciones.
3. **`RestauranteFlexySeeder::ensureBranches`** — el dataset demo de QA
   recibe estaciones en sus 3 sedes (Pereira/Cartago/Armenia).
4. **`KdsStationSeeder` (backfill)** — invocado desde `ProductionSeeder`
   para empresas que existían antes de #115.

Idempotente: `seedDefaultsForBranch()` usa `firstOrCreate` por
`(company_nit, branch_id, slug)`. Re-ejecutar es seguro.

---

## Drift y cambios al catálogo

Si se agrega, renombra o elimina una estación canónica:

1. Editar `KdsStation::defaultDefinitions()` (código gana).
2. Actualizar la tabla "Estaciones canónicas" de este `.md`.
3. Considerar un seeder de backfill explícito si afecta empresas en PDN
   (las estaciones existentes NO se renombran automáticamente — un
   `firstOrCreate` con slug nuevo siembra paralelo, no reemplaza).

Aplica regla de drift CLAUDE.md §7: si el `.md` no coincide con el código,
el código gana y este archivo se corrige en el mismo PR.

---

## Cross-references

- Modelo: `application/app/Models/KdsStation.php`
- Migración: `application/database/migrations/2026_05_19_*_create_kds_stations_table.php`
- Seeder: `application/database/seeders/KdsStationSeeder.php`
- Permisos: ver `PERMISSIONS_CATALOG.md` (sección "Cocina (KDS)").
- Eventos de auditoría: ver `AUDIT_EVENTS.md` (slugs `kds.*`).
- Wiki: `docs/wiki/Cocina.md` (flujo cocinero + screenshots).
