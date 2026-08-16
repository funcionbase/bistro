---
title: "Menús"
description: "Cómo crear y publicar tu carta, editar platos, subir fotos, definir recetas para el inventario, configurar el KDS y generar el QR."
metaTitle: "Menús — Manual bistro.example.com"
metaDescription: "Cómo crear categorías, platos, recetas, fotos, menú público, QR y configurar el KDS de cocina en bistro.example.com."
section: "el día a día"
readingTime: "9 min"
lastUpdated: "8 de julio de 2026"
---

![Gestión de menús en bistro](/images/manual/menus.svg "Tus menús y platos, organizados por categoría.")

## La lista de menús

Un negocio puede tener varios menús (carta de almuerzo, carta de cena, menú de temporada). Solo uno puede estar **publicado** (activo y visible al cliente) a la vez. Los demás quedan como borradores — los editas sin que el cliente los vea y los publicas cuando los necesites.

Desde la lista puedes crear un menú nuevo, ver cuál está publicado y cambiar cuál se activa. Al publicar un borrador, el anterior queda como borrador automáticamente — nunca queda el negocio sin carta.

## El editor de menú

Adentro del menú organizas **categorías** y dentro de cada categoría van los **platos** (o ítems). El orden de las categorías y de los platos dentro de cada una se cambia arrastrando.

### Categorías

- Nombre y descripción opcional.
- **Disponibilidad:** siempre disponible, o solo en ciertas horas (útil para desayunos o menú ejecutivo).
- **Estación de cocina (KDS):** qué pantalla de cocina prepara esta categoría. Puedes tener "cocina caliente" para platos y "barra" para bebidas, por ejemplo.

### Platos

- Nombre, descripción, precio.
- **Impuesto específico:** si este plato tiene un régimen distinto al del negocio (por ejemplo, la cerveza lleva IVA 19% mientras el resto lleva INC 8%).
- **Estado:** disponible, agotado o borrador. El agotado lo ve el cliente pero sin poder pedirlo; el borrador no se muestra.
- **Receta:** lista de ingredientes con cantidades. Cuando se marca listo en cocina, el inventario se descuenta automáticamente.

## Fotografías

Cada plato acepta hasta 5 fotos (JPG, PNG, WEBP, máximo 5 MB cada una). La primera foto es la portada. Arrastrar para reordenar.

Las fotos se optimizan automáticamente — no tienes que preocuparte por el tamaño del archivo que subas desde el celular.

## Límites

- Máximo 10 categorías por menú.
- Máximo 50 platos por categoría.
- Máximo 500 platos en total por menú.

## Menú público

El menú publicado queda disponible en la página de pedidos de tu negocio. Cualquier cliente puede verlo sin iniciar sesión. El color de los botones y el logo son los que configuraste en *configuración → información del negocio*.

Los platos agotados se muestran tachados. Los borradores no aparecen. Si el negocio está cerrado (según los horarios configurados), la página avisa y no deja agregar al carrito.

## Estaciones KDS

Cada categoría del menú puede ir a una estación de cocina diferente. Cuando la orden entra, bistro separa los ítems por estación y manda cada parte a la pantalla correcta. El cocinero ve solo lo suyo, el bartender solo lo de barra.

### Configuración de estaciones

Las estaciones se configuran en *configuración → KDS*. Al crear el negocio se siembran automáticamente según la vertical (restaurante, bar, cafetería, dark kitchen). Puedes renombrarlas, cambiarles el color y añadir o eliminar estaciones según lo que tenga tu local.

## QR del menú

En *menús → QR* generas el código QR que apunta al menú público de tu negocio. Puedes descargarlo en PNG o SVG para imprimirlo en mesas, carteleras o empaques.

El QR nunca cambia aunque actualices el menú — apunta al negocio, no a una versión fija de la carta.

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
<td>Ver menú</td>
<td>Consultar la carta y sus detalles.</td>
</tr>
<tr>
<td>Crear</td>
<td>Agregar categorías, platos, menús nuevos.</td>
</tr>
<tr>
<td>Actualizar</td>
<td>Editar precios, fotos, disponibilidad, recetas. Marcar agotados.</td>
</tr>
<tr>
<td>Eliminar</td>
<td>Borrar platos o categorías. Solo si no tienen pedidos en curso.</td>
</tr>
<tr>
<td>Publicar</td>
<td>Cambiar cuál menú está activo (visible al cliente).</td>
</tr>
</tbody>
</table>

<div class="callout callout-info">
<p>
<strong>Truco para empezar:</strong> empieza con 2-3 categorías y los platos más vendidos. Un menú
corto bien fotografiado convierte mejor que una carta de 80 platos sin foto.
</p>
</div>
