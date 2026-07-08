---
title: "Usuarios, roles y permisos"
description: "Cómo decides qué puede tocar cada miembro de tu operación. Tres roles base, ocho plantillas listas, un catálogo de unos 82 permisos y excepciones individuales cuando hace falta."
metaTitle: "Usuarios, roles y permisos — Manual bistro.flexyflow.co"
metaDescription: "Tres roles base, ocho plantillas operativas listas para usar y un editor fino para personalizar lo que cada miembro del equipo puede hacer. Invitaciones por correo con vigencia de 7 días."
section: "administración"
readingTime: "9 min"
lastUpdated: "8 de julio de 2026"
---

![Usuarios y roles del equipo en bistro](/images/manual/usuarios.svg "Usuarios del equipo con su rol y permisos.")

## Cómo funciona

Cada miembro de tu equipo tiene un **rol** dentro del negocio. El rol decide a qué módulos puede entrar (menú, pedidos, caja, métricas, inventario, chats, etc.) y qué puede hacer en cada uno (*ver*, *crear*, *actualizar*, *eliminar*).

Cuando creas tu negocio, bistro flexy te deja armados los tres roles base. A partir de ahí decides si te quedas con esos o si activas las plantillas operativas pre-armadas (mesero, cocinero, cajero, gerente…) para no tener que configurar permisos uno por uno.

### Las cuatro acciones por módulo

- **Ver:** puede abrir la sección y consultar lo que hay.
- **Crear:** agregar cosas nuevas (un menú, un plato, un cupón, un usuario, etc.).
- **Actualizar:** modificar lo que ya existe.
- **Eliminar:** borrar (en muchos módulos es archivar, no borrar duro — el historial DIAN no se puede tocar).

### Cuántos permisos hay

El catálogo tiene alrededor de **82 permisos** agrupados por dominio. No te asustes: nunca los vas a tocar uno por uno porque las plantillas ya traen combinaciones razonables.

<table>
<thead>
<tr>
<th>Dominio</th>
<th>Qué cubre</th>
</tr>
</thead>
<tbody>
<tr>
<td>Operaciones</td>
<td>Pedidos, menú, horarios, domicilios, KDS y estaciones.</td>
</tr>
<tr>
<td>Caja</td>
<td>Apertura y cierre de caja, pagos divididos, devoluciones.</td>
</tr>
<tr>
<td>Inventario</td>
<td>Insumos, recetas, compras, proveedores, bodegas, transferencias entre sedes.</td>
</tr>
<tr>
<td>Marketing</td>
<td>Cupones, fidelización, fichas de clientes (CRM).</td>
</tr>
<tr>
<td>Comunicación</td>
<td>Chats por WhatsApp, reasignación de chats entre sedes.</td>
</tr>
<tr>
<td>Analítica</td>
<td>Reportes, métricas, vista consolidada cross-sede.</td>
</tr>
<tr>
<td>Equipo (workforce)</td>
<td>Empleados, turnos, ver salarios, reportes de nómina.</td>
</tr>
<tr>
<td>Administración</td>
<td>Usuarios, roles, configuración de empresa, facturación, notificaciones.</td>
</tr>
<tr>
<td>Multi-sede</td>
<td>Crear y administrar sedes, asignar usuarios, copiar menú entre sedes.</td>
</tr>
<tr>
<td>DIAN</td>
<td>Perfil fiscal, resoluciones, proveedor tecnológico, documentos electrónicos.</td>
</tr>
</tbody>
</table>

## Los tres roles base del sistema

- **Propietario** — acceso total. Es el rol del dueño (el que abrió la cuenta) y siempre debe existir al menos uno activo. Es el único que puede *cambiar el número de WhatsApp conectado* o *desconectar la cuenta de WhatsApp*.
- **Administrador** — acceso casi total, pensado para gerentes o socios. Por defecto no recibe los permisos sensibles de sede ni los reservados al Propietario.
- **Empleado** — solo lectura por defecto. Sirve de punto de partida para construir accesos limitados u operativos sin tocar configuración.

Estos tres **no se pueden editar ni eliminar** — son inmutables.

<div class="callout callout-info">
<p>
<strong>Última línea de seguridad:</strong> nunca te puedes quedar sin Propietario activo. El
sistema bloquea cualquier acción (eliminar miembro, desactivar, cambiar rol) que dejaría a la
empresa sin dueño.
</p>
</div>

## Las ocho plantillas operativas

Pre-armadas para los cargos típicos de tu operación. A diferencia de los tres base, **estas sí las puedes renombrar, ajustar permisos o eliminar**.

<table>
<thead>
<tr>
<th>Plantilla</th>
<th>Para qué se diseñó</th>
</tr>
</thead>
<tbody>
<tr>
<td>
<strong>Mesero</strong>
</td>
<td>
Aprobar y rechazar tandas, editar notas del pedido, resolver solicitudes de
cancelación, ver y responder chats.
</td>
</tr>
<tr>
<td>
<strong>Cocinero</strong>
</td>
<td>
Tablero de cocina (KDS) exclusivo: ver órdenes y mover el estado de los ítems.
Nada de caja ni configuración.
</td>
</tr>
<tr>
<td>
<strong>Cajero</strong>
</td>
<td>Caja con pago dividido, devoluciones (refund), reportes propios del turno.</td>
</tr>
<tr>
<td>
<strong>Gerente</strong>
</td>
<td>
Operación de sede: cierra órdenes, ajusta menú, gestiona turnos y atiende inventario.
Sin contabilidad ni configuración fiscal.
</td>
</tr>
<tr>
<td>
<strong>Contador</strong>
</td>
<td>
Lectura financiera consolidada de todas las sedes: facturación, compras, proveedores,
reportes de nómina. Sin operación.
</td>
</tr>
<tr>
<td>
<strong>Marketing</strong>
</td>
<td>Cupones (sin poder eliminarlos), fidelización, clientes y chats.</td>
</tr>
<tr>
<td>
<strong>Bodeguero</strong>
</td>
<td>
Inventario completo, gestión de bodegas, compras (sin poder pagarlas ni eliminarlas) y
proveedores.
</td>
</tr>
<tr>
<td>
<strong>Supervisor</strong>
</td>
<td>
Vista casi solo lectura de todos los módulos operativos, con permiso de actualizar
pedidos y domicilios.
</td>
</tr>
</tbody>
</table>

## Crear roles personalizados

1. Vas a **administración → usuarios y roles → roles** y le das a <kbd>nuevo rol</kbd>.
2. Le pones nombre, descripción y un **color**.
3. Marcas los módulos y las acciones que quieres habilitar. Puedes usar **"Clonar permisos de…"** para partir de un rol existente.
4. Hay **interruptores por columna** arriba de la matriz (Ver, Crear, Actualizar, Eliminar) para marcar todos los módulos en un solo click.
5. Guardas. Ya puedes asignarlo desde la tabla de miembros.

**No puedes eliminar** un rol que tenga miembros asignados. Primero los pasas a otro rol y luego lo borras.

## Invitar y administrar miembros

En **administración → usuarios y roles → usuarios** ves la tabla con todos los miembros del equipo.

### Invitaciones por correo (vigencia 7 días)

Escribes el correo y eliges el rol con el que arrancará la persona. La invitación va por email con un enlace personal y **dura 7 días** antes de vencerse.

- La persona entra al enlace, inicia sesión con su Google y queda enrolada al instante.
- Si ya tenía cuenta en flexyflow, simplemente se le agrega tu operación como un negocio más.

## Editor fino de permisos (excepciones individuales)

Cada miembro puede tener **permisos extra** que se suman a los de su rol, sin necesidad de cambiarle el rol completo. Útil para:

- *"Camilo, el cocinero, está cubriendo al gerente esta semana. Dale acceso temporal a métricas sin moverlo del rol Cocinero."*

<div class="callout callout-warn">
<p>
<strong>Regla de oro:</strong> nadie puede otorgar permisos que él mismo no tiene. Esto evita
que alguien escale poco a poco hasta tener más acceso del que su jefe le dio.
</p>
</div>

<div class="callout callout-info">
<p>
<strong>Auto-protección:</strong> nadie puede modificar su propio rol, sus propios permisos ni
sacarse a sí mismo del equipo.
</p>
</div>

## Un ejemplo de equipo bien armado

Una **pizzería con 8 personas**:

<table>
<thead>
<tr>
<th>Miembro</th>
<th>Rol</th>
<th>Excepciones</th>
</tr>
</thead>
<tbody>
<tr>
<td>Don Hernán (dueño)</td>
<td>Propietario</td>
<td>—</td>
</tr>
<tr>
<td>María (gerente)</td>
<td>Gerente (plantilla)</td>
<td>—</td>
</tr>
<tr>
<td>Sofía (cajera mañana)</td>
<td>Cajero (plantilla)</td>
<td>—</td>
</tr>
<tr>
<td>Andrea (cajera tarde)</td>
<td>Cajero (plantilla)</td>
<td>—</td>
</tr>
<tr>
<td>Camilo (jefe de cocina)</td>
<td>Cocinero (plantilla)</td>
<td>+ ver métricas</td>
</tr>
<tr>
<td>Mateo (cocinero)</td>
<td>Cocinero (plantilla)</td>
<td>—</td>
</tr>
<tr>
<td>Carlos (bodega/compras)</td>
<td>Bodeguero (plantilla)</td>
<td>—</td>
</tr>
<tr>
<td>Laura (contadora externa)</td>
<td>Contador (plantilla)</td>
<td>—</td>
</tr>
</tbody>
</table>
