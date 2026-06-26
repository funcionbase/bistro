import ManualLayout from '@/layouts/manual-layout';
import { Link } from 'react-router-dom';

export default function ManualWhatsapp() {
    return (
        <ManualLayout
            currentSlug="whatsapp"
            pageTitle="WhatsApp del negocio"
            pageDescription="Conecta el número de WhatsApp de tu negocio a flexyflow. Recibe pedidos por chat, responde desde la app y configura el mensaje de bienvenida del bot."
            metaTitle="WhatsApp del negocio — Manual bistro.flexyflow.co"
            metaDescription="Conecta el WhatsApp Business de tu negocio a flexyflow para recibir pedidos y responder desde la misma app."
            sectionLabel="clientes y mercadeo"
            readingTime="7 min"
        >
            <h2>Para qué sirve</h2>
            <p>Conectar tu WhatsApp Business a flexyflow te deja:</p>
            <ul>
                <li>
                    Recibir los mensajes de tus clientes en el panel de{' '}
                    <Link to="/manual/bistro/chat">chat</Link>, sin tener que mirar el celular del negocio.
                </li>
                <li>
                    Responder desde la app, dejando registro de quién atendió cada conversación.
                </li>
                <li>
                    Mandar avisos automáticos al cliente cuando le asignan domiciliario o cuando se le entrega su
                    pedido.
                </li>
                <li>
                    Que el bot atienda solo lo repetitivo (horarios, carta, recibir un pedido) y le pase al equipo
                    humano solo cuando haga falta.
                </li>
            </ul>

            <h2>Dos formas de conectar</h2>

            <h3>Opción A: traer tu propio número</h3>
            <p>
                Si ya tienes un número de WhatsApp Business, lo conectas con el flujo oficial de Meta (
                <em>Embedded Signup</em>) — el mismo que usan grandes plataformas. Todo pasa adentro de una
                ventanita de Facebook, sin salir del panel.
            </p>
            <ol>
                <li>
                    En <strong>WhatsApp del negocio</strong> le das a <em>"Conectar con Facebook"</em>.
                </li>
                <li>
                    <strong>Te pide un código de seguridad</strong> que llega al correo del propietario. Lo digitas
                    (válido 10 minutos, máximo 3 intentos).
                </li>
                <li>
                    Te abre el flujo oficial de Facebook (Meta). Inicias sesión con tu cuenta de Facebook donde
                    está tu Business Manager.
                </li>
                <li>
                    Eliges la cuenta de WhatsApp Business que vas a conectar. Autorizas a flexyflow.
                </li>
                <li>
                    Vuelves a la app y ya aparece <em>"🟢 Conectado"</em> con tu número.
                </li>
            </ol>

            <h3>Opción B: que flexyflow te dé un número</h3>
            <p>
                Si no tienes WhatsApp Business todavía, puedes solicitar que flexyflow te asigne uno. Llenas un
                formulario corto con país, descripción del negocio y correo de contacto. Nuestro equipo te gestiona
                la asignación manualmente y te avisa cuando esté listo.
            </p>

            <h2>Mensajes entrantes</h2>
            <p>
                Cada mensaje que llega a tu número se registra automáticamente en el panel de{' '}
                <Link to="/manual/bistro/chat">chats</Link> como una conversación con el cliente. Si es la primera
                vez que ese teléfono te escribe, te crea el chat solito. Si es un cliente conocido, el mensaje se
                suma a la conversación existente.
            </p>

            <h2>Cómo arma un pedido el bot</h2>
            <p>Cuando el bot esté operativo, el flujo de un pedido por WhatsApp se parece a esto:</p>
            <ol>
                <li>
                    Cliente escribe a tu número. El bot consulta los{' '}
                    <Link to="/manual/bistro/horarios">horarios</Link> y responde solo si estás abierto.
                </li>
                <li>Le muestra la carta (la misma del menú público de tu negocio).</li>
                <li>
                    Conversa con el cliente, arma un carrito y le manda un enlace seguro tipo{' '}
                    <em>tutienda.flexyflow.co/carrito/abc123</em>. Ese enlace vive un tiempo limitado (unos 70
                    minutos por defecto).
                </li>
                <li>
                    El cliente abre el enlace en su navegador, revisa el carrito, paga y confirma. El pedido
                    aparece en tu <Link to="/manual/bistro/pedidos">tablero</Link> en tiempo real.
                </li>
                <li>
                    Si el cliente quiere hablar con un humano en cualquier momento, escribe algo como{' '}
                    <em>"hablar con alguien"</em> y el bot le pasa la conversación al equipo (handoff).
                </li>
            </ol>

            <div className="callout callout-info">
                <p>
                    <strong>Ventana de 24 horas:</strong> WhatsApp permite responder libremente a un cliente solo
                    dentro de las 24 horas siguientes a su último mensaje. Pasada esa ventana, para volver a iniciar
                    conversación se debe usar una <strong>plantilla pre-aprobada por Meta</strong> (por ejemplo:
                    aviso de pedido listo, recordatorio de reserva). Las plantillas las gestiona el equipo de
                    soporte de flexyflow contigo.
                </p>
            </div>

            <h2>Preferencias del WhatsApp</h2>

            <h3>Privacidad: doble chulito azul</h3>
            <p>
                Por defecto, los mensajes que tú lees no marcan doble chulito azul para el cliente. Si quieres
                que sí, activas el interruptor. La marca solo se manda cuando un operador efectivamente ve la
                conversación — no automáticamente al recibir.
            </p>

            <h3>Bot: mensajes editables</h3>
            <p>Tienes dos textos personalizables que el bot usará cuando esté operativo:</p>
            <ul>
                <li>
                    <strong>Mensaje de bienvenida:</strong> lo que el cliente recibe la primera vez que escribe a
                    tu WhatsApp. Por ejemplo:{' '}
                    <em>"¡Hola! Bienvenido a [nombre del negocio]. ¿En qué te ayudamos hoy?"</em>.
                </li>
                <li>
                    <strong>Mensaje de fuera de horario:</strong> cuando un cliente te escribe en hora cerrada. Por
                    ejemplo:{' '}
                    <em>"Gracias por escribir a [nombre del negocio]. Estamos cerrados ahora, abrimos mañana a
                    las 11 AM"</em>.
                </li>
            </ul>
            <p>
                Te aparece una vista previa estilo WhatsApp mientras editas. La sustitución{' '}
                <code>{'{'+'company_name'+'}'}</code> se rellena solita con el nombre comercial de tu negocio.
            </p>

            <h2>Cambiar o desconectar el número</h2>
            <p>
                Estas acciones son <strong>solo del Propietario</strong> y exigen código de seguridad por correo
                (OTP). No las puede hacer un Administrador, ni siquiera con permisos especiales.
            </p>
            <ul>
                <li>
                    <strong>Cambiar número:</strong> útil si cambias de línea. Te desconecta el actual y te deja
                    listo para conectar uno nuevo.
                </li>
                <li>
                    <strong>Desconectar:</strong> termina la conexión con Meta. El historial de chats se preserva
                    para auditoría.
                </li>
            </ul>

            <div className="callout callout-warn">
                <p>
                    <strong>"No fui yo":</strong> el correo del OTP incluye un botón <em>"No fui yo"</em>. Si
                    recibes un código que no pediste, haces clic en ese botón y el código queda inválido al
                    instante. Es una protección contra accesos no autorizados al cambio de número.
                </p>
            </div>

            <h2>Quién puede hacer qué</h2>
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

            <div className="callout callout-info">
                <p>
                    <strong>Costos del lado de Meta:</strong> WhatsApp Cloud API tiene su propia tarifa por
                    conversación (la maneja Meta, no flexyflow). Hoy las conversaciones iniciadas por el cliente
                    las primeras 24h suelen ser gratis. Si tu volumen es alto, conviene revisar la política de
                    precios vigente de Meta.
                </p>
            </div>
        </ManualLayout>
    );
}
