import ManualLayout from '@/layouts/manual-layout';

export default function ManualEntregas() {
    return (
        <ManualLayout
            currentSlug="entregas"
            pageTitle="Entregas / Domicilios"
            pageDescription="La lista de domicilios, asignación de domiciliario, modo auto-asignación, reasignación, avisos por WhatsApp y métricas de entregas."
            metaTitle="Entregas / Domicilios — Manual bistro.flexyflow.co"
            metaDescription="Cómo gestionar los domicilios en bistro.flexyflow.co: asignación manual y automática, reasignación, cancelación y métricas de entrega."
            sectionLabel="el día a día"
            readingTime="8 min"
        >
            <h2>La lista de entregas</h2>
            <p>
                En <strong>pedidos → entregas</strong> ves todos los pedidos a domicilio del día, con su estado,
                el domiciliario asignado (si aplica), la dirección y cuánto tiempo llevan en espera. Hay filtros
                por estado y por domiciliario.
            </p>
            <p>
                Los estados de una entrega van de: <em>pendiente de asignación → en camino → entregado</em>. Hay
                también <em>rechazado</em> y <em>revertido</em> para casos especiales.
            </p>

            <h2>Asignación manual</h2>
            <p>
                El operador selecciona un pedido y le asigna un domiciliario de la lista de disponibles. En ese
                momento, si tienes WhatsApp conectado y la notificación
                activada, el cliente recibe un aviso automático con el nombre del domiciliario.
            </p>

            <h2>Modo auto-asignación (courier self-assign)</h2>
            <p>
                Con el modo courier activado, los domiciliarios pueden ver los pedidos pendientes de asignación en
                su propia vista y <strong>tomarse ellos mismos</strong> los pedidos que van a repartir. Útil para
                operaciones con muchos repartidores independientes donde no hay un operador asignando uno por uno.
            </p>
            <p>
                Para activar el modo courier para un domiciliario, el administrador le asigna el permiso de
                auto-asignación desde el panel de usuarios.
            </p>

            <div className="callout callout-info">
                <p>
                    <strong>Vista del domiciliario:</strong> el domiciliario entra a la app con su cuenta y ve solo
                    sus pedidos activos y los pendientes de asignación (en modo courier). No ve nada más del panel.
                </p>
            </div>

            <h2>Reasignación</h2>
            <p>
                Si un domiciliario se cayó, se le ponchó la moto o hay que redistribuir por volumen, puedes
                reasignar un pedido a otro domiciliario. El sistema registra el cambio con timestamp y quién lo
                hizo.
            </p>

            <h2>Finalizar, rechazar y revertir</h2>
            <ul>
                <li>
                    <strong>Finalizar entrega:</strong> el domiciliario (o el operador) confirma que el pedido
                    llegó. El pedido pasa a "entregado" y el cobro queda registrado.
                </li>
                <li>
                    <strong>Rechazar entrega:</strong> el domiciliario no pudo entregar (cliente no contestó,
                    dirección incorrecta, etc.). El pedido vuelve al operador para decidir qué hacer: reasignar,
                    cancelar o revertir.
                </li>
                <li>
                    <strong>Revertir a pendiente:</strong> el pedido vuelve a "pendiente de asignación" para
                    intentar de nuevo.
                </li>
            </ul>

            <h2>Avisos por WhatsApp</h2>
            <p>
                Si tienes WhatsApp conectado y las notificaciones de
                domicilios activadas:
            </p>
            <ul>
                <li>
                    <strong>Al asignar:</strong> el cliente recibe un mensaje con el nombre del domiciliario y
                    (si está configurado) un enlace de seguimiento.
                </li>
                <li>
                    <strong>Al entregar:</strong> el cliente recibe confirmación de entrega.
                </li>
            </ul>
            <p>
                Si el aviso falla (WhatsApp caído, número inválido), la operación sigue normal. El error queda
                en el registro de soporte.
            </p>

            <h2>Métricas de entregas</h2>
            <p>
                En <strong>entregas → métricas</strong> ves el resumen de desempeño del equipo de domicilios:
            </p>
            <table>
                <thead>
                    <tr>
                        <th>Métrica</th>
                        <th>Qué mide</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Tiempo promedio de entrega</td>
                        <td>Desde la asignación hasta la confirmación de entrega.</td>
                    </tr>
                    <tr>
                        <td>Pedidos por domiciliario</td>
                        <td>Cuántos pedidos completó cada uno en el período.</td>
                    </tr>
                    <tr>
                        <td>Tasa de rechazo</td>
                        <td>Pedidos que no llegaron vs. pedidos asignados.</td>
                    </tr>
                    <tr>
                        <td>Tiempo en estado pendiente</td>
                        <td>Cuánto tarda en asignarse un pedido desde que llega.</td>
                    </tr>
                </tbody>
            </table>

            <div className="callout callout-warn">
                <p>
                    <strong>Área de cobertura:</strong> el radio de domicilios se configura en{' '}
                    <em>configuración → preferencias → pedidos → área de cobertura</em>. Si un cliente está fuera
                    del radio, el sistema no le deja confirmar el pedido a domicilio.
                </p>
            </div>
        </ManualLayout>
    );
}
