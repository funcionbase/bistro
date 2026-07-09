---
title: "Métricas"
description: "Tu cabina de mando: KPIs del día, mapas de calor, ranking de platos, costo de insumos en tiempo real, ingeniería de menú y vista consolidada cuando tienes varias sedes."
metaTitle: "Métricas — Manual bistro.flexyflow.co"
metaDescription: "Ingresos en vivo, ticket promedio, mapas de calor por hora y día, ranking de platos, costo de insumos, ingeniería de menú, abandono de carrito y vista consolidada multi-sede."
section: "números y reportes"
readingTime: "8 min"
lastUpdated: "8 de julio de 2026"
---

![Métricas y análisis de ventas en bistro](/images/manual/metricas.svg "Métricas: ventas, productos top y comparativos.")

## Panel de inicio

El **panel de control** es la primera pantalla cuando entras a la app. Te muestra cómo va tu operación en el período que elijas (hoy, últimos 7 días, mes en curso o un rango personalizado).

### KPIs del día

- **Ingresos del día** con el **ticket promedio** (cuánto gasta en promedio cada cliente). El ticket promedio se calcula solo sobre pedidos que *completaron* — no entran cancelados ni abandonados.
- **Conteo de pedidos** del día y del período seleccionado.
- **Calificación promedio** de tus clientes en el período.
- **Pedidos activos** ahora mismo, separados por estado (pendiente, en cocina, listo, en camino). Se refresca solo cada 30 segundos.
- **Comparación con el período anterior:** un distintivo verde o rojo te dice si subió o bajó. Si vienes de un negocio sin histórico, el distintivo se oculta.

### Otros paneles del inicio

- **Mapa de calor por hora:** en qué horas del día se concentran tus pedidos. Sirve para programar turnos.
- **Abandono de carrito:** cuántos clientes empezaron un pedido y no lo terminaron, y cuánto dinero estimado dejaste sobre la mesa.
- **Resumen de domicilios:** cómo va la operación logística. Solo aparece si tienes permiso para ver entregas.
- **Alertas:** ver [alertas](/manual/alertas).

## Página de métricas detallada

En **métricas** tienes la vista detallada con filtros de período:

- **Resumen del período:** ingresos, ticket promedio, conteos por estado.
- **Mapa de calor por hora del día** (24 buckets).
- **Mapa de calor semanal** — los 7 días por las 24 horas. Te dice "los viernes a las 8 PM es nuestra hora pico".
- **Ranking de platos:**
    - Por *ingresos* (cuáles te dejan más plata).
    - Por *cantidad* (cuáles se venden más unidades).
    - Por *margen* — cruza el precio de venta contra el costo del insumo.
- **Abandono de carrito** con tasa de conversión y dinero estimado perdido.
- **Escaneos del menú QR:** cuántas veces escanearon el QR de tu menú en el período, cuántos visitantes distintos fueron y cómo se reparte por día. Sirve para saber si el QR de las mesas y los empaques realmente se usa.
- **Costo de insumos (food cost) en tiempo real** — qué porcentaje del precio de venta se va en insumos.
- **Histórico de costo por plato** — para un ítem específico ves la curva de su food cost en el tiempo.
- **Ingeniería de menú** — clasifica tus platos en cuatro cuadrantes:
    - **Estrellas** — alta popularidad, alto margen. Lo que más cuidas.
    - **Vacas** — alta popularidad, bajo margen. Se venden bien pero te dejan poco.
    - **Acertijos** — baja popularidad, alto margen. Hay que promocionarlos.
    - **Perros** — baja popularidad, bajo margen. Candidatos a sacarlos de la carta.

### Modo en vivo y caché

En la página de métricas tienes un interruptor *"En vivo"*. Cuando lo activas, el panel se refresca solo cada minuto. Se apaga solo a los 5 minutos para no consumir de más.

### Vista consolidada si tienes varias sedes

Si tu operación tiene más de una sede, puedes activar la **vista consolidada** para ver los números sumados de todas las sedes al tiempo, o cambiar el selector para mirar solo una. La vista consolidada requiere un permiso específico — típicamente la tiene el dueño o el contador.

## Ventas del día

En **ventas del día** tienes el listado de pedidos del período con filtros por estado, paginación y exportación.

### Cierre de caja del día

Un componente especial te muestra el **cierre de caja por fecha**: para una fecha específica o un rango, ves un desglose por método de pago (efectivo, datáfono, transferencia) con cobros, devoluciones, neto y propinas.

### Informe de cierre por turno

Debajo del cierre del día está el **historial de turnos de caja**, agrupado por fecha. Cada turno se despliega para ver su arqueo completo: con cuánto fondo abrió, cuánto se vendió por cada método de pago, las entradas y salidas de efectivo, cuánto se esperaba en el cajón y cuánto contó el cajero al cerrar — con la diferencia (sobrante o faltante) marcada.

Es la herramienta para responder "¿por qué no cuadró la caja del martes?" sin llamar al cajero: todo el movimiento del turno está ahí, turno por turno, día por día.

## Descargar reportes

- **Reporte de métricas** en PDF — para imprimir o compartir con un socio.
- **Reporte de pedidos** en PDF (resumen con desglose tributario) o en CSV (datos crudos para abrir en Excel).
- **Reporte de domiciliarios** en PDF.
- **Cierre de caja del día** en PDF.

Los reportes en PDF tienen un tope de 500 filas. Para el detalle completo, descarga el CSV de pedidos — ese no tiene tope.

## Quién puede ver qué

Todo el módulo de métricas y reportes requiere el permiso de **ver reportes**. Sin ese permiso, los paneles del panel de inicio se ocultan en silencio.

<div class="callout callout-success">
<p>
<strong>Hábito recomendado:</strong> revisa el mapa de calor semanal una vez al mes y la
ingeniería de menú cada trimestre. Cualquier cosa que cambies (precios, ingredientes, plato
nuevo), vuelve a revisar dos semanas después para ver el impacto.
</p>
</div>
