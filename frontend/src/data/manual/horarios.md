---
title: "Horarios"
description: "Define cuándo está abierto tu negocio: horario semanal, excepciones por fecha (feriados o eventos) y el estado en vivo de apertura/cierre."
metaTitle: "Horarios — Manual bistro.example.com"
metaDescription: "Cómo configurar el horario de tu negocio en bistro.example.com: horario semanal, excepciones por fecha, estado en vivo y efecto en el bot de WhatsApp."
section: "el día a día"
readingTime: "5 min"
lastUpdated: "8 de julio de 2026"
---

![Horarios del negocio en bistro](/images/manual/horarios.svg "Horarios de apertura por día de la semana.")

## Horario semanal

En **horarios** configuras para cada día de la semana si el negocio está abierto, y de qué hora a qué hora. Puedes tener varios rangos por día (por ejemplo, almuerzo de 12 PM a 3 PM y cena de 6 PM a 10 PM).

Los días que dejas sin marcar se tratan como cerrado. El cliente en el menú público ve un mensaje de "estamos cerrados" y no puede confirmar pedidos.

<div class="callout callout-warn">
<p>
<strong>Sin horario configurado = siempre abierto.</strong> Si nunca configuras horarios, la app
asume que estás disponible las 24 horas. Para negocios con horario fijo, configúralo antes de
arrancar operaciones para evitar pedidos a deshora.
</p>
</div>

## Excepciones por fecha

Para fechas específicas donde el horario cambia (feriados, eventos especiales, temporadas altas), creas una excepción. La excepción reemplaza el horario semanal ese día.

- **Cerrado todo el día:** el negocio no abre aunque sea un día de semana normal. Útil para feriados (20 de julio, 7 de agosto) o vacaciones.
- **Horario especial:** el negocio abre en horas distintas ese día. Útil para navidad (abres solo al mediodía), un evento especial (abres más temprano) o un día de mantenimiento (cierras más temprano).

## Estado en vivo: abierto / cerrado

El sistema calcula en tiempo real si el negocio está abierto o cerrado con base en el horario y las excepciones del día. Este estado se refleja en:

- **Menú público:** si está cerrado, aparece un aviso y los clientes no pueden agregar al carrito.
- **Bot de WhatsApp:** si el cliente escribe fuera de horario, el bot responde con el mensaje de "fuera de horario" que configuraste. No arma pedidos si estás cerrado.
- **Panel de inicio:** el dashboard muestra el estado actual del negocio.

<div class="callout callout-info">
<p>
<strong>Zona horaria:</strong> bistro usa la hora oficial de Colombia (hora de Bogotá)
para todos los cálculos de horario.
</p>
</div>

## Quién puede editar horarios

Los horarios son configuración del negocio. Pueden editarlos los usuarios con permiso de actualización en el módulo de horarios — típicamente el propietario y el administrador. El empleado base no tiene este permiso por defecto.
