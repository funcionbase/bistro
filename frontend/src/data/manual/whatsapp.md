---
title: "WhatsApp del negocio"
description: "Conecta el número de WhatsApp de tu negocio a flexyflow. Recibe pedidos por chat, responde desde la app y configura el mensaje de bienvenida del bot."
metaTitle: "WhatsApp del negocio — Manual bistro.flexyflow.co"
metaDescription: "Conecta el WhatsApp Business de tu negocio a flexyflow para recibir pedidos y responder desde la misma app."
section: "clientes y mercadeo"
readingTime: "7 min"
lastUpdated: "8 de julio de 2026"
---

![WhatsApp del negocio conectado a bistro](/images/manual/whatsapp.svg "WhatsApp conectado: plantillas y mensajes automáticos.")

## Para qué sirve

Conectar tu WhatsApp Business a flexyflow te deja:

- Recibir los mensajes de tus clientes en el panel de [chat](/manual/chat), sin tener que mirar el celular del negocio.
- Responder desde la app, dejando registro de quién atendió cada conversación.
- Mandar avisos automáticos al cliente cuando le asignan domiciliario o cuando se le entrega su pedido.
- Que el bot atienda solo lo repetitivo (horarios, carta, recibir un pedido) y le pase al equipo humano solo cuando haga falta.

## Dos formas de conectar

### Opción A: traer tu propio número

Si ya tienes un número de WhatsApp Business, lo conectas con el flujo oficial de Meta ( *Embedded Signup*) — el mismo que usan grandes plataformas. Todo pasa adentro de una ventanita de Facebook, sin salir del panel.

1. En **WhatsApp del negocio** le das a *"Conectar con Facebook"*.
2. **Te pide un código de seguridad** que llega al correo del propietario. Lo digitas (válido 10 minutos, máximo 3 intentos).
3. Te abre el flujo oficial de Facebook (Meta). Inicias sesión con tu cuenta de Facebook donde está tu Business Manager.
4. Eliges la cuenta de WhatsApp Business que vas a conectar. Autorizas a flexyflow.
5. Vuelves a la app y ya aparece *"🟢 Conectado"* con tu número.

### Opción B: que flexyflow te dé un número

Si no tienes WhatsApp Business todavía, puedes solicitar que flexyflow te asigne uno. Llenas un formulario corto con país, descripción del negocio y correo de contacto. Nuestro equipo te gestiona la asignación manualmente y te avisa cuando esté listo.

## Mensajes entrantes

Cada mensaje que llega a tu número se registra automáticamente en el panel de [chats](/manual/chat) como una conversación con el cliente. Si es la primera vez que ese teléfono te escribe, te crea el chat solito. Si es un cliente conocido, el mensaje se suma a la conversación existente.

## Cómo arma un pedido el bot

Cuando el bot esté operativo, el flujo de un pedido por WhatsApp se parece a esto:

1. Cliente escribe a tu número. El bot consulta los [horarios](/manual/horarios) y responde solo si estás abierto.
2. Le muestra la carta (la misma del menú público de tu negocio).
3. Conversa con el cliente, arma un carrito y le manda un enlace seguro tipo *tutienda.flexyflow.co/carrito/abc123*. Ese enlace vive un tiempo limitado (unos 70 minutos por defecto).
4. El cliente abre el enlace en su navegador, revisa el carrito, paga y confirma. El pedido aparece en tu [tablero](/manual/pedidos) en tiempo real.
5. Si el cliente quiere hablar con un humano en cualquier momento, escribe algo como *"hablar con alguien"* y el bot le pasa la conversación al equipo (handoff).

<div class="callout callout-info">
<p>
<strong>Ventana de 24 horas:</strong> WhatsApp permite responder libremente a un cliente solo
dentro de las 24 horas siguientes a su último mensaje. Pasada esa ventana, para volver a iniciar
conversación se debe usar una <strong>plantilla pre-aprobada por Meta</strong> (por ejemplo:
aviso de pedido listo, recordatorio de reserva). Las plantillas las gestiona el equipo de
soporte de flexyflow contigo.
</p>
</div>

## Preferencias del WhatsApp

### Privacidad: doble chulito azul

Por defecto, los mensajes que tú lees no marcan doble chulito azul para el cliente. Si quieres que sí, activas el interruptor. La marca solo se manda cuando un operador efectivamente ve la conversación — no automáticamente al recibir.

### Bot: mensajes editables

Tienes dos textos personalizables que el bot usará cuando esté operativo:

- **Mensaje de bienvenida:** lo que el cliente recibe la primera vez que escribe a tu WhatsApp. Por ejemplo: *"¡Hola! Bienvenido a [nombre del negocio]. ¿En qué te ayudamos hoy?"*.
- **Mensaje de fuera de horario:** cuando un cliente te escribe en hora cerrada. Por ejemplo: *"Gracias por escribir a [nombre del negocio]. Estamos cerrados ahora, abrimos mañana a las 11 AM"*.

Te aparece una vista previa estilo WhatsApp mientras editas. La sustitución `{company_name}` se rellena solita con el nombre comercial de tu negocio.

## Cambiar o desconectar el número

Estas acciones son **solo del Propietario** y exigen código de seguridad por correo (OTP). No las puede hacer un Administrador, ni siquiera con permisos especiales.

- **Cambiar número:** útil si cambias de línea. Te desconecta el actual y te deja listo para conectar uno nuevo.
- **Desconectar:** termina la conexión con Meta. El historial de chats se preserva para auditoría.

<div class="callout callout-warn">
<p>
<strong>"No fui yo":</strong> el correo del OTP incluye un botón <em>"No fui yo"</em>. Si
recibes un código que no pediste, haces clic en ese botón y el código queda inválido al
instante. Es una protección contra accesos no autorizados al cambio de número.
</p>
</div>

## Quién puede hacer qué

<table>
<thead>
<tr>
<th>Acción</th>
<th>Quién puede</th>
</tr>
</thead>
<tbody>
<tr>
<td>Ver estado de la conexión</td>
<td>Propietario, Administrador (configurable a Empleado).</td>
</tr>
<tr>
<td>Conectar (con tu propio número o con uno de flexyflow)</td>
<td>Propietario, Administrador. Exige código por correo.</td>
</tr>
<tr>
<td>Editar mensajes del bot</td>
<td>Propietario, Administrador.</td>
</tr>
<tr>
<td>Cambiar número</td>
<td>
<strong>Solo Propietario</strong>. Exige código por correo.
</td>
</tr>
<tr>
<td>Desconectar</td>
<td>
<strong>Solo Propietario</strong>. Exige código por correo.
</td>
</tr>
</tbody>
</table>

<div class="callout callout-info">
<p>
<strong>Costos del lado de Meta:</strong> WhatsApp Cloud API tiene su propia tarifa por
conversación (la maneja Meta, no flexyflow). Hoy las conversaciones iniciadas por el cliente
las primeras 24h suelen ser gratis. Si tu volumen es alto, conviene revisar la política de
precios vigente de Meta.
</p>
</div>
