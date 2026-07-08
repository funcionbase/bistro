---
title: "Planificador de turnos"
description: "Programa los turnos de tu equipo en una vista semanal o mensual: asigna empleados, horarios y cargos, y lleva el control de la cobertura de tu operación."
metaTitle: "Planificador de turnos — Manual bistro.flexyflow.co"
metaDescription: "Cómo usar el planificador de turnos de bistro.flexyflow.co: vista semanal, mensual, asignación de empleados por cargo y control de cobertura del local."
section: "administración"
readingTime: "5 min"
lastUpdated: "8 de julio de 2026"
---

![Planificador de turnos del equipo](/images/manual/planner.svg "El planner: turnos del equipo por día y sede.")

## ¿Para qué sirve?

El planificador te ayuda a organizar quién trabaja en qué horario durante la semana o el mes. Es especialmente útil cuando manejas turnos rotativos, tienes varios cajeros y meseros, o necesitas saber de un vistazo si hay cobertura suficiente para un día de alta demanda.

El planificador es por **sede**. Si tienes varias sedes, cada una tiene su propio cronograma.

## Vistas disponibles

- **Vista semanal** (predeterminada): una grilla lunes-domingo con los turnos del equipo. En cada celda ves el empleado, el cargo y el rango de horas del turno.
- **Vista mensual**: un calendario con puntos de turnos por día. Útil para planear el mes completo y detectar semanas con poca gente.

Cambias entre vistas con las pestañas en la parte superior. La vista mensual se abre en *planificador → calendario*.

## Crear un turno

1. Ve a **planificador** y navega a la semana o el día donde quieres asignar.
2. Dale a <kbd>+ Nuevo turno</kbd>.
3. Elige:
    - **Empleado** — solo aparecen miembros activos de la sede.
    - **Fecha, hora de inicio y hora de fin.**
    - **Cargo** — el rol que va a desempeñar ese día (mesero, cajero, cocinero, etc.). Es informativo, no afecta los permisos del panel.
4. Guarda. El turno aparece en la grilla al instante.

## Editar o cancelar un turno

Haz clic sobre el turno en la grilla y elige *editar* o *cancelar*. Al cancelar se te pide un motivo (cambio de horario, incapacidad, etc.) que queda en el registro.

<div class="callout callout-info">
<p>
<strong>Turno cancelado vs. eliminado:</strong> los turnos no se borran duro — quedan
marcados como "cancelado" en el historial para que puedas ver la rotación real de tu equipo
a final de mes.
</p>
</div>

## Indicadores en la vista semanal

Al tope de la vista semanal ves dos contadores:

- **Turnos esta semana:** cuántos turnos hay programados en los 7 días.
- **Empleados en la semana:** cuántas personas distintas tienen turno esta semana.

## Quién puede usar el planificador

El acceso al planificador requiere el permiso de **ver y gestionar turnos**. Por defecto solo lo tienen el Propietario y el Administrador. Si quieres que el Gerente también pueda programar turnos, asígnaselo desde *usuarios → roles → editar rol Gerente → permisos de planificador*.

<div class="callout callout-warn">
<p>
<strong>El planificador no bloquea el acceso al panel:</strong> un empleado puede
ingresar a bistro flexy fuera de su turno programado — el planificador es de organización,
no de control de acceso horario.
</p>
</div>
