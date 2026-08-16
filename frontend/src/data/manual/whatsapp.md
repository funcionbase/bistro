---
title: "WhatsApp del negocio"
description: "Conecta el número de WhatsApp de tu negocio a bistro. Recibe pedidos por chat, responde desde la app y configura el mensaje de bienvenida del bot."
metaTitle: "WhatsApp del negocio — Manual bistro.example.com"
metaDescription: "Conecta el WhatsApp Business de tu negocio a bistro para recibir pedidos y responder desde la misma app."
section: "clientes y mercadeo"
readingTime: "8 min"
lastUpdated: "23 de julio de 2026"
---

![WhatsApp del negocio conectado a bistro](/images/manual/whatsapp.svg "WhatsApp conectado: plantillas y mensajes automáticos.")

## Para qué sirve

Conectar tu WhatsApp Business a bistro te deja:

- Recibir los mensajes de tus clientes en el panel de [chat](/manual/chat), sin tener que mirar el celular del negocio.
- Responder desde la app, dejando registro de quién atendió cada conversación.
- Mandar avisos automáticos al cliente cuando le asignan domiciliario o cuando se le entrega su pedido.
- Que el bot atienda solo lo repetitivo (horarios, carta, recibir un pedido) y le pase al equipo humano solo cuando haga falta.

## Conectar un número (escaneando un QR)

Conectar es como vincular WhatsApp Web: escaneás un código con el celular y listo. Toma menos de dos minutos y no necesitás cuenta de Facebook ni Business Manager.

1. En **WhatsApp del negocio** le das a *"Conectar WhatsApp"*.
2. Si tenés varias sedes, primero elegís el alcance: **un número para toda la empresa** o **un número por sede** (cada sede atiende el suyo). Con una sola sede este paso se salta.
3. Aceptás el aviso de riesgo (WhatsApp puede bloquear un número que se use de forma abusiva) y aparece el **código QR**.
4. En tu celular abrís WhatsApp → **⋮ → Dispositivos vinculados → Vincular un dispositivo** y apuntás la cámara al código. El QR se renueva solo cada minuto: si vence, se genera otro sin que hagas nada.
5. ¿Sin cámara a mano? Usás el **código de 8 dígitos**: lo pedís con tu número y lo escribís en el celular.
6. Al conectar te muestra el número detectado y te pregunta *"¿Es el número correcto?"* — así no queda vinculado el WhatsApp equivocado.

Cada sede sin número aparece como una tarjeta punteada con el botón **"Conectar esta sede"**, para que ninguna se quede sin WhatsApp sin que te des cuenta.

### Probar que quedó conectado

Apenas conectás, en la tarjeta del número tenés **"Enviar mensaje de prueba"**: se manda un mensaje a tu propio número y lo ves llegar en la bandeja. Es la confirmación, en un clic, de que todo el circuito funciona.

### Ver cómo va

La tarjeta de cada número conectado muestra los **mensajes de los últimos 7 días** y el **tiempo medio de respuesta** — el número que te dice si el módulo te está sirviendo.

## Mensajes entrantes

Cada mensaje que llega a tu número se registra automáticamente en el panel de [chats](/manual/chat) como una conversación con el cliente. Si es la primera vez que ese teléfono te escribe, te crea el chat solito. Si es un cliente conocido, el mensaje se suma a la conversación existente.

## Cómo arma un pedido el bot

Cuando el bot esté operativo, el flujo de un pedido por WhatsApp se parece a esto:

1. Cliente escribe a tu número. El bot consulta los [horarios](/manual/horarios) y responde solo si estás abierto.
2. Le muestra la carta (la misma del menú público de tu negocio).
3. Conversa con el cliente, arma un carrito y le manda un enlace seguro para confirmar. El enlace vence en aproximadamente una hora por seguridad.
4. El cliente abre el enlace en su navegador, revisa el carrito, paga y confirma. El pedido aparece en tu [tablero](/manual/pedidos) en tiempo real.
5. Si el cliente quiere hablar con un humano en cualquier momento, escribe algo como *"hablar con alguien"* y el bot le pasa la conversación al equipo.

<div class="callout callout-info">
<p>
<strong>Ventana de 24 horas:</strong> WhatsApp permite responder libremente a un cliente solo
dentro de las 24 horas siguientes a su último mensaje. Pasada esa ventana, para volver a iniciar
conversación se debe usar una <strong>plantilla pre-aprobada por Meta</strong> (por ejemplo:
aviso de pedido listo, recordatorio de reserva). Las plantillas las gestiona el equipo de
soporte de bistro contigo.
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

### Responder automáticamente fuera de horario

Con el interruptor **"Responder automáticamente fuera de horario"** activado, cuando un cliente te escribe con el local cerrado se le manda el *mensaje de fuera de horario* de arriba — una sola vez por día, para no acosarlo si escribe varias veces. Usa tus [horarios](/manual/horarios) por sede. Está apagado por defecto: si nunca lo activaste, nadie recibe respuestas automáticas.

## Respuestas rápidas

El 80 % de lo que responde un restaurante son cinco frases: *"¿A qué dirección?"*, *"Sale en 35 min"*, *"Ya va en camino"*. En vez de escribirlas cada vez, las guardás una sola vez y las insertás en el chat escribiendo **`/`**.

- Las creás en **Empresa → WhatsApp → Respuestas rápidas** (solo Propietario y Administrador). Cada una puede ser de toda la empresa o de una sede.
- En el chat, escribís `/` y aparece la lista; te movés con las flechas y la insertás con Enter.
- Admiten variables que se completan solas al insertar: `{{cliente}}` (nombre del cliente), `{{pedido}}` (código del último pedido) y `{{sede}}`. Así *"Hola {{cliente}}, tu pedido {{pedido}} va en camino"* sale listo, sin escribirlo a mano.

## Enviar la carta, un carrito o crear un pedido

Desde el compositor del chat, el botón de acciones (✨) te deja:

- **Enviar la carta:** inserta el link del menú público de tu negocio.
- **Enviar un carrito:** genera un link para que el cliente arme su pedido y lo confirme.
- **Crear pedido para este cliente:** abre la caja con el teléfono del cliente ya cargado, como pedido a domicilio.

## Atajos de teclado en la bandeja

Para atender rápido sin soltar el teclado:

- **J / K** — moverte entre conversaciones.
- **Enter** — saltar a escribir la respuesta.
- **Esc** — volver al listado.
- **/** — buscar.

## Cambiar o desconectar el número

Estas acciones son **solo del Propietario** y exigen un código de seguridad que llega por correo. No las puede hacer un Administrador, ni siquiera con permisos especiales.

- **Cambiar número:** útil si cambias de línea. Te desconecta el actual y te deja listo para conectar uno nuevo.
- **Desconectar:** cierra la sesión del número. Dejan de entrar mensajes hasta que vuelvas a escanear el QR. El historial de chats se preserva para auditoría.

<div class="callout callout-warn">
<p>
<strong>"No fui yo":</strong> el correo del código incluye un botón <em>"No fui yo"</em>. Si
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
<td>Conectar (con tu propio número o con uno de bistro)</td>
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
<strong>Costos del lado de Meta:</strong> WhatsApp tiene su propia tarifa por
conversación (la maneja Meta, no bistro). Hoy las conversaciones iniciadas por el cliente
las primeras 24h suelen ser gratis. Si tu volumen es alto, conviene revisar la política de
precios vigente de Meta.
</p>
</div>
