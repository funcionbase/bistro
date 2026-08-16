---
title: "Configuración"
description: "Información del negocio, preferencias operativas, perfil fiscal DIAN, conexión a WhatsApp, impresoras térmicas, atajos y la cuenta personal de cada miembro del equipo."
metaTitle: "Configuración — Manual bistro.example.com"
metaDescription: "Datos del negocio, banca, QR de pagos, impuestos, perfil fiscal DIAN, conexión a WhatsApp, impresoras térmicas y configuración personal. Todo desde un solo lugar."
section: "administración"
readingTime: "8 min"
lastUpdated: "8 de julio de 2026"
---

![Configuración de la empresa en bistro](/images/manual/configuracion.svg "Configuración: datos del negocio, impuestos y preferencias.")

## Mapa de configuración

<table>
<thead>
<tr>
<th>Pantalla</th>
<th>Para qué sirve</th>
</tr>
</thead>
<tbody>
<tr>
<td>Información del negocio</td>
<td>Datos generales, banca, QR de pagos, logo, impuestos.</td>
</tr>
<tr>
<td>Preferencias</td>
<td>Regional, pedidos, notificaciones al cliente, branding del menú público.</td>
</tr>
<tr>
<td>Facturación electrónica DIAN</td>
<td>Consulta de facturas emitidas, resoluciones y contacto por defecto.</td>
</tr>
<tr>
<td>WhatsApp</td>
<td>Conectar y administrar el número de WhatsApp del negocio.</td>
</tr>
<tr>
<td>Impresoras</td>
<td>Térmicas para cocina, barra y recibos al cliente.</td>
</tr>
<tr>
<td>Sedes</td>
<td>
Crear, editar y archivar sucursales (ver
<a href="/manual/sedes">sedes</a>).
</td>
</tr>
<tr>
<td>Cajas registradoras</td>
<td>Crear, nombrar y activar las cajas de una sede para soporte multi-caja.</td>
</tr>
<tr>
<td>Mi cuenta</td>
<td>Perfil personal, apariencia, notificaciones push.</td>
</tr>
</tbody>
</table>

## Información del negocio

- **Nombre comercial** (el que conoce el cliente) y **razón social** (el del RUT).
- **NIT** — quedó fijo desde el onboarding. **No se puede cambiar** después porque viaja en toda la facturación electrónica que ya emitiste.
- **Cuenta bancaria:** banco, número y tipo (corriente o ahorros).
- **Llave Bre-B** (opcional) — para transferencias instantáneas.
- **Logo** — JPG, PNG, WEBP o SVG, máximo 5 MB.
- **QR de pagos:** JPG o PNG, máximo 5 MB.

<div class="callout callout-info">
<p>
<strong>Cambias el nombre comercial y se ve al instante:</strong> la barra lateral con el
nombre del negocio se actualiza sin que tengas que cerrar sesión ni refrescar.
</p>
</div>

### Configuración de impuestos

- **Régimen tributario:** Simple, INC 8%, IVA 19%, IVA 5%, Exento o Personalizado.
- **Tasa por defecto:** el % de impuesto que se aplica a un plato cuando no le pones uno específico.
- **Precios con impuesto incluido:** si los precios del menú ya traen el impuesto adentro o si se le suma al cobrar.

## Preferencias

### Pedidos

- **Área de cobertura** de domicilios en kilómetros (de 1 a 100).
- **Monto mínimo de pedido** en pesos.
- **Métodos de pago aceptados:** efectivo, transferencia, tarjeta, Nequi, DaviPlata.
- **Auto-confirmar pedidos:** si está activo, los pedidos entran directamente como "en cocina" en vez de quedarse en "pendiente".

### Branding del menú público

- **Color principal:** eliges el color de tu marca y se usa para pintar los botones y encabezados del menú público.

## Facturación electrónica DIAN

La pantalla de facturación electrónica tiene tres pestañas:

- **Facturas:** consulta de todos los documentos que tu negocio ha emitido — por resolución, por sede, con búsqueda y descarga. El paso a paso está en [facturación](/manual/facturacion).
- **Resoluciones:** los rangos de numeración que la DIAN le autorizó a tu negocio. Puedes tener varias activas a la vez y ver cuánto le queda a cada una.
- **Contacto por defecto:** el "consumidor final" genérico que se usa en los tickets de caja cuando el cliente no da sus datos.

El **perfil fiscal** (razón social, dirección, régimen tributario, representante legal, municipio) se completa en *configuración → información del negocio*. La conexión técnica con la DIAN la opera directamente el equipo de bistro — no tienes que contratar ni configurar nada por tu cuenta. La facturación electrónica hace parte del *Plan Plus* y se activa con tu asesor.

<div class="callout callout-warn">
<p>
<strong>Sin perfil fiscal no se factura:</strong> si intentas emitir un documento electrónico
sin tener el perfil DIAN y la resolución vigente configurados, bistro te bloquea.
</p>
</div>

## WhatsApp del negocio

En **configuración → WhatsApp** conectas el número de WhatsApp del negocio. Ver la guía completa en [WhatsApp del negocio](/manual/whatsapp).

<div class="callout callout-info">
<p>
<strong>Solo el Propietario:</strong> cambiar el número conectado o desconectar la cuenta de
WhatsApp <strong>solo lo puede hacer el dueño</strong>. Ni el administrador con todos los
permisos puede tocar eso.
</p>
</div>

## Impresoras térmicas

En **configuración → impresoras** configuras:

- **Nombre y tipo:** cocina, barra, caja o recibos al cliente.
- **Conexión:** USB, Bluetooth o por red local.
- **Ancho de papel:** 58 o 80 mm.
- **Categorías que atiende** (para impresoras de cocina/barra).

Hay un botón **"Probar impresora"** que manda un ticket de prueba para verificar que la conexión funciona antes de abrir el local.

## Cajas registradoras

Si tu operación tiene más de un punto de cobro (mostrador principal, barra, caja express), en **configuración → sedes → cajas registradoras** creas una caja por cada terminal. Le das un nombre, la activas y listo. Cuando el cajero entra al módulo de caja, el sistema le pregunta cuál caja va a operar — esa elección queda guardada en el dispositivo.

Cada caja tiene su propio turno independiente: se puede abrir y cerrar por separado sin afectar las otras cajas de la sede. Los reportes de cierre del día muestran el detalle por caja.

Si solo tienes un punto de cobro, no necesitas configurar nada — la sede ya trae una caja por defecto.

## Configuración personal

Cada miembro administra su propia cuenta desde **mi cuenta**. Esta configuración es individual.

### Apariencia (tema)

- Elige entre **claro**, **oscuro** o *según el sistema*.
- La preferencia se guarda en el navegador.

### Notificaciones push

En **mi cuenta → notificaciones** decides en qué dispositivo quieres recibir avisos push (pedidos nuevos, devoluciones, mensajes urgentes, etc.).

<div class="callout callout-info">
<p>
<strong>iPhone:</strong> en iOS las notificaciones push solo llegan si
<strong>instalas la app al "home screen"</strong> (botón Compartir → "Agregar al Inicio"). Es
una limitación de Apple, no de bistro.
</p>
</div>

## Atajos de teclado

<table>
<thead>
<tr>
<th>Atajo</th>
<th>Va a</th>
</tr>
</thead>
<tbody>
<tr>
<td>
<kbd>Alt + H</kbd>
</td>
<td>Panel de inicio</td>
</tr>
<tr>
<td>
<kbd>Alt + O</kbd>
</td>
<td>Tablero de pedidos</td>
</tr>
<tr>
<td>
<kbd>Alt + J</kbd>
</td>
<td>Caja</td>
</tr>
<tr>
<td>
<kbd>Alt + E</kbd>
</td>
<td>Domicilios</td>
</tr>
<tr>
<td>
<kbd>Alt + M</kbd>
</td>
<td>Menú</td>
</tr>
<tr>
<td>
<kbd>Alt + S</kbd>
</td>
<td>Chats</td>
</tr>
<tr>
<td>
<kbd>Alt + P</kbd>
</td>
<td>Cupones</td>
</tr>
<tr>
<td>
<kbd>Alt + B</kbd>
</td>
<td>Horarios</td>
</tr>
<tr>
<td>
<kbd>Alt + T</kbd>
</td>
<td>Métricas</td>
</tr>
<tr>
<td>
<kbd>Alt + R</kbd>
</td>
<td>Informes</td>
</tr>
<tr>
<td>
<kbd>Alt + U</kbd>
</td>
<td>Usuarios</td>
</tr>
<tr>
<td>
<kbd>Alt + L</kbd>
</td>
<td>Roles</td>
</tr>
<tr>
<td>
<kbd>Ctrl + B</kbd>
</td>
<td>Mostrar / ocultar menú lateral</td>
</tr>
<tr>
<td>
<kbd>?</kbd>
</td>
<td>Ventana de ayuda con todos los atajos</td>
</tr>
</tbody>
</table>
