---
title: "Entregas / Domicilios"
description: "La lista de domicilios, asignación de domiciliario, modo auto-asignación, reasignación, avisos por WhatsApp y métricas de entregas."
metaTitle: "Entregas / Domicilios — Manual bistro.flexyflow.co"
metaDescription: "Cómo gestionar los domicilios en bistro.flexyflow.co: asignación manual y automática, reasignación, cancelación y métricas de entrega."
section: "el día a día"
readingTime: "8 min"
lastUpdated: "8 de julio de 2026"
---

![Panel de entregas y domicilios](/images/manual/entregas.svg "Domicilios: pedidos listos para asignar y en tránsito.")

## La lista de entregas

En **pedidos → entregas** ves todos los pedidos a domicilio del día, con su estado, el domiciliario asignado (si aplica), la dirección y cuánto tiempo llevan en espera. Hay filtros por estado y por domiciliario.

Los estados de una entrega van de: *pendiente de asignación → en camino → entregado*. Hay también *rechazado* y *revertido* para casos especiales.

## Asignación manual

El operador selecciona un pedido y le asigna un domiciliario de la lista de disponibles. En ese momento, si tienes WhatsApp conectado y la notificación activada, el cliente recibe un aviso automático con el nombre del domiciliario.

## Modo auto-asignación

Con la auto-asignación activada, los domiciliarios pueden ver los pedidos pendientes de asignación en su propia vista y **tomarse ellos mismos** los pedidos que van a repartir. Útil para operaciones con muchos repartidores independientes donde no hay un operador asignando uno por uno.

Para activársela a un domiciliario, el administrador le asigna el permiso de auto-asignación desde el panel de usuarios.

<div class="callout callout-info">
<p>
<strong>Vista del domiciliario:</strong> el domiciliario entra a la app con su cuenta y ve solo
sus pedidos activos y los pendientes de asignación (si tiene auto-asignación). No ve nada más del panel.
</p>
</div>

## Reasignación

Si un domiciliario se cayó, se le ponchó la moto o hay que redistribuir por volumen, puedes reasignar un pedido a otro domiciliario. El sistema registra el cambio con fecha, hora y quién lo hizo.

## Finalizar, rechazar y revertir

- **Finalizar entrega:** el domiciliario (o el operador) confirma que el pedido llegó. El pedido pasa a "entregado" y el cobro queda registrado.
- **Rechazar entrega:** el domiciliario no pudo entregar (cliente no contestó, dirección incorrecta, etc.). El pedido vuelve al operador para decidir qué hacer: reasignar, cancelar o revertir.
- **Revertir a pendiente:** el pedido vuelve a "pendiente de asignación" para intentar de nuevo.

## Avisos por WhatsApp

Si tienes WhatsApp conectado y las notificaciones de domicilios activadas:

- **Al asignar:** el cliente recibe un mensaje con el nombre del domiciliario y (si está configurado) un enlace de seguimiento.
- **Al entregar:** el cliente recibe confirmación de entrega.

Si el aviso falla (WhatsApp caído, número inválido), la operación sigue normal. El error queda en el registro de soporte.

## Métricas de entregas

En **entregas → métricas** ves el resumen de desempeño del equipo de domicilios:

<table>
<thead>
<tr>
<th>Métrica</th>
<th>Qué mide</th>
</tr>
</thead>
<tbody>
<tr>
<td>Tiempo promedio de entrega</td>
<td>Desde la asignación hasta la confirmación de entrega.</td>
</tr>
<tr>
<td>Pedidos por domiciliario</td>
<td>Cuántos pedidos completó cada uno en el período.</td>
</tr>
<tr>
<td>Tasa de rechazo</td>
<td>Pedidos que no llegaron vs. pedidos asignados.</td>
</tr>
<tr>
<td>Tiempo en estado pendiente</td>
<td>Cuánto tarda en asignarse un pedido desde que llega.</td>
</tr>
</tbody>
</table>

<div class="callout callout-warn">
<p>
<strong>Área de cobertura:</strong> el radio de domicilios se configura en
<em>configuración → preferencias → pedidos → área de cobertura</em>. Si un cliente está fuera
del radio, el sistema no le deja confirmar el pedido a domicilio.
</p>
</div>
