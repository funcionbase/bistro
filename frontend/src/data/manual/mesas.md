---
title: "Mesas y QR"
description: "El mapa de mesas, las sesiones con QR, aprobación del mesero, cobro dividido y cierre de mesa."
metaTitle: "Mesas y QR — Manual bistro.flexyflow.co"
metaDescription: "Cómo funciona el sistema de mesas y QR de bistro.flexyflow.co: sesiones, aprobación, cobro dividido entre comensales y cierre automático."
section: "el día a día"
readingTime: "7 min"
lastUpdated: "8 de julio de 2026"
---

![Mesas y sesiones QR en bistro](/images/manual/mesas.svg "Las mesas de la sede con su estado y sesiones QR.")

## El mapa de mesas

En **pedidos → mesas** ves el mapa de todas las mesas del local. Cada mesa muestra su estado: libre, ocupada, o con sesión activa. Puedes abrir una sesión desde cualquier mesa libre o ver el detalle de las ocupadas.

Las mesas se configuran en *configuración → mesas*: les pones nombre (Mesa 1, Terraza 3, Barra 2), capacidad y área (salón, terraza, bar).

## Sesión de mesa con QR

Cada mesa tiene un QR físico (lo imprimes desde el panel). Cuando el cliente lo escanea, el sistema abre una sesión de mesa y lo lleva directo al menú de tu negocio — sin app, sin login, desde el navegador del celular.

El cliente puede ver la carta, agregar al carrito y confirmar el pedido. El pedido llega al tablero del panel con la mesa asociada, listo para que el mesero lo apruebe.

### Aprobación del mesero

Los pedidos de mesa llegan en estado **pendiente**. El mesero los aprueba (pasan a "en cocina") o los rechaza con un motivo. Esto permite corregir errores antes de que la cocina empiece a preparar.

Si tienes *auto-confirmar* activado en preferencias, la aprobación manual no aplica y los pedidos entran directo en cocina.

<div class="callout callout-warn">
<p>
<strong>QR de sesión vs QR fijo:</strong> hay dos modos. (1) QR fijo — siempre apunta al menú
del negocio; el cliente escoge la mesa manualmente. (2) QR por mesa — cada mesa tiene su propio
QR y el sistema sabe a cuál mesa pertenece. El modo 2 requiere tener las mesas configuradas.
</p>
</div>

### Pedir para llevar desde el QR de la sede

El QR fijo de la sede también sirve para clientes que **no van a sentarse**: al escanearlo pueden pedir **para llevar o a domicilio** sin escoger mesa. El pedido llega al tablero como cualquier otro, marcado con su tipo, y sigue el flujo normal de aprobación y cobro. Es ideal para imprimirlo en la barra, la entrada o el empaque.

## Múltiples rondas de pedidos en la misma sesión

En la misma sesión de mesa, el cliente puede hacer **varias rondas de pedidos** sin cerrar la sesión ni escanear el QR de nuevo. Ordena las entradas, el mesero las aprueba, y más tarde puede pedir el plato fuerte con un segundo pedido en la misma sesión. El total acumulado de la mesa crece con cada ronda aprobada.

Desde la vista del mesero, cada ronda de pedidos aparece como un pedido separado dentro de la misma sesión, para que sea fácil ver qué aprobó cuándo.

El cliente también lo ve así en su celular: su cuenta aparece **agrupada por tandas**, cada una con lo que pidió y su subtotal, más el total acumulado de la mesa. Así nadie se pierde de cuánto va la cuenta aunque hayan pedido en tres momentos distintos.

### Agregar productos desde el panel

El mesero también puede **agregar productos a una mesa activa** directamente desde el detalle del pedido en el panel — útil cuando el cliente pide algo de viva voz en vez de usar el celular. Lo agregado entra a la misma cuenta de la mesa, como una tanda más.

### Cancelar un ítem ya aprobado

Si el cliente o el mesero necesita cancelar un ítem que ya fue aprobado (pero que todavía no pasó a la cocina), hay un botón **"Cancelar ítem"** disponible en la tarjeta del pedido. Se puede ingresar un motivo opcional (por ejemplo, "cliente cambió de opinión"). Los ítems cancelados quedan registrados en el historial de la sesión.

### Asignar mesa a un pedido QR sin mesa

Cuando un pedido llega por QR sin mesa asignada (por ejemplo, de alguien que pidió por el bot de WhatsApp y quiere atenerse en salón), el mesero puede asignarle una mesa física directamente desde la sesión. La mesa queda vinculada al pedido y ya aparece en el mapa.

## La cuenta en vivo

El cliente puede ver su cuenta en tiempo real escaneando el QR de la mesa. Puede agregar más pedidos en la misma sesión (sujeto a aprobación del mesero). Cuando decide pagar, el mesero va a la mesa en el panel y ejecuta el cobro.

## Cobro dividido

Cuando hay varios comensales en la misma mesa, puedes dividir la cuenta:

- **Partes iguales:** divides el total entre N personas.
- **Por ítem:** cada persona paga lo que pidió.
- **Mixto:** algunas partes iguales, otras por ítem.

Cada parte puede pagarse con método diferente (uno en efectivo, otro con tarjeta). El cobro total de la mesa cierra cuando se pagan todas las partes.

## Cierre de mesa

Cuando se cobra el último pago, la sesión de mesa se cierra automáticamente y la mesa queda libre en el mapa. El sistema registra el historial completo de la sesión: quién ordenó qué, cuándo, y cómo se pagó.

<div class="callout callout-info">
<p>
<strong>La mesa no se libera sola con cuenta pendiente:</strong> aunque los comensales lleven
un buen rato sin tocar el celular, mientras haya productos servidos sin pagar la mesa sigue
ocupada y la cuenta sigue visible en el panel. La sesión solo se cierra cuando se cobra (o
cuando el mesero la cierra a mano). Las mesas que se cierran solas por inactividad son
únicamente las que no tienen nada pendiente de pago.
</p>
</div>

<div class="callout callout-success">
<p>
<strong>Flujo rápido:</strong> cliente escanea QR → ordena desde el celular → mesero aprueba →
cocina prepara → mesero trae → cliente pide la cuenta → cobro dividido si aplica → mesa libre.
Todo sin papel ni gritos cruzados.
</p>
</div>
