---
title: "Puntos de fidelidad"
description: "El cliente acumula puntos cada vez que te compra y los canjea por descuentos. Niveles, libro mayor auditado, expiración automática y reportes consolidados entre sedes."
metaTitle: "Puntos de fidelidad — Manual bistro.flexyflow.co"
metaDescription: "Programa de fidelización con puntos por compra, niveles (bronce, plata, oro), canje por cupones amarrados al teléfono, libro mayor auditado y reportes consolidados entre sedes."
section: "clientes y mercadeo"
readingTime: "7 min"
lastUpdated: "8 de julio de 2026"
---

![Reportes de puntos de fidelidad](/images/manual/fidelizacion.svg "Fidelización: puntos otorgados y redimidos.")

## Cómo funciona

Por cada pedido completado, el cliente acumula puntos en una cuenta que vive a nivel de empresa (no por sede). Esos puntos los puede canjear por descuentos predefinidos: *"500 pts = $5.000 de descuento"*, por ejemplo.

El programa es **opcional** y se prende desde [configuración](/manual/configuracion). Si no lo activas, los clientes nunca ven el panel de puntos y la operación no cambia.

### Una sola cuenta entre todas las sedes

Si tienes [varias sedes](/manual/sedes), los puntos del cliente se suman en una sola cuenta. **Don Hernán pide en la sede de El Poblado el lunes y en la de Laureles el viernes** — los puntos van al mismo saldo. Cuando canjee, puede usar el descuento en cualquier sede.

### El libro mayor de puntos

Cada punto que entra o sale queda anotado como un **movimiento auditado**: cuándo, por qué, asociado a qué pedido. Nada se edita ni se borra después. Si hay que corregir algo, se hace con un movimiento nuevo (positivo o negativo). Es como una contabilidad — transparente y fácil de auditar.

## Cómo se ganan los puntos

Cuando cierras un pedido cobrado (no aplica con cancelados ni devueltos), el sistema le asigna puntos al cliente con esta fórmula. Si por alguna razón el pedido se procesa dos veces (un reintento de cobro, por ejemplo), los puntos se otorgan una sola vez — nunca se duplican.

`puntos = pedido_total × tasa_de_puntos × multiplicador_de_nivel`

- **Tasa de puntos:** por defecto, 1 punto por cada $100 gastados. Configurable.
- **Multiplicador de nivel:** el cliente sube de nivel según cuánto ha gastado en su vida con tu operación. Cada nivel tiene un multiplicador:
    - **Bronce** — 1.0× (tasa base)
    - **Plata** — 1.2× (gana 20% más puntos)
    - **Oro** — 1.4× (gana 40% más puntos)

### Un ejemplo

Laura tiene nivel Plata. Hace un pedido de $35.000:

`35.000 × (1 ÷ 100) × 1.2 = 420 puntos`

Se le suman a su cuenta. Si ya tenía 1.080, ahora tiene 1.500. Listo para canjear su próximo descuento.

## Catálogo de recompensas

Defines un catálogo de recompensas que el cliente puede canjear con sus puntos. Cada una tiene:

- **Etiqueta** (ej. *"$5.000 de descuento"*).
- **Puntos necesarios** (ej. 500 pts).
- **Tipo de descuento:** hoy solo monto fijo (ej. $5.000 menos en el pedido).
- **Monto mínimo de pedido** para poder usarlo (ej. el pedido debe ser de al menos $25.000).

### Cómo se hace un canje

1. El cliente entra al carrito (o tú lo abres en su nombre desde la ficha del cliente).
2. Ve su saldo de puntos y las recompensas disponibles.
3. Elige una que tenga puntos suficientes. Le da a "Canjear".
4. El sistema le resta los puntos al instante y le genera un cupón único, válido por unos minutos (configurable, 60 por defecto), **amarrado solo a su teléfono** — nadie más puede usarlo aunque lo reenvíen por WhatsApp.
5. El cliente aplica ese cupón en el carrito como cualquier otro. En el lado cliente, el código se inyecta automáticamente para que solo presione "Aplicar".

Si el cupón expira sin usarse, **los puntos no se devuelven** — el cliente asumió ese riesgo al canjear. Pero si tú, el operador, anulas el canje antes de que se use, los puntos vuelven a la cuenta con un movimiento auditado y un motivo.

## Niveles y subida automática

El nivel del cliente se recalcula cada vez que gana puntos. Se basa en su **total acumulado de por vida** (no en el saldo actual). Por ejemplo, los niveles por defecto:

- **Bronce** — desde 0 puntos.
- **Plata** — a partir de 2.000 puntos acumulados.
- **Oro** — a partir de 10.000 puntos acumulados.

Los umbrales son configurables. El cliente nunca "baja" de nivel porque canjeó — el nivel mide su gasto histórico, no su saldo actual.

## Devoluciones y los puntos

- **Devolución total** de un pedido: los puntos que se otorgaron se reversan automáticamente.
- **Devolución parcial**: los puntos no se reversan (decisión pragmática — el incentivo del cliente se mantiene).

## Ajustes manuales

Desde la ficha del cliente puedes **ajustar puntos a mano**: sumar o restar con un motivo. Útil para:

- Compensar una mala experiencia ("+500 pts por la demora").
- Corregir un error operativo ("+200 pts, le faltaba un pedido").
- Reconocer algo especial ("+1000 pts por su cumpleaños").

Cada ajuste manual queda auditado con tu identidad y el motivo. Tiene un tope (por defecto, 10.000 puntos por ajuste) para evitar accidentes. Los ajustes positivos **no** suman al acumulado del nivel (para que un regalo no te suba a alguien de nivel artificialmente).

## Expiración

Puedes configurar que los puntos se venzan si el cliente no compra durante un tiempo determinado (el período es configurable por ti). Si lo dejas en cero, los puntos nunca se vencen.

La expiración se ejecuta **cada madrugada en un proceso automático**. Al cliente que se le venzan los puntos se le crea un movimiento auditado con el detalle. Ese mismo proceso también vence los cupones de canje que no se usaron a tiempo.

## Reportes de fidelización

En **fidelización → reportes** tienes la vista consolidada del programa, con datos cruzados entre todas las sedes. Por defecto te muestra los últimos 30 días:

- Puntos **otorgados, canjeados, vencidos y reversados** en el período.
- Cuántos clientes activos tienes en el programa y cuántos eventos de ganancia/canje hubo.
- **Tasa de canje:** qué porcentaje de cupones emitidos efectivamente se usó.
- Distribución de clientes por nivel (cuántos bronce, plata, oro).
- **Ingreso promedio por nivel:** cuánto gasta en promedio un cliente bronce vs uno plata vs uno oro.
- Los 20 mejores clientes por puntos acumulados de por vida.

## Quién puede hacer qué

<table>
<thead>
<tr>
<th>Acción</th>
<th>Qué permite</th>
</tr>
</thead>
<tbody>
<tr>
<td>Ver fidelización</td>
<td>Consultar saldos, reportes y movimientos.</td>
</tr>
<tr>
<td>Editar</td>
<td>Ajustar puntos a mano y hacer canjes a nombre del cliente.</td>
</tr>
</tbody>
</table>

<div class="callout callout-info">
<p>
<strong>Consejo de mercadeo:</strong> avísales a los clientes nuevos del programa cuando hagan
su primer pedido. Un WhatsApp simple como <em>"¡Bienvenido! Acabas de ganar tus primeros 200
puntos. Con 500 puntos tienes $5.000 de descuento en tu próximo pedido"</em> genera mucha más
recompra que un cupón frío.
</p>
</div>
