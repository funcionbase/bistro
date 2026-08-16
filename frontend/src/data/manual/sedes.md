---
title: "Sedes y bodegas"
description: "Cada sede de tu operación con su caja, su inventario, su menú, su tablero de pedidos y hasta su propia vertical de negocio. Aislamiento de datos por NIT y por sede."
metaTitle: "Sedes y bodegas — Manual bistro.example.com"
metaDescription: "Si tu negocio tiene varios locales bajo el mismo NIT, cada sede opera con su caja, su inventario, su menú y sus reportes aparte. Bodegas internas, copia de menú y reportes consolidados."
section: "administración"
readingTime: "7 min"
lastUpdated: "8 de julio de 2026"
---

![Sedes y bodegas de la empresa](/images/manual/sedes.svg "Tus sedes con sus bodegas y cajas asociadas.")

## ¿Quién necesita esto?

Si manejas un solo local, no te preocupes — bistro crea automáticamente una sede "Principal" al darte de alta y todo funciona sin que tengas que pensar en esto. Esta página es para:

- Cadenas con varios locales bajo el mismo NIT (pizzerías, hamburgueserías, panaderías, bares, sucursales en distintos barrios o ciudades).
- Negocios que abren una sede nueva y necesitan separar su operación.
- Operaciones con verticales mixtas: una cadena con un restaurante en Laureles y una dark kitchen en El Poblado bajo el mismo NIT.

## Cómo funciona

Un negocio (un NIT) puede tener **tantas sedes como necesite**. Cada sede tiene su propia operación independiente:

- Su propia [caja](/manual/caja) (los turnos no se cruzan entre sedes).
- Su propio [tablero de pedidos](/manual/pedidos) y de cocina (KDS).
- Su propio inventario, con una o varias bodegas internas.
- Sus propios reportes operativos.
- Su propio menú: lo puedes copiar entre sedes y a partir de ahí cada una lo edita por separado.
- Su propia **vertical de negocio**: una sede puede ser restaurante, otra bar, otra cafetería y otra dark kitchen.

Comparten a nivel de negocio: la base de clientes (CRM), los cupones, el programa de fidelización, el plan contratado, la facturación y la configuración fiscal DIAN.

## Crear y gestionar sedes

Desde **configuración → sedes** puedes:

- **Crear una sede nueva** con nombre, identificador corto, dirección, ciudad y la vertical de negocio que aplique.
- **Marcar una como predeterminada** — la que se abre por defecto al entrar al panel.
- **Asignar usuarios a cada sede** — un miembro puede pertenecer a varias sedes a la vez.
- **Cambiar la vertical** de una sede operativa sin tener que recrearla.
- **Archivar** sedes que ya no operan. El sistema no borra: solo las pone en "histórico" para que no se pierdan datos.

<div class="callout callout-warn">
<p>
<strong>Última sede activa:</strong> no puedes archivar la única sede activa que te queda.
Un negocio siempre debe tener al menos una sede operando.
</p>
</div>

## Cambio de sede activa

Si tu cuenta tiene acceso a varias sedes, te aparece un selector en el menú lateral. Lo abres, eliges la sede a la que quieres entrar y el panel se recarga con los datos de esa sede.

<div class="callout callout-info">
<p>
<strong>Cambio bloqueado con caja abierta:</strong> si la sede en la que estás tiene una caja
en turno abierto, bistro bloquea el cambio de sede hasta que se cierre el turno. El
Propietario sí puede saltarse este bloqueo con un permiso especial.
</p>
</div>

## Aislamiento por diseño

Cada fila de pedido, cobro, comanda, sesión de mesa y movimiento de inventario lleva en su ADN la sede a la que pertenece:

- Si la cajera de El Poblado abre una mesa, no la ve la cajera de Laureles.
- Una devolución se ejecuta en la sede donde se hizo el cobro original.
- La sede de una orden **no se puede cambiar** después de creada.

## Bodegas dentro de una sede

Cada sede puede tener una o varias **bodegas**: cocina, barra, congelador, almacén general. Los insumos viven en una bodega específica, no en la sede genérica.

El stock, los movimientos y la valorización se calculan por bodega. No puedes archivar una bodega que tiene stock — primero hay que sacarlo o transferirlo.

## Reportes consolidados (vista global)

El Propietario puede ver reportes **consolidados** de todas las sedes a la vez sin tener que ir cambiando sede una por una. Para otros miembros (un contador externo, un supervisor regional) se la puedes habilitar con el permiso **"ver todas las sedes en reportes"**.

## Un ejemplo: cadena con vertical mixta

Una **operación de tres sedes** en Medellín, todas bajo el mismo NIT:

- **Sede Laureles** (predeterminada) — vertical restaurante. Atiende mesa y domicilio. Dos bodegas: cocina y barra.
- **Sede El Poblado** — vertical dark kitchen. Solo domicilios desde una cocina ciega. Una bodega: cocina.
- **Sede Envigado** — vertical bar. Atiende mesa, sin domicilio. Dos bodegas: cocina y barra.

Don Hernán abre el reporte consolidado del mes: Laureles $42M, El Poblado $28M, Envigado $15M. **Total empresa: $85.000.000.**

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
<td>Crear, editar y archivar sedes</td>
<td>Solo Propietario (se le puede asignar al Administrador).</td>
</tr>
<tr>
<td>Cambiar la vertical de una sede</td>
<td>Solo Propietario.</td>
</tr>
<tr>
<td>Asignar usuarios a una sede</td>
<td>Propietario por defecto; asignable al Administrador.</td>
</tr>
<tr>
<td>Copiar menú entre sedes</td>
<td>Propietario por defecto; asignable al Administrador.</td>
</tr>
<tr>
<td>Reportes consolidados de todas las sedes</td>
<td>Propietario por defecto; asignable a otros roles con el permiso especial.</td>
</tr>
<tr>
<td>Crear y editar bodegas dentro de una sede</td>
<td>Propietario, Administrador y Bodeguero.</td>
</tr>
</tbody>
</table>
