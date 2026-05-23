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
