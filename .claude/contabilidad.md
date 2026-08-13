# Reglas contables (legislación colombiana) + documentos legales

> REGLA OBLIGATORIA. Aplican SIEMPRE en backend, DB, reportes y APIs financieras. Contabilidad **auditable, trazable e inmutable**.

## Moneda y precisión
- Moneda por defecto: **COP**. No mezclar monedas en una columna; si hay multimoneda en el futuro, agregar `currency_code` (ISO 4217).
- Columnas monetarias: `decimal(12,2)` mínimo. Cast `'campo' => 'decimal:2'` en `casts()`. NUNCA `float`/`double`.
- Aritmética monetaria: castear a `decimal:2` antes de persistir, `round($v, 2)` al componer totales en PHP.
- Facturación CO se muestra sin decimales (truncar a peso) — decisión de **presentación**, no de almacenamiento. BD siempre `decimal:2`.

## Inmutabilidad y trazabilidad
- `payment_receipts` son **inmutables**. Para corregir: crear otro registro (refund con monto negativo, nota de ajuste).
- Toda mutación financiera (`closeWithPayment`, `refund`, `cancel`, `appendItems`, `updateStatus`):
  1. Envolver en `DB::transaction(...)`.
  2. `Order::lockForUpdate()` sobre la entidad principal (prevenir doble-cierre/cobro concurrente).
  3. `AuditService::log(...)` con acción + metadatos (montos, referencias, motivo).
- No introducir endpoints que muten un receipt ya creado. DIAN exige trazabilidad histórica.

## Convención de signos en `payment_receipts`
- `payment_method` ∈ `{cash, card, transfer, refund}` (lista cerrada — no inventar).
- `amount` es **signed**: cobros positivos, refunds negativos.
- `Net = SUM(amount) GROUP BY payment_method`. NUNCA calcular neto en PHP sumando órdenes y restando refunds cuando se puede en SQL.

## Estados de orden
- Fuente única: `config/orders.php` (`revenue`, `operational`, `terminal_*`). NUNCA duplicar listas en controllers/services/blades/frontend; usar `config('orders.revenue')`.
- `revenue_statuses = ['completed']`. NO incluir `in_transit` ni pre-entrega como ingreso.
- Estados terminales son finales. Refund crea nuevo asiento, no muta el original.

## Invariante `orders.total`
- `orders.total = SUM(items.price * items.quantity)`. Helper único: `OrderController::computeItemsTotal`. Llamarlo en cada mutación de `items[]`.
- `orders.total` ya viene **neto del descuento**. `discount_amount` es informativo. NUNCA restarlo en reportes/revenue (doble descuento).
- Precios SIEMPRE se leen del menú activo en BD, NUNCA del payload del cliente.

## Impuestos (IVA / INC)
- Hoy `orders.total` es precio final sin desglose tributario.
- Al implementar IVA/INC, migración con: `tax_amount` `decimal(12,2)`, `tax_rate` `decimal(5,2)`, `tax_regime` `varchar` (`iva_19`, `iva_5`, `iva_exento`, `inc_8`, `simple_no_iva`).
- Restaurantes CO suelen aplicar **INC 8%** en lugar de IVA, salvo franquicias / régimen común.
- **Régimen Simple (RST)**: muchos restaurantes están acogidos y NO facturan IVA. Si `tax_regime='simple'`, reportes sin desglose IVA.
- Propina: **voluntaria 10%** sugerida. Si se cobra, columna separada `tip_amount`, NO suma a `total` ni a base gravable.

## Facturación electrónica DIAN
- Facturas requieren CUFE + numeración consecutiva autorizada. Servicio dedicado (no embebido en `OrderController`), tabla `invoices` separada de `orders`.
- Facturas inmutables. Anulación = nota crédito (otro documento), no `UPDATE`.
- Conservación: **5 años** (personas naturales) / **10 años** (jurídicas). No borrar receipts/facturas antes de plazo. Soft-delete máximo, jamás `truncate` en PDN.

## Devoluciones y notas crédito
- Refund crea `PaymentReceipt` con `amount` negativo. Orden pasa a `refunded`.
- Tarjeta/transferencia: **siempre** exigir `reference` (número de comprobante de la devolución). Único respaldo contable.
- Efectivo: puede omitir reference, pero registrar quién autorizó (`actor_id` del JWT en AuditLog).
- Refunds parciales: `payment_receipts.amount` soporta múltiples filas por orden — sumar todos los refunds.

## Reportes y cierres
- Agregación financiera (gross, refunds, net, ticket promedio) en SQL (`selectRaw('SUM(...) GROUP BY ...')`), NUNCA iterando en PHP.
- Reportes muestran **gross / refunds / net** explícitos. No solo gross — induce a error contable.
- Filtros de período: `ordered_at` para órdenes, `paid_at` para receipts. NO siempre coinciden (cobro al día siguiente). Cuadre de caja: agrupar por `paid_at::date`.
- KPIs históricos estables: una vez cerrado el período, recálculos posteriores dan el mismo número. No permitir mutaciones retroactivas a órdenes en estado terminal.

## Checklist pre-merge para features financieras
1. ¿Mutaciones en `DB::transaction` con `lockForUpdate`?
2. ¿`AuditService::log` con metadata reconstructible?
3. ¿Columnas monetarias `decimal:2`?
4. ¿Reportes consumen `config('orders.*')`, no listas hardcoded?
5. ¿Operación reversible vía nuevo asiento (no `UPDATE`/`DELETE`)?
6. Si introduce `payment_method` nuevo, ¿lista cerrada y reportes actualizados?
7. ¿Política contable documentada en `BACKEND_FILES.md`?

---

## Documentos legales

`config/legal.php` define 3 URLs placeholder (terms/privacy/contract, todas `https://example.com/...` con nota TODO) — reemplazalas por tus propios documentos legales antes de producción. El frontend las lee vía `useBootstrap().data.legalUrls` (las expone `BootstrapService::buildCatalogs()`) y las abre en pestaña nueva (`target="_blank" rel="noopener noreferrer"`) en los checkboxes de aceptación de las pantallas de enrollment.

**Aceptaciones**: la tabla `user_acceptances` se conserva como historial inmutable (Habeas Data CO). Para nuevas aceptaciones se guarda `accepted_at + ip_address + user_agent + document_type` — sin snapshot. Para TOS/privacidad la evidencia del contenido vive fuera del repo (sitio institucional); para el contrato, en el git history de `contrato.md` (versión + `published_at` en el frontmatter, cláusula 3.3 del propio contrato).

**Cambios al contrato**: se editan directamente en `contrato.md` (bump de `version`/`published_at` en el frontmatter). Cambios a TOS/privacidad se hacen en el sitio institucional, fuera de este repo.
