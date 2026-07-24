# CRM Clientes

> Estado: Estable
> Versión API: v1
> Owner: equipo de plataforma

CRM básico de clientes recurrentes (issue #123 + refactor #235). Página de
listado `/clients` y perfil consolidado `/clients/{contact}`. Identidad
canónica del cliente: `(company_nit, contacts.id)`, complementada por
`doc_number` (clave de negocio) y `client_phone` normalizado.

A diferencia del panel de chats (transaccional, mensajes), el CRM es
analítico: KPIs por cliente, segmentación automática, notas privadas y
etiquetas para acciones manuales del staff.

---

## Resumen

| Capacidad | Detalle |
|---|---|
| Cross-sede | Sí. No requiere `branch.access`; un cliente es único por empresa. |
| Cache | `crm:list:base:{nit}` con `Cache::flexible([300, 1800])` (5 min fresh, 30 min stale). |
| Identidad | `contacts.id` (route key), `doc_number` (canónica), `phone` (informal). |
| Segmentos | `vip`, `at_risk`, `inactive`, `new`, `recurrent`, `regular` (heurística determinística). |
| TZ | `America/Bogota` (consistente con `config('orders.timezone')`). |

---

## Modelo de datos

### `contacts`

Tabla canónica del cliente. Fillable real definido en `App\Models\Contact`:

| Campo | Notas |
|---|---|
| `id` (uuid) | Route key. |
| `company_nit` | FK a `companies.nit`. |
| `kind` | `natural` \| `company`. |
| `doc_type` | CC/CE/TI/PA/RC para naturales; NIT/NIT_EXT para empresas. |
| `doc_number` | UNIQUE PARCIAL `(company_nit, doc_number)` cuando no es null. |
| `dv` | Dígito de verificación (solo NIT). |
| `phone` | Móvil normalizado `57XXXXXXXXXX`. Nullable (empresas pueden no tener móvil). |
| `name` | Nombre comercial / persona. |
| `legal_name` | Razón social (obligatorio en `kind=company`). |
| `email`, `address`, `municipality_dane_code` | Datos fiscales para DIAN. |
| `notes` | Texto libre saneado por `SafePlainText` (max 1000 bytes). |
| `branch_id` | Sede que registró al contacto. Trait `BelongsToBranch` con `withoutBranchScope()` disponible para queries CRM. |

### `client_notes`

Notas privadas del staff sobre el cliente. Soft-delete (`deleted_at`) para
auditoría legal. Sin `branch_id` por diseño: el CRM es cross-sede.

| Campo | Notas |
|---|---|
| `id` | uuid |
| `company_nit`, `contact_id`, `client_phone` | Triple referencia (legacy + nueva). |
| `note` | Hasta 2000 chars, saneado `SafePlainText`. |
| `created_by` | `users.id` actor que la creó. |
| `created_at`, `updated_at`, `deleted_at` | Soft-delete. |

### `client_tags`

Etiquetas slug-style por cliente. Hard-delete; el `audit_log` preserva
trazabilidad. UNIQUE en `(company_nit, contact_id, tag)`.

| Campo | Notas |
|---|---|
| `tag` | Slug `/^[a-z0-9_\-]+$/`, 1–50 chars. Idempotente en POST. |
| `client_phone` | Mirror para queries legacy por phone. |
| `created_by` | Actor. |

---

## Permisos RBAC

| Slug | owner | admin | manager | waiter | cashier | accountant |
|---|---|---|---|---|---|---|
| `clients.read` | RCUD | RCUD | R--- | R--- | R--- | R--- |
| `clients.create` | RCUD | RCUD | -C-- | -C-- | -C-- | ---- |
| `clients.update` | RCUD | RCUD | --U- | --U- | --U- | ---- |
| `clients.delete` | RCUD | RCUD | ---- | ---- | ---- | ---- |

Owner bypass por `role.is_system=true`. Cross-sede deliberado: no se valida
`branch.access` (`Contact::withoutBranchScope()`), pero la query siempre se
filtra por `company_nit` del JWT activo y `loadContactOrFail` aborta 404 si
el contact pertenece a otra empresa. Ver
`bistro/backend/constants/PERMISSIONS_CATALOG.md`.

---

## Endpoints

Prefijo: `/api/v1`. Middleware: `jwt` + `company.access` + permission del slug.

| Método | Ruta | Permiso | Notas |
|---|---|---|---|
| GET | `/clients` | `clients.read,read` | Listado paginado con `search`, `segment`, `tag`. |
| POST | `/clients` | `clients.create,create` | Doc obligatorio, phone opcional. Refactor #235. |
| GET | `/clients/{contact}` | `clients.read,read` | Perfil consolidado por `contacts.id`. |
| POST | `/clients/{contact}/notes` | `clients.update,update` | `note` 1–2000 chars. |
| DELETE | `/clients/{contact}/notes/{id}` | `clients.delete,delete` | Soft-delete con `note_excerpt` en audit. |
| POST | `/clients/{contact}/tags` | `clients.update,update` | Idempotente por UNIQUE. |
| DELETE | `/clients/{contact}/tags/{id}` | `clients.delete,delete` | Hard-delete. |

### Listado

```
GET /api/v1/clients?search=laura&segment=vip&tag=domicilio&page=1&per_page=25
```

Validación: `search` ≤ 100, `per_page` ≤ 100. Filtros se aplican sobre la
lista base cacheada en PHP (sin re-pegar a la BD). La cache se invalida
explícitamente vía `CrmService::forgetCache` al crear/eliminar nota o tag.

Respuesta:

```json
{
  "data": [
    {
      "id": "01HX...",
      "doc_type": "CC",
      "doc_number": "1020304050",
      "phone": "573001000113",
      "name": "Laura Restrepo",
      "kind": "natural",
      "total_orders": 23,
      "completed_orders": 21,
      "cancelled_orders": 2,
      "total_spent": 558900,
      "average_ticket": 26614,
      "last_order_at": "2026-05-08T19:42:00-05:00",
      "first_order_at": "2026-03-10T12:01:00-05:00",
      "orders_last_60d": 18,
      "spent_last_90d": 558900,
      "cancellation_rate": 0.087,
      "tags": ["vip", "domicilio"],
      "segment": "vip"
    }
  ],
  "meta": {
    "current_page": 1, "last_page": 5, "per_page": 25, "total": 125,
    "segments": ["vip","recurrent","new","inactive","at_risk","regular"],
    "available_tags": ["vip","domicilio","preferente"]
  }
}
```

### Creación manual

```
POST /api/v1/clients
{
  "kind": "natural",
  "doc_type": "CC",
  "doc_number": "1020304050",
  "phone": "+57 300 100 0113",
  "name": "Laura Restrepo",
  "email": "laura@example.com",
  "address": "Cra 43A # 5-15",
  "municipality_dane_code": "05001"
}
```

Validación cruzada `kind ↔ doc_type`: empresas solo aceptan `NIT`/`NIT_EXT`,
naturales solo `CC|CE|TI|PA|RC`. `legal_name` obligatorio si `kind=company`.
`phone` se normaliza al canónico `57XXXXXXXXXX` (10 dígitos arrancando por 3).

Resolución de sede para `contacts.branch_id`:
1. `active_branch_id` del JWT.
2. Sede `is_default=true` activa.
3. Si no hay sede activa → 422.

### Perfil consolidado

```
GET /api/v1/clients/{contact}
```

Devuelve KPIs (mismos del listado) + historial de 50 últimas órdenes
(matchea por `contact_id` y por `client_phone` legacy) + 20 últimos chats
(cross-sede, por phone) + todas las notas activas + todas las tags. Si el
contact no existe en la empresa activa, 404 sin leak cross-tenant.

---

## Flujos funcionales

### Segmentación automática

`CrmService::classifySegment()`:

| Segmento | Regla |
|---|---|
| `vip` | Top 10 por gasto en últimos 90 días (gasto > 0). |
| `at_risk` | Total ≥ 4 órdenes y `cancellation_rate > 25%`. |
| `inactive` | Última orden hace > 60 días. |
| `new` | Primera orden < 30 días y ≤ 2 órdenes totales. |
| `recurrent` | ≥ 3 órdenes en últimos 60 días. |
| `regular` | Fallback. |

No es ML: heurística determinística sobre `orders` agregadas. Se recalcula
en cada hit de la cache base.

### Normalización de teléfono

`CrmService::normalizePhone()` es idempotente:

- `573001234567` → `573001234567`
- `3001234567` → `573001234567`
- `+57 300 123 4567` → `573001234567`

Quita todo lo no numérico, antepone `57` cuando ve 10 dígitos arrancando por
`3`. Compartida con `LoyaltyService` y `PublicLoyaltyController`.

### Cross-sede

Las queries usan `Order::withoutBranchScope()` y `Contact::withoutBranchScope()`
para consolidar a nivel empresa. Es deliberado: un teléfono es un cliente
único sin importar dónde haya pedido, y a futuro habilita cupones por cliente
que apliquen en cualquier sede.

---

## Componentes frontend

Página: `bistro/frontend/src/pages/clients/index.tsx` (listado) y
`pages/clients/show.tsx` (perfil). Hooks `useClients` y `useClient`.

Componentes DS reutilizados:
- `PageHeader`, `PageShell`, `FilterBar` para layout.
- `Table`, `DataCardList` con `DataCard` para responsive mobile.
- `SegmentBadge` (`components/clients/segment-badge.tsx`) con tokens semánticos.
- `EmptyState`, `ClientsListSkeleton`, `Alert`.
- `NewClientDialog` para creación y edición.
- `MergeClientsDialog` (`components/clients/merge-clients-dialog.tsx`) para
  unificar duplicados: checkboxes en el listado (visibles con `clients.delete`),
  barra de acción con "Unificar (N)" al seleccionar ≥2, radio para elegir el
  principal (preselecciona quien tiene doc y más pedidos).
- `LoyaltyPanel` se renderiza en el perfil si `loyalty.read` (ver
  `Fidelizacion-Puntos.md`).

UX clave:
- Búsqueda con debounce 300 ms (matchea nombre / documento / teléfono).
- `formatPhone()` muestra `+57 3XX XXX XXXX`; en BD siempre `57XXXXXXXXXX`.
- Acción "Ver chat" abre `/chats?chat={id}` si el cliente tiene conversación.
- Tabs en perfil: historial órdenes / chats / notas / loyalty.

---

## Eventos de auditoría

Emitidos por `ClientController` vía `AuditService::log`:

| Acción | Data mínimo |
|---|---|
| `client.created` | `contact_id`, `kind`, `doc_type`, `doc_number`, `client_phone`, `client_name`, `branch_id`. |
| `client.updated` | `contact_id`, `kind`, `doc_type`, `doc_number`, `client_phone`, `client_name`. |
| `client.merged` | `contact_id` (principal), `merged_contacts[]` (snapshot id/name/doc/phone/email de los absorbidos), `moved` (conteos por tabla). |
| `client.note_created` | `contact_id`, `client_phone`, `note_id`. |
| `client.note_deleted` | `contact_id`, `client_phone`, `note_id`, `note_excerpt` (≤200 chars). |
| `client.tag_added` | `contact_id`, `client_phone`, `tag`. |
| `client.tag_removed` | `contact_id`, `client_phone`, `tag`. |

`AuditService::log` agrega automáticamente `branch_id` y
`actor_active_branch_id` del JWT. Ver
`bistro/backend/constants/AUDIT_EVENTS.md`.

---

## Edge cases y empty states

- **Empresa sin sedes activas**: `POST /clients` aborta 422 con mensaje
  "Crea una sede antes de registrar clientes". `contacts.branch_id` es NOT NULL.
- **Doc duplicado en la misma empresa**: 422 "Ya existe un cliente con ese
  número de documento en esta empresa". El UNIQUE parcial actúa como segunda
  defensa.
- **Phone inválido (no es móvil CO)**: 422 "Ingresa un móvil colombiano de
  10 dígitos que empiece por 3".
- **Empresa con `kind=company` sin razón social**: 422 obligatoria para
  factura electrónica.
- **Tag duplicado**: el endpoint POST es idempotente — devuelve la fila
  existente con HTTP 200 en lugar de 201.
- **Cross-tenant contact**: `loadContactOrFail` aborta 404 si el contact no
  pertenece a la empresa activa del JWT.
- **Merge de duplicados** (`POST /clients/{contact}/merge`, permiso
  `clients.delete`): `{contact}` sobrevive; `merge_ids` (máx 10) se absorben.
  Reasigna orders/chats/client_notes/table_session_guests por `contact_id`,
  tags con dedup (UNIQUE parcial + legacy por phone intactos), rellena solo
  campos vacíos del principal (doc viaja como grupo type+number+dv), concatena
  notas de perfil y elimina los duplicados ANTES de copiar el doc (evita chocar
  el UNIQUE parcial de doc_number). Loyalty/coupons van por `client_phone` y no
  requieren reasignación. Todo en transacción con `lockForUpdate`.
- **Listado vacío**: `EmptyState` con CTA "Registrar primer cliente" si
  `clients.create`; sin CTA en otro caso.
- **Cache stale**: `Cache::flexible([300, 1800])` sirve respuesta vieja hasta
  30 min mientras se refresca. La invalidación explícita garantiza ver
  cambios inmediatamente tras mutaciones del propio actor.

---

## Pendientes fuera de alcance v1

- Loyalty avanzado (ver `Fidelizacion-Puntos.md` para v1 ya entregado).
- Cupones específicos por cliente (asociados a un phone o a una tag).
- Exportación CSV del CRM.
- Métricas avanzadas: cohort retention, LTV, churn por mes.
- Acción "enviar mensaje ad-hoc desde CRM" (delega al panel `/chats`).

---

## Cross-references

- Constants: `bistro/backend/constants/PERMISSIONS_CATALOG.md`,
  `BRANCH_RBAC.md`, `AUDIT_EVENTS.md`, `FEATURES_INDEX.md`.
- Backend: `app/Http/Controllers/Api/ClientController.php`,
  `app/Services/CrmService.php`,
  `app/Http/Requests/Clients/StoreNoteRequest.php`,
  `StoreTagRequest.php`,
  `app/Models/Contact.php`, `ClientNote.php`, `ClientTag.php`.
- Frontend: `src/pages/clients/index.tsx`, `pages/clients/show.tsx`,
  `hooks/use-clients.ts`, `components/clients/`.
- Relacionados: `Chats-Clientes.md`, `Fidelizacion-Puntos.md`.
