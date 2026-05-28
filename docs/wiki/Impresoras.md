# Impresoras térmicas y comandas

> Estado: Estable (CRUD + test de impresora + ruteo por categoría — HU #116)
> Disparador automático en transición de estado, botón "Re-imprimir" y cliente WebUSB/WebBluetooth: **planificados** (no entregados).
> Versión API: v1
> Owner: equipo de plataforma

---

## Resumen

flexyflow soporta impresión térmica para dos canales:

1. **Comandas de cocina/barra** — tickets ESC/POS que listan los ítems de una orden agrupados por categoría, despachados a las impresoras configuradas (`type ∈ {kitchen, bar}`).
2. **Recibos / comprobantes de caja** — receipt al cliente con totales, impuestos (cuando aplica) y referencia de pago.

El CRUD de impresoras vive en `/company/printers`, con permiso `company.update`. Las comandas se generan vía `App\Services\Printing\CommandTicketService::printForOrder($order)`: particiona los ítems por `category`, mapea cada partición a las impresoras cuyo `categories[]` contenga esa categoría y despacha un `PrintCommandTicketJob` por (printer, items_subset). El job genera el buffer ESC/POS con `EscposTicketBuilder` y lo envía vía `HttpAgentDriver` al agente local (estilo PrintNode).

La sanitización ESC/POS — strip de bytes `\x1B` (ESC) y `\x1D` (GS) del texto del usuario — vive en `EscposTicketBuilder` y se documenta en [`docs/wiki/SECURITY_INPUT_HANDLING.md`](./SECURITY_INPUT_HANDLING.md).

---

## Modelo de datos

### `printers`

```text
id              uuid (PK)
company_nit     string (FK → companies.nit)
branch_id       uuid (FK → branches.id, nullable según uso)
name            string
type            string  — kitchen | bar | cashier | customer_receipt
connection      string  — usb | bluetooth | lan
address         string  — URL del agente local (LAN) o identificador USB/BT
paper_width     int     — 58 | 80 (mm)
categories      jsonb (nullable) — lista cerrada de nombres de categoría del menú
is_active       boolean
last_test_at    timestamp (nullable)
created_at/updated_at
```

Eloquent: `App\Models\Printer` con `HasUuids` + `BelongsToBranch`. Scopes `forCompany($nit)` y `active()`. Helper `matchesCategory($category)` para el ruteo.

### Config canónica (`config/printing.php`)

Fuente única de verdad — backend y frontend (vía Inertia shared props) la consumen:

| Sección | Valores |
|---|---|
| `types` | `kitchen` → "Cocina", `bar` → "Barra", `cashier` → "Caja", `customer_receipt` → "Recibo cliente" |
| `connections` | `usb` → "USB", `bluetooth` → "Bluetooth", `lan` → "LAN (agente HTTP)" |
| `paper_widths` | `[58, 80]` |
| `command_types` | `['kitchen', 'bar']` — tipos cuyo destino son comandas de preparación |
| `trigger_status` | `'in_kitchen'` — estado de orden que dispararía la impresión automática (pendiente de integrar) |
| `audit` | `printed`, `reprinted`, `failed`, `tested` — strings cerrados |
| `job` | `tries=3`, `backoff=[10,30,90]`, `timeout=30` |
| `http_agent` | `timeout=5` segundos |

---

## Permisos RBAC

| Slug usado | Default | Notas |
|---|---|---|
| `company.update,read` | owner + admin | Listar/ver impresoras. |
| `company.update,update` | owner + admin | Crear, editar, eliminar y disparar test. |

> El módulo reutiliza el permiso genérico `company.update` (configuración de empresa). No introduce slugs propios. Roles custom (e.g. Cocina) no reciben permisos de configuración de impresoras por default — el owner los puede asignar manualmente.

Sin impacto en `branches.*` ni `EnsureBranchAccess` para el CRUD (vive a nivel de empresa). El ruteo de comandas en runtime sí filtra por `company_nit` + `branch_id` activo de la orden.

---

## Endpoints

Todas bajo middleware `jwt` + `company.access`. CRUD a nivel de empresa.

| Método | Ruta | Permiso |
|---|---|---|
| GET | `/api/v1/company/printers` | `company.update,read` |
| POST | `/api/v1/company/printers` | `company.update,update` |
| PUT | `/api/v1/company/printers/{id}` | `company.update,update` |
| DELETE | `/api/v1/company/printers/{id}` | `company.update,update` |
| POST | `/api/v1/company/printers/{id}/test` | `company.update,update` |

### Respuesta del `index`

```json
{
  "printers": [
    {
      "id": "<uuid>",
      "name": "Cocina principal",
      "type": "kitchen",
      "type_label": "Cocina",
      "connection": "lan",
      "connection_label": "LAN (agente HTTP)",
      "address": "http://192.168.1.50:9100/print",
      "paper_width": 80,
      "categories": ["Carnes", "Pastas"],
      "is_active": true,
      "last_test_at": "2026-05-20T14:32:00+00:00"
    }
  ],
  "config": {
    "types": { "kitchen": "Cocina", "bar": "Barra", "cashier": "Caja", "customer_receipt": "Recibo cliente" },
    "connections": { "usb": "USB", "bluetooth": "Bluetooth", "lan": "LAN (agente HTTP)" },
    "paper_widths": [58, 80]
  }
}
```

### `POST /test` (202 Accepted)

```json
{ "queued": true }
```

El test despacha un `PrintCommandTicketJob` en modo `isTest=true` con un ítem ficticio "PRUEBA DE IMPRESION". El resultado real se observa en `audit_logs` (`printer.tested` al éxito; falla del job se registra como `order.command_print_failed` con `is_test=true` en metadata).

---

## Flujos funcionales

### Registro de impresora

1. Owner/admin abre `/company/printers`, click **Nueva impresora**.
2. Completa formulario:
   - **Nombre** descriptivo (e.g. "Cocina principal").
   - **Tipo**: `kitchen` / `bar` / `cashier` / `customer_receipt`.
   - **Conexión**: `lan` (URL del agente HTTP local), `usb` o `bluetooth` (en estos casos el agente local resuelve el dispositivo por identificador).
   - **Dirección**: URL `http://192.168.x.y:puerto/print` para LAN; identificador USB/BT para los otros casos.
   - **Ancho de papel**: 58 o 80 mm.
   - **Categorías que atiende**: lista cerrada de nombres de categoría del menú (e.g. "Carnes", "Pastas", "Bebidas"). Solo aplica a `type ∈ {kitchen, bar}`.
   - **Activa**: `is_active` (true por default).
3. `POST /api/v1/company/printers` crea la fila + audita `printer.created`.

### Detección de impresoras

**Estado actual**: el registro es manual. No hay auto-detección de impresoras vía WebUSB / WebBluetooth ni descubrimiento mDNS de impresoras LAN. El usuario obtiene la URL del agente local (típicamente `http://<IP-LAN>:9100/print`) y la pega en el formulario.

El agente local es un binario ligero estilo PrintNode (no provisto por flexyflow; el cliente lo instala en una mini-PC / Raspberry de cocina). Habla HTTP y reenvía el buffer ESC/POS al puerto físico USB / BT / paralelo.

### Mapeo impresora ↔ categoría del menú

El campo `categories[]` de cada impresora es una lista de **nombres exactos** de categorías del menú. El ruteo es **many-to-many** implícito:

- Una impresora puede atender N categorías (carnes, pastas, ensaladas).
- Una categoría puede llegar a N impresoras (e.g. "Postres" se imprime en cocina **y** en barra).

`Printer::matchesCategory($category)` valida la pertenencia con `in_array(... , true)` (strict, sensible a mayúsculas y tildes — debe coincidir exacto con `RestaurantMenu.structure.categories[].name`).

**Relación con KDS stations**: independiente. Una `KdsStation` (caliente/fría/barra/fritos) mapea categorías → estación de pantalla. Una `Printer` mapea categorías → impresora física. Ambos coexisten: el ticket llega a la KDS para que la cocina lo vea en pantalla, y simultáneamente se imprime la comanda física como respaldo. No hay vínculo directo entre `kds_stations` y `printers` en el modelo.

### Flujo de impresión de comanda (`CommandTicketService::printForOrder`)

1. Carga impresoras `active` de la empresa con `type ∈ config('printing.command_types')` (`kitchen` y `bar`).
2. Si no hay impresoras activas → retorna `['queued' => 0, 'orphan_items' => count($items)]` (no-op silencioso).
3. Por cada ítem de la orden:
   - Lee `item.category`.
   - Itera impresoras: si `matchesCategory($category)` → agrega ítem al bucket de esa impresora.
   - Si ningún `printer` matchea → cuenta como **orphan**.
4. Si `orphans > 0` → `Log::warning('CommandTicketService: items without printer', [...])`. **No bloquea** la orden (la comanda no es comprobante fiscal).
5. Despacha un `PrintCommandTicketJob::dispatch($orderId, $printerId, $items_subset, $isReprint)` por bucket.
6. Retorna `['queued' => N, 'orphan_items' => K]`.

**Estado actual del disparador**: la transición `orders.status → 'in_kitchen'` está definida como `trigger_status` en `config/printing.php` pero **NO está cableada al pipeline de cambio de estado** (sub-issue pendiente). Hoy la impresión se invoca solo desde el botón de test y desde código que llame a `CommandTicketService::printForOrder` manualmente. El botón "Re-imprimir" en detalle de orden también está pendiente.

### Job de impresión (`PrintCommandTicketJob`)

- `tries=3`, backoff `[10, 30, 90]` segundos, timeout `30s` (todo desde `config/printing.php`).
- Genera el buffer ESC/POS con `EscposTicketBuilder` (header, ítems, footer, corte de papel).
- Envía vía `HttpAgentDriver` (POST al `printer.address` con el buffer en el body, timeout 5s).
- Éxito → audita `order.command_printed` (o `order.command_reprinted` si `isReprint=true`).
- Test → audita `printer.tested` + update `printers.last_test_at`.
- Falla definitiva (post-retries) → audita `order.command_print_failed` con detalle del error HTTP.

### Sanitización ESC/POS

Bytes de control ESC (`\x1B`) y GS (`\x1D`) en texto del usuario podrían reprogramar el firmware de la impresora o inyectar comandos arbitrarios. `EscposTicketBuilder` **filtra estos bytes** antes de componer el buffer.

Política completa en [`docs/wiki/SECURITY_INPUT_HANDLING.md`](./SECURITY_INPUT_HANDLING.md):

- Frontend / Backend: `SafePlainText` + `SanitizesInput` (NFC + control-chars strip) bloquean la entrada de control-chars antes de llegar a BD.
- Salida térmica: `EscposTicketBuilder` aplica un strip explícito de `\x1B` / `\x1D` como defensa en profundidad — defensa por capas.

### Test print

1. Owner/admin abre `/company/printers`, click ⚡ en la impresora.
2. `POST /api/v1/company/printers/{id}/test` responde 202 inmediato.
3. El job genera un ticket de prueba con un ítem ficticio "PRUEBA DE IMPRESION" y lo despacha por `HttpAgentDriver`.
4. Frontend muestra toast "Test enviado a la impresora".
5. Resultado real:
   - Éxito → audita `printer.tested` + `last_test_at` actualizado (visible en próxima recarga).
   - Falla → audita `order.command_print_failed` con `is_test=true`. Frontend no recibe notificación push (consulta a `audit_logs` o reintenta).

### Recibos / comprobantes de caja

`App\Services\Printing\ReceiptPrintingService` + `App\Services\Printing\DianReceiptBuilder` componen recibos (no comandas) para impresoras `type ∈ {cashier, customer_receipt}`. Cubre receipt del POS y, con `DianReceiptBuilder`, recibos compatibles con la facturación electrónica DIAN (HU #235, ver wiki **Facturación-DIAN**).

---

## Componentes frontend

| Componente | Ruta / Archivo | Propósito |
|---|---|---|
| `pages/company/printers.tsx` | `/company/printers` | Listado + CRUD vía `Dialog`. Botón ⚡ por fila ejecuta test. |
| `components/ui/empty-state.tsx` | — | Empty state cuando no hay impresoras configuradas. CTA "Agregar impresora". |
| `components/ui/list-card-skeleton.tsx` | — | Skeleton mientras carga `/api/v1/company/printers`. |
| `components/ui/confirm-dialog.tsx` | — | Confirmación al eliminar impresora. |
| `components/ui/select.tsx` | — | Selector de `type`, `connection`, `paper_width`. |

Shared props Inertia: `printingConfig` = `config('printing.{types,connections,paper_widths}')` para que el frontend no hardcoded los labels.

---

## Eventos de auditoría

Vía `AuditService::log`. Strings cerrados en `config('printing.audit')`:

| Action | Disparador | Metadata |
|---|---|---|
| `printer.created` | `PrinterController::store` | `type`, `connection` |
| `printer.updated` | `PrinterController::update` | `before`, `after` (name/type/connection/address/paper_width/categories/is_active) |
| `printer.deleted` | `PrinterController::destroy` | `id`, `name`, `type`, `connection` (snapshot pre-delete) |
| `printer.tested` | `PrintCommandTicketJob` (modo test, éxito) | `printer_id`, `last_test_at` |
| `order.command_printed` | `PrintCommandTicketJob` (éxito) | `order_id`, `printer_id`, `items_count` |
| `order.command_reprinted` | `PrintCommandTicketJob` (éxito, `isReprint=true`) | `order_id`, `printer_id`, `items_count` |
| `order.command_print_failed` | `PrintCommandTicketJob` (falla definitiva post-retries) | `order_id`, `printer_id`, `error`, `is_test` |

---

## Edge cases y empty states

- **Sin impresoras configuradas**: `CommandTicketService::printForOrder` retorna `queued=0`, `orphan_items=count(items)`. Loguea warning y sigue — la orden no se bloquea. Empty state en `/company/printers` con CTA "Agregar impresora".
- **Ítem sin categoría**: cae como orphan. Loguea warning con `order_id` y `orphan_count`. El staff físico decide qué hacer (típicamente lo voceará).
- **Categoría que no matchea ninguna impresora**: orphan también. Recomendación operativa: agregar la categoría al campo `categories[]` de alguna impresora.
- **Impresora desconectada / agente caído**: el job reintenta 3 veces con backoff `[10, 30, 90]`. Tras la 3ª falla audita `order.command_print_failed`. La cocina debe monitorear `audit_logs` o KDS para no perder comandas.
- **Cambio de IP del agente local**: edición manual del `address` desde `/company/printers`. Recomendación: usar hostnames mDNS estables o IP fija reservada en el router.
- **Bytes ESC/POS en texto del usuario**: filtrados por la cadena `SafePlainText` (backend) + `sanitizePlainText` (frontend) + `EscposTicketBuilder` (salida). Defensa por capas.
- **Categorías con tildes / mayúsculas diferentes** entre `printers.categories` y `RestaurantMenu.structure.categories[].name`: NO matchea (comparación strict). Riesgo operativo — recomendar copy/paste exacto al configurar.
- **`paper_width` distinto del soporte físico**: el ESC/POS asume el ancho declarado. Si la impresora física es 58mm y se declaró 80mm, el ticket sale cortado. No hay validación cross — confianza en el operador.
- **Eliminar impresora con jobs en cola**: el job ya despachado intenta enviarse a una impresora que ya no existe — `Printer::find($printerId)` retorna null y el job falla. Audita `order.command_print_failed`.
- **Test print sin agente local corriendo**: el HTTP client de `HttpAgentDriver` falla con timeout `5s` → reintento → eventual `order.command_print_failed` con `is_test=true`. Frontend mostró toast optimista al click — el usuario revisa en `audit_logs` si quiere confirmar.
- **Empty state listado vacío**: copy "No tenés impresoras configuradas" + CTA prominente.

---

## Pendientes (no entregados en HU #116)

Documentado en `FUNCIONALIDADES_APP.md §25.b`:

- **Disparador automático** en transición de orden a `in_kitchen` (config existe en `trigger_status` pero no está cableada).
- **Botón "Re-imprimir"** en detalle de orden (`isReprint=true` ya está soportado por el job).
- **Cliente WebUSB / WebBluetooth** para evitar el agente local en sedes pequeñas con la impresora conectada a la propia caja.
- **Notificación push al frontend** del resultado del test (hoy hay que consultar `audit_logs` o recargar para ver `last_test_at`).
- **Monitor de salud de impresoras** (heartbeat, estado de papel, atasco) — depende del firmware de cada modelo y del agente local.
