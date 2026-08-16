---
title: "Inventario"
description: "Control de insumos por bodega: entradas, mermas, ajustes, transferencias entre bodegas, historial de movimientos y valorización en tiempo real."
metaTitle: "Inventario — Manual bistro.example.com"
metaDescription: "Cómo gestionar el inventario de insumos en bistro.example.com: bodegas, movimientos (entrada/merma/ajuste/transferencia), historial y valorización."
section: "el día a día"
readingTime: "8 min"
lastUpdated: "8 de julio de 2026"
---

![Inventario con niveles y alertas de stock](/images/manual/inventario.svg "Inventario: insumos, niveles y mínimos por bodega.")

## ¿Para qué sirve el inventario?

El módulo de inventario lleva el control del stock de insumos (ingredientes, bebidas, empaque, suministros) y lo conecta directamente con las recetas del menú. Cada vez que el KDS marca un plato como listo, bistro descuenta automáticamente los ingredientes de la receta. El resultado: siempre sabes cuánto tienes, qué cuesta y qué platos están en riesgo de agotarse.

El inventario es por **sede**. Si tienes varias sedes, cada una lleva su propio control — pero un insumo puede estar en varias bodegas dentro de una misma sede.

## Insumos (ingredientes)

Cada insumo tiene:

- **Nombre y unidad de medida** (kg, g, ml, L, unidad, porción, etc.).
- **Costo por unidad** — el costo actual. Cuando subes una compra, el costo se actualiza automáticamente si cambias el precio unitario en la orden de compra.
- **Bodega** — a cuál bodega pertenece (cocina, barra, almacén, etc.).
- **Stock actual** — la cantidad disponible en ese momento, resultado de todos los movimientos acumulados.
- **Umbral de alerta** — si el stock cae por debajo de este valor, aparece una alerta en el panel de [alertas](/manual/alertas).

### Crear y editar insumos

Desde **inventario → nuevo insumo**. El nombre debe ser único dentro de la sede. Si ya existe pero está archivado, lo puedes reactivar en lugar de crear uno nuevo.

Para **archivar** un insumo: en el menú de la fila (…) → archivar. El sistema lo saca de la vista activa pero conserva todo su historial. No se puede archivar un insumo que está en una receta activa de un plato publicado.

## Bodegas

Una sede puede tener varias **bodegas**: cocina, barra, congelador, almacén general. Las bodegas se configuran en *configuración → sedes → bodegas*. Cada insumo pertenece a una bodega, y el stock se lleva por bodega — no hay un "total de sede" que mezcle todo.

Si necesitas mover insumos entre bodegas de la misma sede, usa una **transferencia** (ver abajo).

<div class="callout callout-warn">
<p>
<strong>No puedes archivar una bodega con stock.</strong> Primero hay que sacar el stock
(transferir o registrar una merma) y luego archivar.
</p>
</div>

## Movimientos de inventario

Cada cambio en el stock queda registrado como un **movimiento**. Hay cuatro tipos:

<table>
<thead>
<tr>
<th>Tipo</th>
<th>Cuándo se usa</th>
<th>Signo</th>
</tr>
</thead>
<tbody>
<tr>
<td>
<strong>Entrada</strong>
</td>
<td>
Llegó mercancía: una compra, una donación, una transferencia entre sedes. También
se genera automáticamente al marcar una orden de compra como "recibida".
</td>
<td>+ (suma)</td>
</tr>
<tr>
<td>
<strong>Merma</strong>
</td>
<td>
Se perdió insumo: vencimiento, derrame, rotura. Lleva un campo de motivo para
tener trazabilidad.
</td>
<td>− (resta)</td>
</tr>
<tr>
<td>
<strong>Ajuste</strong>
</td>
<td>
El conteo físico no coincide con el sistema. Corrección manual que puede sumar o
restar. Requiere motivo y queda en auditoría.
</td>
<td>± (puede ser ambos)</td>
</tr>
<tr>
<td>
<strong>Transferencia</strong>
</td>
<td>
Mover insumos entre bodegas dentro de la misma sede. Sale de la bodega origen y
entra a la bodega destino en el mismo movimiento.
</td>
<td>− en origen / + en destino</td>
</tr>
</tbody>
</table>

<div class="callout callout-info">
<p>
<strong>Movimiento automático por receta:</strong> cuando el KDS marca un plato como
"listo", bistro descuenta automáticamente cada ingrediente de la receta del plato,
según la cantidad configurada. Ese movimiento aparece en el historial como "consumo por
orden".
</p>
</div>

## Historial de movimientos

En la fila de cualquier insumo → icono de historial (reloj) → se abre el panel lateral con todos los movimientos de ese insumo en orden cronológico: fecha, tipo, cantidad, quién lo registró y el motivo (si aplica). Puedes filtrar por fecha.

## Gráfica de valorización

Al tope de la página de inventario hay una gráfica que muestra el **valor total del inventario** (stock × costo unitario) a lo largo del tiempo. Útil para detectar pérdidas o días en que el stock se disparó por una compra grande.

Puedes cambiar el rango de fechas con el selector. El cálculo usa el costo vigente en cada movimiento, no el costo actual.

## Integración con recetas

Las recetas se configuran en el menú de cada plato (ver [Menús](/manual/menus)). Cada ítem de receta apunta a un insumo del inventario con una cantidad. Para que el descuento automático funcione:

1. El insumo debe existir en inventario y tener stock mayor a cero.
2. La receta del plato debe tener ese insumo con la cantidad correcta.
3. El KDS debe marcar el plato como "listo" — el descuento se hace en ese momento, no cuando llega el pedido.

Si el stock de un ingrediente llega a cero y un plato lo requiere, bistro marca ese plato como **agotado** en el menú público automáticamente.

## Filtros y búsqueda

Desde la lista de inventario puedes filtrar por:

- **Bodega** — para ver solo los insumos de cocina, solo los de barra, etc.
- **Estado** — activos o archivados.
- **Búsqueda por nombre.**

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
<td>Ver inventario y movimientos</td>
<td>Propietario, Administrador, Bodeguero, Gerente.</td>
</tr>
<tr>
<td>Crear y editar insumos</td>
<td>Propietario, Administrador, Bodeguero.</td>
</tr>
<tr>
<td>Registrar movimientos (entrada / merma / ajuste)</td>
<td>Propietario, Administrador, Bodeguero.</td>
</tr>
<tr>
<td>Transferir entre bodegas</td>
<td>Propietario, Administrador, Bodeguero.</td>
</tr>
<tr>
<td>Archivar insumos</td>
<td>Propietario, Administrador, Bodeguero.</td>
</tr>
</tbody>
</table>

<div class="callout callout-success">
<p>
<strong>Rutina recomendada:</strong> haz un conteo físico una vez por semana y compáralo
con el sistema. Si hay diferencia, registra un ajuste con motivo "conteo físico MM/DD".
Así el historial queda limpio y puedes detectar mermas sistemáticas antes de que se vuelvan
un problema.
</p>
</div>
