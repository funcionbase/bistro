import ManualLayout from '@/layouts/manual-layout';

export default function ManualChat() {
    return (
        <ManualLayout
            currentSlug="chat"
            pageTitle="Chat"
            pageDescription="El panel de conversaciones de WhatsApp: multi-sede, búsqueda, responder mensajes, medios, doble chulito azul, estado del bot y perfil del cliente."
            metaTitle="Chat — Manual bistro.flexyflow.co"
            metaDescription="Cómo funciona el panel de chats de WhatsApp en bistro.flexyflow.co: múltiples sedes, buscar conversaciones, enviar mensajes y medios, estado del bot."
            sectionLabel="el día a día"
            readingTime="7 min"
        >
            <h2>Para qué sirve el panel de chat</h2>
            <p>
                Cuando conectas tu WhatsApp del negocio a flexyflow, todos los mensajes
                que llegan a tu número aparecen acá. El equipo puede responder desde el panel sin necesidad de
                tocar el celular del negocio — y queda registro de quién atendió cada conversación.
            </p>

            <h2>Multi-sede</h2>
            <p>
                Si tienes varias sedes, cada una puede tener su propio número de WhatsApp. El panel de chats
                muestra las conversaciones de la sede en la que estás activo. Para ver los chats de otra sede,
                cambias de sede en el selector del menú lateral.
            </p>
            <p>
                Un administrador con permiso especial puede reasignar un chat de una sede a otra (por ejemplo, si
                el cliente escribió al número equivocado).
            </p>

            <h2>La lista de conversaciones</h2>
            <p>
                Las conversaciones se ordenan por último mensaje. Hay un buscador para encontrar por nombre del
                cliente o por número de teléfono. Cada conversación muestra:
            </p>
            <ul>
                <li>Nombre del cliente (si lo tiene registrado en el CRM) o el teléfono.</li>
                <li>Último mensaje y cuándo fue.</li>
                <li>Si hay mensajes no leídos (badge rojo).</li>
                <li>Si el bot está activo o pausado en esa conversación.</li>
            </ul>

            <h2>Responder un mensaje</h2>
            <p>
                Dentro de la conversación ves el historial completo. Para responder, escribes en el campo de
                texto y le das enviar. El mensaje sale desde el número de WhatsApp del negocio — el cliente lo
                ve como si respondiera alguien del equipo del local.
            </p>

            <h3>Medios</h3>
            <p>
                Puedes enviar imágenes, PDFs y audio. Los formatos aceptados por WhatsApp son los estándar
                (JPG, PNG, PDF, MP3, OGG). El tamaño máximo lo define Meta (actualmente 16 MB para imágenes,
                100 MB para documentos).
            </p>

            <h3>Doble chulito azul</h3>
            <p>
                Por defecto, cuando lees un mensaje en el panel <strong>no</strong> se marca como leído para el
                cliente (no hay doble chulito azul). Si quieres activarlo, ve a{' '}
                <em>configuración → WhatsApp → preferencias</em> y activa el interruptor de "doble chulito azul".
                La marca solo se manda cuando un operador efectivamente ve la conversación (panel abierto, pestaña
                activa).
            </p>

            <h2>Estado del bot</h2>
            <p>
                Cada conversación tiene un interruptor de <strong>bot activo / pausado</strong>:
            </p>
            <ul>
                <li>
                    <strong>Bot activo:</strong> el bot atiende automáticamente. El operador puede leer pero no
                    interrumpe el flujo del bot.
                </li>
                <li>
                    <strong>Bot pausado:</strong> un humano atiende. El bot no responde aunque el cliente escriba.
                    Útil cuando hay que resolver algo que el bot no puede: una queja, un pedido especial, datos de
                    facturación complicados.
                </li>
            </ul>
            <p>
                Cuando el cliente pide hablar con un humano ("hablar con alguien", "agente"), el bot pausa
                automáticamente y la conversación aparece en la lista como "en espera de atención humana".
            </p>

            <div className="callout callout-warn">
                <p>
                    <strong>Ventana de 24 horas de WhatsApp:</strong> puedes responder libremente solo dentro de
                    las 24 horas siguientes al último mensaje del cliente. Pasada esa ventana, para iniciar
                    conversación necesitas una plantilla pre-aprobada por Meta.
                </p>
            </div>

            <h2>Perfil del cliente en el chat</h2>
            <p>
                Al abrir una conversación, el panel lateral derecho muestra el perfil del cliente: nombre,
                teléfono, historial de pedidos, puntos de fidelidad, notas privadas y etiquetas. Puedes editar
                la ficha sin salir del chat — útil para agregar una nota mientras atiendes.
            </p>
        </ManualLayout>
    );
}
