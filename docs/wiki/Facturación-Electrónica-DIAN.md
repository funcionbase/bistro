# Facturación electrónica DIAN (HU #235)

> Fase 1 entregada: arquitectura completa con `MockDianProvider` activo
> (CUFE/CUDE reales + XML UBL 2.1 + PDF con QR + responses simuladas).
> Provider real (Factura1/Siigo/Carvajal/MyPC/Dispapeles/etc.) queda como
> sub-issue futuro.

---

## ¿Qué emite la app?

| Documento | Cuándo | Código | Caso típico |
|-----------|--------|--------|-------------|
| **DEE POS** | Default al cierre cuando el cliente NO pide factura | **CUDE** | Mesa que paga en caja sin pedir factura |
| **Factura Electrónica de Venta (FEV)** | Cliente solicita factura con sus datos | **CUFE** | Empresa, profesional |
| **Nota Crédito FEV** | Anular/devolver FEV | CUFE | Devolución |
| **Nota Crédito DEE POS** | Anular/devolver DEE POS | CUDE | Devolución en caja |
| **Nota Débito FEV** | Aumentar valor de FEV | CUFE | Cargos adicionales |

Cada empresa registra al menos **2 resoluciones DIAN activas**: una para
DEE POS (`prefix=PO`) y otra para FEV (`prefix=FE`).

---

## Arquitectura

```
┌──────────────────┐                  ┌─────────────────────────┐
│ Order (cerrada)  │   POST /dian/    │ ElectronicDocumentCtrl  │
│ + Idempotency-K  │───documents─────▶│ Cache::lock + dedupe    │
└──────────────────┘                  └──────────┬──────────────┘
                                                 │
                                                 ▼
                          ┌──────────────────────────────────────┐
                          │ DianDispatchService (DB::transaction)│
                          │ ├ ResolutionConsecutiveAllocator     │
                          │ │  (SELECT FOR UPDATE on resolutions)│
                          │ ├ DianDocumentBuilder (DTO neutral)  │
                          │ ├ RecipientResolver (cascada §5.3)   │
                          │ ├ CufeCudeGenerator (SHA-384)        │
                          │ ├ DianXmlBuilder (UBL 2.1)           │
                          │ ├ DianRepresentationPdfBuilder       │
                          │ │  (dompdf + endroid/qr-code)        │
                          │ ├ Storage::disk('s3')->put (XML/PDF) │
                          │ ├ DianProviderFactory::make(slug)    │
                          │ │  └ MockDianProvider (default)      │
                          │ │     ├ 92% accept (síncrono)        │
                          │ │     ├ 5%  reject (catálogo DIAN)   │
                          │ │     ├ 2%  sent → async webhook     │
                          │ │     └ 1%  error                    │
                          │ └ AuditService::log                  │
                          └──────────────────────────────────────┘
                                          │
                                          ▼
                          ┌──────────────────────────────────────┐
                          │ electronic_documents (BD inmutable   │
                          │   post-accepted)                     │
                          │ ├ unique_code (CUFE/CUDE 96 chars)   │
                          │ ├ status: pending→sent→accepted      │
                          │ ├ xml_path/pdf_path → S3             │
                          │ └ provider_track_id                  │
                          └──────────────────────────────────────┘
```

---

## Configuración por empresa

`Configuración → Facturación DIAN` (owner-only, slug `dian.config.read`):

1. **Perfil fiscal** — DV, representante legal, CIIU, responsabilidades
   fiscales DIAN, municipio DANE, contacto fiscal del emisor.
2. **Proveedor** — slug, credenciales encrypted at rest, ambiente
   habilitación/producción, webhook_secret.
3. **Resoluciones** — registra resoluciones autorizadas por DIAN
   (prefijo + rango + clave técnica). UNIQUE parcial: 1 activa por
   (company, document_type, environment).
4. **Cliente por defecto** — override opcional del CONSUMIDOR FINAL DIAN
   estándar (NIT 222222222222).

---

## Settings de empresa (`company_defaults`)

| Setting | Valores | Default | Significado |
|---------|---------|---------|-------------|
| `dian.auto_emit_on_close` | `always` / `only_with_recipient` / `manual` | `manual` | Si emite documento al `closeWithPayment` automáticamente |
| `dian.print_on_close` | `always` / `ask` / `never` | `ask` | Política de impresión al cierre |
| `dian.default_document_type` | `pos_equivalent` / `invoice` | `pos_equivalent` | Tipo a emitir cuando no se especifica |
| `dian.lookup_by_phone_enabled` | `true` / `false` | `true` | Lookup automático en `contacts` por teléfono |

---

## Cascada de resolución del adquirente (`RecipientResolver`)

```
1. orders.billing_* (snapshot capturado por el cajero en modal)
2. Contact lookup por (company_nit, client_phone)
   ├ profile completo (dian_profile_completed_at) → emite FEV
   ├ profile parcial → status `needs_recipient_data`, UI pide datos
   └ no encontrado → siguiente
3. dian_default_recipients[company_nit] (override)
4. config('dian.default_final_consumer') ← NIT 222222222222
```

---

## N-instance safe (add-on operativo)

| Riesgo | Defensa |
|--------|---------|
| Doble consecutivo en 2 EC2 paralelas | `SELECT ... FOR UPDATE` sobre `dian_resolutions` |
| Doble emisión por order_id | `Idempotency-Key` + `Cache::lock(30s)` + UNIQUE en BD |
| Webhook duplicado | `Cache::lock` por (provider, track_id) + transición monotónica |
| Schedule en N instancias | `->onOneServer()` + `->withoutOverlapping()` |
| Worker re-encola mid-job | `ShouldBeUnique` con `uniqueFor=300s` |
| Storage local de XML/PDF | `Storage::disk('s3')` obligatorio — `local` prohibido |
| Rotación de credentials mid-emit | Snapshot del `DianProviderConfig` dentro de `DB::transaction` |

---

## Schedules registrados (`routes/console.php`)

| Comando | Frecuencia | Onclevel |
|---------|------------|----------|
| `dian:dispatch-pending` | cada 5 min | onOneServer + withoutOverlapping(10) |
| `dian:check-pending-acceptance` | cada 15 min | onOneServer + withoutOverlapping(20) |
| `dian:resolution-expiration-alert` | diario 05:15 | onOneServer + withoutOverlapping(60) |

---

## Reemplazo del mock por proveedor real (futuro sub-issue)

1. Crear `App\Services\Dian\Providers\Factura1Provider` (o el que sea) implementando `DianProviderContract`.
2. Registrar binding en `DianProviderFactory::make()` (`'factura1' => app(Factura1Provider::class)`).
3. UI de `Configuración → DIAN → Proveedor`: dropdown ya soporta `mock|factura1|siigo`.
4. Owner cambia `provider_slug` activo de la empresa + setea credenciales.
5. Documentos previos del mock siguen como historial (status, XML, PDF) — no se migran retroactivamente.
6. **Importante**: el guardarrail `MockInProductionException` bloquea
   `provider_slug='mock'` + `environment='produccion'` desde el backend.
   El owner NO puede activar mock en producción.

---

## Seguridad de los endpoints (#235)

**Regla**: ningún endpoint DIAN se expone públicamente. Todos los caminos
de emisión, configuración y consulta son consumo interno autenticado.

### Stack de middleware aplicado al bloque `/api/v1/dian/*`

Hereda del bloque padre `routes/api.php` (línea 258):

```
['jwt', 'throttle:api', 'company.access', 'company.verified', 'company.not_blocked']
```

Sobre eso, cada endpoint suma:
- `permission:dian.<feature>,<action>` — RBAC fino con `FeaturePermissionService`.
- `branch.access` cuando es operativo (`documents/*`, `recipients/*`).
- `branch.consolidate` cuando puede pasar `?branch=all`.

Resultado: para tocar cualquier ruta DIAN se requiere **sesión activa
(JWT cookie HttpOnly) + empresa verificada + no bloqueada por past_due +
permiso fino + acceso a la sede activa**.

### Validaciones adicionales del backend (defensa en profundidad)

1. **`order_id` debe pertenecer a la empresa Y a la sede activa** (no solo
   `exists` global). `Rule::exists(...)->where(...)`.
2. **`references_document_id` también validado por empresa**.
3. **`printer_id` debe pertenecer a la sede activa Y estar `is_active`**.
4. **Estado de la orden**: 422 `dian.order_not_emittable` si la orden NO está
   en `config('orders.revenue')` (default `['completed']`).
5. **`Idempotency-Key` obligatorio** en `POST /dian/documents`.
6. **Cache::lock por (`order_id`, `document_type`)** serializa emisiones paralelas.
7. **Cada controller con route binding** valida `company_nit === active_company_nit`.

### Único endpoint público

`POST /api/v1/webhooks/dian/{provider}`. Defensas:

| Capa | Mecanismo |
|------|-----------|
| Whitelist provider | regex en route + check defensivo en controller (`mock\|factura1\|siigo`). |
| Rate limit | `throttle:60,1` por IP. |
| Autenticación criptográfica | HMAC SHA-256 con `webhook_secret_encrypted`. |
| Idempotencia | `Cache::lock` por (provider, track_id) + transición monotónica. |
| Anti-normalización | Whitelisted en `NormalizeStrings`. |
| Anti-leak | 404 silencioso cuando no encuentra config / track_id. |

### Quién dispara la emisión

El backend es la única autoridad de emisión:
- **Manual**: cajero con permiso `dian.documents.emit` pulsa "Emitir documento" desde UI; frontend `POST /dian/documents` con `Idempotency-Key`.
- **Automático** (cuando se configure): `EmitDianDocumentJob` desde `closeWithPayment` según `company_settings.dian.auto_emit_on_close`.
- **No hay endpoint público** que dispare emisión. El webhook solo recibe respuestas.

### Cómo NO se puede atacar

| Vector | Bloqueo |
|--------|---------|
| Sin sesión | `jwt` middleware → 401. |
| Sesión de otra empresa | `Rule::exists` por `company_nit` → 422. |
| Sin permiso fino | `EnsureFeaturePermission` → 403. |
| Empresa pending/suspended | `company.verified`/`company.not_blocked` → 403. |
| Orden no facturable | `dian.order_not_emittable` → 422. |
| Reenvío con misma `Idempotency-Key` | dedupe + Cache::lock → 200 con doc existente. |
| Doble emisión paralela | UNIQUE compuesta + UNIQUE en `unique_code`. |
| Robo de tokens del provider | Cast `encrypted` en BD; jamás en GET. |
| Webhook falso | HMAC inválido → 401. |
| Webhook con provider no whitelist | Regex de ruta + check controller → 404. |
| Robo de XML/PDF | URLs S3 firmadas TTL 15 min + endpoint con `permission:dian.documents,read`. |

---

## Conservación legal

- **5 años** para personas naturales / **10 años** para jurídicas (legislación DIAN).
- Soft-delete máximo; jamás `truncate` ni `delete` de `electronic_documents`.
- XML/PDF en S3 con prefijo `companies/{nit}/dian/{yyyy}/{mm}/{full_number}.{xml|pdf}`.

---

## RBAC

Ver `application/backend/constants/PERMISSIONS_CATALOG.md` sección
"Facturación electrónica DIAN". 10 slugs nuevos en grupo
'Facturación DIAN'. Owner-only por seeder: `dian.config.write` y
`dian.default_recipient.write`.
