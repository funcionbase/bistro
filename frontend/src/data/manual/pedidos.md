---
title: "Pedidos"
description: "Cómo llegan los pedidos, el tablero kanban, aprobación por QR de mesa, mover estados, inventario, actualizaciones en vivo, KDS y devoluciones."
metaTitle: "Pedidos — Manual bistro.flexyflow.co"
metaDescription: "El tablero de pedidos de bistro.flexyflow.co: kanban, aprobación, KDS, tickets térmicos, devoluciones y todo lo que pasa desde que llega un pedido hasta que se entrega."
section: "el día a día"
readingTime: "9 min"
lastUpdated: "8 de julio de 2026"
---

![Tablero de pedidos de bistro](/images/manual/pedidos.svg "El tablero de pedidos: cada columna es un estado.")

## Cómo llegan los pedidos

Hay cuatro fuentes de pedidos que convergen en el mismo tablero:

- **Menú público (web):** el cliente entra al enlace de tu negocio, agrega al carrito y confirma el pedido desde su navegador.
- **QR de mesa:** el cliente escanea el QR de la mesa, navega la carta y ordena. La mesa queda asociada al pedido.
- **WhatsApp (bot):** el cliente conversa con el bot, que arma el carrito y genera un enlace de confirmación.
- **Caja (POS):** el cajero toma el pedido directamente desde la [caja](/manual/bistro/caja), por teléfono o en mostrador.

## El tablero kanban

El tablero tiene columnas por estado: **Pendiente → En cocina → Listo → Completado**. Si tienes domicilios activos, aparece también la columna **En tránsito** (solo para pedidos a domicilio). Cada pedido vive en una tarjeta con la mesa, el cliente, los ítems y el total.

Puedes mover un pedido de columna arrastrando la tarjeta o usando el menú de la tarjeta. Hay filtros por fuente (mesa, web, WhatsApp, caja) y por estado.

### Aprobación de pedidos (QR de mesa)

Cuando un cliente pide desde el QR de su mesa, el pedido llega en estado **Pendiente esperando aprobación**. El mesero lo aprueba (lo mueve a "en cocina") o lo rechaza con un motivo. Esto es para que la cocina no empiece a preparar algo sin que el equipo lo haya validado.

Si tienes activado el **auto-confirmar pedidos** en preferencias, la aprobación manual no aplica: los pedidos entran directo en cocina.

<div class="callout callout-info">
<p>
<strong>Aviso de aprobaciones pendientes:</strong> cuando hay pedidos esperando aprobación del
mesero, aparece un aviso naranja en la parte superior del tablero, la caja y la vista de mesas.
Un clic te lleva directo al pedido para aprobarlo o rechazarlo sin buscar en el tablero.
</p>
</div>

## Mover pedidos entre estados

Las transiciones son *forward-only* (hacia adelante) salvo cancelar. Una vez que un pedido está "completado", no vuelve a "en cocina". Si hubo un error, la corrección se hace con una devolución — el registro contable queda limpio.

<div class="callout callout-info">
<p>
<strong>Estado "listo":</strong> cuando un pedido pasa a "listo", el cliente en la mesa puede
ver en su teléfono que su pedido está por llegar. Si tienes WhatsApp conectado, se puede
mandar un aviso automático.
</p>
</div>

## Mensajes de texto al cliente

Si el pedido tiene un número de teléfono, el cliente recibe un **mensaje de texto (SMS)** automático cuando su pedido cambia a un estado que le importa: entró en preparación, ya está listo, va en camino o fue entregado. El mensaje incluye el nombre de tu negocio y el código del pedido, por ejemplo: *"Flexy Burger: tu pedido #A3F9C1 va EN CAMINO"*.

No tienes que hacer nada para que salga — funciona sin importar desde dónde se movió el pedido (el tablero, la pantalla de cocina o la caja). Si un mensaje no se pudo enviar, el panel te lo avisa para que puedas contactar al cliente por otro medio.

## Inventario y recetas

Si tienes recetas configuradas en los platos, bistro flexy descuenta el inventario cuando la cocina marca el plato como listo. Si un ingrediente se acaba antes de que el turno termine, el plato se marca automáticamente como agotado en el menú público.

## Actualizaciones en vivo

El tablero de pedidos se actualiza solo en tiempo real. No tienes que darle F5 — cuando llega un pedido nuevo, aparece en la columna "pendiente" y suena una notificación de audio (configurable en el navegador). Si la pestaña está al fondo, la notificación push del navegador te avisa.

Al abrir el **detalle de un pedido**, la información también se refresca sola cada pocos segundos mientras tengas la pantalla abierta — si la cocina marca un plato como listo, lo ves sin cerrar y volver a entrar. Y si en algún momento quieres forzar la actualización, el botón de **refrescar** (la flecha circular) está siempre a la mano.

### El tablero en el celular

En pantallas de celular el tablero cambia de columnas a una **lista compacta**, parecida a la pantalla de cocina: cada pedido es una fila con su estado, y cambias de estado con un toque en lugar de arrastrar. Es la misma información, acomodada para operar con una sola mano.

## Pantalla KDS (cocina)

Cada estación de cocina tiene su propia vista de pantalla completa. Solo ve los ítems que le corresponden según las categorías asignadas. Desde esa vista el cocinero marca cada ítem como listo. Cuando todos los ítems de un pedido están listos, la comanda pasa a "listo" en el tablero del salón.

La pantalla KDS se abre en [configuración → KDS](/manual/bistro/configuracion) → link a la estación. Es una URL distinta que puedes poner en fullscreen en una tablet o monitor de cocina, independiente de la sesión del panel.

## Tickets térmicos

Si tienes impresoras térmicas configuradas, el ticket se imprime automáticamente cuando el pedido pasa a "en cocina" (comanda) y cuando se cobra (recibo). Las comandas van a la impresora de cocina o barra según la categoría del plato.

También puedes reimprimir cualquier ticket desde la tarjeta del pedido → menú → imprimir.

## Devoluciones

Desde la tarjeta del pedido puedes hacer una devolución total o parcial. Seleccionas los ítems a devolver, el método de devolución y el motivo. El sistema crea un nuevo registro de cobro con monto negativo (crédito) — el pedido original queda intacto para la trazabilidad contable.

<div class="callout callout-warn">
<p>
<strong>Devoluciones con tarjeta o transferencia:</strong> siempre hay que ingresar la
referencia del comprobante de la devolución. Es el único respaldo contable ante una auditoría
DIAN.
</p>
</div>

<div class="callout callout-success">
<p>
<strong>Pedidos activos en el dashboard:</strong> el panel de inicio siempre muestra cuántos
pedidos hay activos ahora mismo y en qué estado están, para que el dueño o gerente lo vea de
un vistazo sin entrar al tablero.
</p>
</div>
