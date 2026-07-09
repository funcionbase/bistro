---
title: "Alertas"
description: "Avisos accionables sobre stock, costos, margen, popularidad, mora con flexyflow y emisión electrónica DIAN. El sistema vigila tu operación y te dice qué revisar primero."
metaTitle: "Alertas — Manual bistro.flexyflow.co"
metaDescription: "Avisos accionables cuando un insumo se acaba, un costo se dispara, un plato deja de ser rentable, una factura entra en mora o la DIAN rechaza una emisión electrónica."
section: "números y reportes"
readingTime: "6 min"
lastUpdated: "8 de julio de 2026"
---

![Reportes y alertas del negocio](/images/manual/alertas.svg "Reportes: los números que disparan alertas.")

## Para qué sirven

Las alertas vigilan tu inventario, tus costos, la rentabilidad de cada plato, la popularidad de la carta, la mora con tu suscripción a flexyflow y la emisión electrónica DIAN. Cuando algo se sale del rango sano, te aparece una **alerta accionable** en el panel de inicio con la descripción y un enlace al sitio donde actuar.

No se automatiza nada — el sistema te dice qué pasa, tú decides qué hacer. Es una segunda opinión que mira números que tú no tienes tiempo de mirar todos los días.

## Alertas operativas

### Stock bajo

Cuando un insumo del inventario está por debajo de su stock mínimo, o cuando llegó a 0. La severidad cambia según qué tan apretado estás:

- **Crítica:** stock en cero. Si vendes platos que usen ese insumo, te tocaría agotarlos en la carta hasta que llegue la reposición.
- **Advertencia:** bajaste del mínimo pero todavía tienes margen de un par de turnos.

El mínimo se define **por bodega**. Si tienes cocina, barra y bodega seca por separado, cada una lleva su propio umbral. La alerta se prende si *cualquiera* de las bodegas activas se va por debajo del suyo.

### Subida de costo de un insumo

Cuando un insumo te empezó a costar más caro que antes. Por ejemplo: *"El queso mozzarella subió 15% en los últimos 7 días"*. Útil para sentarte a negociar con el proveedor o ajustar el precio del plato antes de perder margen en silencio.

El sistema usa el costo promedio ponderado (WAC) de tus compras reales — no precios de lista.

### Margen bajo (plato que dejó de ser rentable)

Cuando un plato cae por debajo del margen mínimo. Por ejemplo: *"La Bandeja Paisa tiene margen de 22% (umbral: 30%)"*. Suele pasar después de una subida de costo del insumo.

- **Crítica:** el margen quedó muy por debajo del umbral — el plato puede estar dejándote pérdida en vez de utilidad.
- **Advertencia:** está por debajo del mínimo sano pero todavía deja algo.

La alerta te lleva directo al plato en [menús](/manual/menus) para que decidas: subes el precio, cambias un ingrediente, renegocias con el proveedor o lo sacas de la carta.

### Plato sin ventas

Cuando un plato del menú activo no se ha vendido en varios días (típicamente 14). Te ayuda a tomar decisiones: lo quitas de la carta para no inflarla, le bajas el precio, lo promocionas en redes, o lo dejas en borrador por si quieres reactivarlo más adelante.

## Alertas administrativas

### Pagos vencidos con flexyflow

Si una factura de tu suscripción al panel quedó vencida, te aparece un aviso en la parte superior de la app. Es informativo, no bloquea — tu operación sigue funcionando con normalidad mientras se resuelve el pago.

- **Naranja:** mora reciente, una factura vencida.
- **Rojo:** mora prolongada, dos o más facturas vencidas. El equipo comercial de flexyflow está al tanto y se pone en contacto.

Si la mora se prolonga varios meses, la cuenta puede pasar a modo solo-lectura mientras se regulariza. Detalles en [facturación](/manual/facturacion).

### Fallos de emisión electrónica DIAN

Si una factura electrónica o un documento equivalente POS quedó rechazado por la DIAN, o si el proveedor tecnológico no respondió, aparece un aviso para que el cajero o el administrador entre y reintente o corrija los datos.

También avisa cuando una **resolución DIAN** está próxima a vencerse o se está acabando el rango de numeración autorizado.

## Cómo se ven las alertas

En el **panel de inicio** aparece un bloque "Alertas" con todas las activas, ordenadas por severidad (críticas primero). Cada alerta te muestra:

- **Icono y color** según severidad (rojo crítica, ámbar advertencia, azul informativa).
- **Descripción legible** ("La Bandeja Paisa tiene margen de 22%", "El queso mozzarella subió 15% en 7 días").
- **Enlace directo** al sitio donde actuar.
- **Botón "Descartar"** — la quitas porque ya la viste y no quieres actuar.
- **Botón "Marcar revisado"** — la quitas con una nota. Queda en auditoría.

## Cómo se generan

Un proceso automático corre cada noche a las 5 AM y evalúa las cuatro reglas operativas (stock bajo, subida de costo, margen bajo, plato sin ventas) sobre los datos del día. Si hay alertas nuevas, las suma al panel. Si una alerta del día anterior sigue activa, se actualiza (no se duplica).

Las alertas de mora con flexyflow y de emisión DIAN no esperan al cron: se prenden en tiempo casi-real cuando ocurre el evento.

<div class="callout callout-info">
<p>
<strong>Pendiente para más adelante:</strong> avisos por WhatsApp o correo cuando aparezca una
alerta crítica, reglas personalizadas y la posibilidad de automatizar acciones (subir precios,
retirar platos automáticamente). Por ahora la decisión sigue siendo humana — es un riesgo
demasiado alto para delegar al sistema.
</p>
</div>
