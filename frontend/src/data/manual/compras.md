---
title: "Compras y proveedores"
description: "Órdenes de compra de insumos con trazabilidad completa: desde el borrador hasta el pago, con adjuntos y vínculo al inventario. Gestión del catálogo de proveedores."
metaTitle: "Compras y proveedores — Manual bistro.flexyflow.co"
metaDescription: "Cómo gestionar compras de insumos y proveedores en bistro.flexyflow.co: órdenes de compra, estados, adjuntos, registro de pago y vínculo con inventario."
section: "administración"
readingTime: "7 min"
lastUpdated: "8 de julio de 2026"
---

![Compras y proveedores en bistro](/images/manual/compras.svg "Compras: órdenes a proveedores y su recepción.")

## Proveedores

Antes de crear órdenes de compra, registra tus proveedores en **compras → proveedores**. Cada proveedor tiene nombre, NIT/cédula, teléfono y correo. Son la "lista de contactos" de tus vendedores habituales (distribuidoras, fruterías, carnicerías, proveedores de empaque, etc.).

Crear el proveedor es un paso de un minuto y hace que las órdenes de compra queden vinculadas al contacto correcto — lo cual agiliza el historial de compras por proveedor.

## Órdenes de compra

Una **orden de compra** (OC) documenta qué compraste, a quién, en qué cantidad y a qué precio. Vive en **compras → órdenes de compra**.

### Crear una OC

1. Dale a <kbd>Nueva orden</kbd>.
2. Elige el proveedor y la fecha esperada de entrega.
3. Agrega los ítems: cada ítem apunta a un insumo del inventario, con cantidad y precio unitario.
4. Guarda como borrador o envía directo al estado "pendiente".

### Estados de la OC

<table>
<thead>
<tr>
<th>Estado</th>
<th>Qué significa</th>
</tr>
</thead>
<tbody>
<tr>
<td>
<strong>Borrador</strong>
</td>
<td>
Se puede editar. Aún no se ha enviado al proveedor ni afecta el inventario.
</td>
</tr>
<tr>
<td>
<strong>Pendiente</strong>
</td>
<td>Enviada al proveedor. Se espera la entrega.</td>
</tr>
<tr>
<td>
<strong>Recibida</strong>
</td>
<td>
La mercancía llegó. Al marcar como recibida, el sistema ofrece registrar la entrada
de stock en el inventario automáticamente.
</td>
</tr>
<tr>
<td>
<strong>Pagada</strong>
</td>
<td>
Se confirmó el pago al proveedor. Estado final positivo. Lleva monto pagado y
método.
</td>
</tr>
<tr>
<td>
<strong>Cancelada</strong>
</td>
<td>Se anuló antes de recibir la mercancía.</td>
</tr>
<tr>
<td>
<strong>Anulada</strong>
</td>
<td>
Revocada después de recibida o pagada. Requiere justificación. El movimiento de
inventario asociado NO se revierte automáticamente — debes hacer un ajuste manual si
la mercancía fue devuelta.
</td>
</tr>
</tbody>
</table>

<div class="callout callout-info">
<p>
<strong>Entrada de inventario automática:</strong> cuando marcas la OC como "recibida",
bistro flexy te ofrece registrar la entrada de stock en
<a href="/manual/inventario">inventario</a> por cada ítem de la OC. Puedes
aceptarla tal cual o ajustar cantidades si la entrega fue parcial. Si prefieres registrar
la entrada a mano, también puedes hacerlo desde inventario directamente.
</p>
</div>

## Adjuntos

Cada OC tiene un panel de adjuntos donde puedes subir la factura del proveedor, la remisión, el soporte de pago u otros documentos. Admite PDF, JPG y PNG. Los adjuntos quedan vinculados a la OC para auditoría.

<div class="callout callout-warn">
<p>
<strong>DIAN — conservación de facturas de compra:</strong> las facturas de proveedor son
soportes contables. La ley colombiana exige conservarlas <strong>5 años</strong> (personas
naturales) o <strong>10 años</strong> (jurídicas). Subelas al panel de adjuntos de la OC
para no perder el respaldo.
</p>
</div>

## Indicadores del encabezado

Al tope de la página de compras ves tres indicadores en tiempo real:

- **Total de OCs:** cuántas órdenes hay en el período.
- **Borradores pendientes:** OCs sin enviar al proveedor.
- **Valor pendiente de pago:** suma de OCs recibidas pero aún sin pagar.

Puedes filtrar la lista por estado (borrador, pendiente, recibida, pagada, cancelada, anulada) y buscar por proveedor o número de OC.

## Cómo fluye una compra típica

1. **Creas la OC en borrador** (lunes cuando haces el pedido al proveedor por teléfono).
2. **La mandas a "pendiente"** cuando confirmas el pedido al proveedor (ya saben que llega el miércoles).
3. **El miércoles llega la mercancía** — la marcas "recibida" y confirmas la entrada de stock.
4. **Subes la factura** al panel de adjuntos.
5. **Pagas al proveedor** y la marcas "pagada" con el monto y el método (efectivo, transferencia).

## Quién puede hacer qué

<table>
<thead>
<tr>
<th>Acción</th>
<th>Quién la hace por defecto</th>
</tr>
</thead>
<tbody>
<tr>
<td>Ver órdenes de compra</td>
<td>Propietario, Administrador, Bodeguero, Contador.</td>
</tr>
<tr>
<td>Crear y editar borradores</td>
<td>Propietario, Administrador, Bodeguero.</td>
</tr>
<tr>
<td>Marcar como recibida o cancelada</td>
<td>Propietario, Administrador, Bodeguero.</td>
</tr>
<tr>
<td>Marcar como pagada</td>
<td>Propietario, Administrador (Bodeguero no puede pagar por defecto).</td>
</tr>
<tr>
<td>Anular una OC</td>
<td>Solo Propietario y Administrador.</td>
</tr>
<tr>
<td>Gestionar proveedores</td>
<td>Propietario, Administrador, Bodeguero.</td>
</tr>
</tbody>
</table>
