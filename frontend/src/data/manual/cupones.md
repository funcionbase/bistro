---
title: "Cupones y descuentos"
description: "Promociones controladas: porcentaje o monto fijo, vigencia, alcance por sede, primer pedido, un solo uso por cliente, hora feliz automática e historial exportable."
metaTitle: "Cupones y descuentos — Manual bistro.flexyflow.co"
metaDescription: "Cupones por porcentaje o monto fijo, alcance por sede, hora feliz automática, un solo uso por teléfono, primer pedido, historial y exportación a PDF."
section: "clientes y mercadeo"
readingTime: "8 min"
lastUpdated: "8 de julio de 2026"
---

![Cupones y descuentos en bistro](/images/manual/cupones.svg "Cupones activos con su vigencia y usos.")

## Crear un cupón

En **cupones** le das a <kbd>Nuevo cupón</kbd>. El formulario te pide:

- **Código:** lo escribes (por ejemplo *BIENVENIDA10*) o le das a "Generar" para que el sistema saque uno aleatorio (sin letras confusas como O/0, I/1). Solo letras, números, guiones y guiones bajos. Mínimo 4, máximo 20 caracteres. **No se puede repetir dentro de tu negocio**.
- **Tipo de descuento:**
    - *Porcentaje* — por ejemplo, 15% off. Tope máximo: 80%.
    - *Monto fijo* — por ejemplo, $5.000 menos. Tope máximo: $100.000.
- **Vigencia:** fecha de inicio y fecha de fin.
- **Tope de usos:** cuando se alcanza, el cupón se agota automáticamente. Si lo dejas vacío, es ilimitado.
- **Monto mínimo del pedido:** el carrito tiene que llegar a este total para que aplique. Útil para no regalar dinero en pedidos pequeños.
- **Solo primer pedido:** el cliente solo puede usarlo si nunca te había comprado antes. Para campañas de bienvenida.
- **Un solo uso por cliente:** el cupón queda *amarrado al teléfono* del primer cliente que lo usó. Aunque queden usos disponibles en el cupón general, ese mismo teléfono no puede volver a aplicarlo. Útil para promociones personales que no quieres que se compartan en grupos de WhatsApp.
- **Alcance (sedes):** el cupón puede valer para *todas tus sedes* o solo para las que tú escojas. Útil si quieres lanzar una promo solo en El Poblado y no en Laureles.
- **Aplicación automática:** el sistema lo carga al carrito sin que el cliente tenga que escribir el código (ver hora feliz, abajo).

## Hora feliz: días y horas específicas (aplicación automática)

Una de las cosas más útiles: puedes crear un cupón que aplica solo **ciertos días y ciertas horas**, y que se aplica **solito al cliente** sin que tenga que escribir el código.

- **Días válidos:** seleccionas los días de la semana (ej. solo martes y miércoles).
- **Horas válidas:** rango horario (ej. 7 PM a 10 PM). Si tu hora feliz pasa medianoche (10 PM a 2 AM), el sistema lo entiende.
- **Aplicación automática:** el sistema busca el mejor cupón automático que aplica y lo carga al pedido sin que el cliente lo escriba. Si hay varios elegibles, gana el de mayor descuento. Al cliente le aparece un mensaje en el carrito tipo *"¡Felicidades! Aplicamos MARTES2X1"*.

### Un ejemplo de hora feliz

Una **pizzería** quiere llenar las noches de martes. Crea el cupón *MARTES2X1*:

- Tipo: porcentaje, 50%.
- Días válidos: solo martes.
- Horas válidas: 7 PM a 10 PM.
- Monto mínimo: $35.000 (para que aplique solo en pizza familiar).
- Aplicación automática: sí.

El martes a las 7:30 PM, un cliente ordena pizza familiar por $40.000. Sin que escriba código, el sistema le aplica 50% solito. El total final son $20.000. Le aparece en el carrito: *"¡Felicidades! Aplicamos MARTES2X1 (-$20.000)"*.

## Editar y eliminar

- **Editar las reglas** (descuento, vigencia, etc.): solo si **nadie ha usado el cupón todavía**. Una vez que un cliente lo redime, las reglas quedan fijas para que el historial sea fiel. Si necesitas cambiar reglas, crea uno nuevo.
- **Activar / desactivar:** esto sí puedes hacerlo siempre, aunque el cupón tenga usos. Sirve para pausar campañas en curso sin perder la configuración.
- **Eliminar:** si nunca se usó, se borra. Si ya se redimió alguna vez, se archiva pero queda en el historial.

## Detalle del cupón

El detalle muestra el resumen y el historial de quién lo usó:

- Monto del pedido antes y después del descuento.
- Teléfono del cliente, parcialmente oculto (ej. *+57 300 *** **34*).
- Las últimas 50 redenciones por página, con fecha y hora.

Desde el detalle puedes **exportar el historial a PDF** para llevarlo a una reunión, anexarlo a un informe contable o mandárselo al socio que pregunta cuánto se gastó en la promo.

## Cómo el cliente aplica el cupón

En el carrito (lado cliente o en la [caja](/manual/bistro/caja) del POS), escribe el código y el descuento se calcula al instante. El sistema verifica:

1. Que el cupón exista, esté activo y dentro de fechas.
2. Que no haya alcanzado el tope de usos.
3. Que el carrito cumpla el monto mínimo.
4. Si aplica, que sea el primer pedido del cliente.
5. Si tiene reglas de hora feliz, que estemos en día y hora válidos.
6. Si está limitado a sedes específicas, que la sede del pedido sea una de las permitidas.
7. Si el cupón es de un solo uso por persona, que ese teléfono no lo haya usado antes.

**Solo un cupón por pedido.** Si el cliente intenta aplicar otro, el nuevo reemplaza al anterior.

### Cupones de fidelización

Cuando un cliente canjea sus puntos (ver [fidelización](/manual/bistro/fidelizacion)), el sistema le emite un cupón temporal único, amarrado a su teléfono y con vencimiento corto (por defecto 60 minutos). Ese cupón no aparece en el listado público de cupones — vive aparte para que tu campaña promocional y tu programa de puntos no se mezclen visualmente.

## Cómo se trata el descuento contablemente

El descuento **reduce la base gravable** de la orden, no se trata como un pago. El subtotal y el impuesto se recalculan proporcionalmente para que el total cuadre. Esto es DIAN-friendly y deja la contabilidad bien hecha.

<div class="callout callout-warn">
<p>
<strong>Atención:</strong> el cálculo del descuento siempre se hace en el servidor con los
precios oficiales del menú. Si un cliente "modifica" el precio desde el navegador, la app lo
ignora. No se pierde dinero por manipulación.
</p>
</div>

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
<td>Ver cupones</td>
<td>Consultar la lista, el detalle y el historial.</td>
</tr>
<tr>
<td>Crear</td>
<td>Agregar cupones nuevos.</td>
</tr>
<tr>
<td>Editar</td>
<td>Modificar (si nadie lo ha usado), activar o desactivar siempre.</td>
</tr>
<tr>
<td>Eliminar</td>
<td>Borrar el cupón.</td>
</tr>
</tbody>
</table>

<div class="callout callout-success">
<p>
<strong>Combinación recomendada:</strong> crea un cupón <em>BIENVENIDA15</em> con 15% off,
monto mínimo de $25.000 y marca "solo primer pedido". Lo promocionas en tu página de pedidos
y en redes. Es un imán para atraer al que duda en probar tu operación.
</p>
</div>
