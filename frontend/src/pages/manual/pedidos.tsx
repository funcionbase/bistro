import ManualLayout from '@/layouts/manual-layout';
import { Link } from 'react-router-dom';

export default function ManualPedidos() {
    return (
        <ManualLayout
            currentSlug="pedidos"
            pageTitle="Pedidos"
            pageDescription="Cómo llegan los pedidos, el tablero kanban, aprobación por QR de mesa, mover estados, inventario, actualizaciones en vivo, KDS y devoluciones."
            metaTitle="Pedidos — Manual bistro.flexyflow.co"
            metaDescription="El tablero de pedidos de bistro.flexyflow.co: kanban, aprobación, KDS, tickets térmicos, devoluciones y todo lo que pasa desde que llega un pedido hasta que se entrega."
            sectionLabel="el día a día"
            readingTime="9 min"
        >
            <h2>Cómo llegan los pedidos</h2>
            <p>Hay cuatro fuentes de pedidos que convergen en el mismo tablero:</p>
            <ul>
                <li>
                    <strong>Menú público (web):</strong> el cliente entra al enlace de tu negocio, agrega al carrito
                    y confirma el pedido desde su navegador.
                </li>
                <li>
                    <strong>QR de mesa:</strong> el cliente escanea el QR de la mesa, navega la carta y ordena. La
                    mesa queda asociada al pedido.
                </li>
                <li>
                    <strong>WhatsApp (bot):</strong> el cliente conversa con el bot, que arma el carrito y genera un
                    enlace de confirmación.
                </li>
                <li>
                    <strong>Caja (POS):</strong> el cajero toma el pedido directamente desde la{' '}
                    <Link to="/manual/bistro/caja">caja</Link>, por teléfono o en mostrador.
                </li>
            </ul>

            <h2>El tablero kanban</h2>
            <p>
                El tablero tiene columnas por estado: <strong>Pendiente → En cocina → Listo → Completado</strong>.
                Si tienes domicilios activos, aparece también la columna <strong>En tránsito</strong> (solo para pedidos
                a domicilio). Cada pedido vive en una tarjeta con la mesa, el cliente, los ítems y el total.
            </p>
            <p>
                Puedes mover un pedido de columna arrastrando la tarjeta o usando el menú de la tarjeta. Hay
                filtros por fuente (mesa, web, WhatsApp, caja) y por estado.
            </p>

            <h3>Aprobación de pedidos (QR de mesa)</h3>
            <p>
                Cuando un cliente pide desde el QR de su mesa, el pedido llega en estado{' '}
                <strong>Pendiente esperando aprobación</strong>. El mesero lo aprueba (lo mueve a "en cocina") o lo
                rechaza con un motivo. Esto es para que la cocina no empiece a preparar algo sin que el equipo lo
                haya validado.
            </p>
            <p>
                Si tienes activado el <strong>auto-confirmar pedidos</strong> en preferencias, la aprobación manual
                no aplica: los pedidos entran directo en cocina.
            </p>

            <div className="callout callout-info">
                <p>
                    <strong>Aviso de aprobaciones pendientes:</strong> cuando hay pedidos esperando aprobación del
                    mesero, aparece un aviso naranja en la parte superior del tablero, la caja y la vista de mesas.
                    Un clic te lleva directo al pedido para aprobarlo o rechazarlo sin buscar en el tablero.
                </p>
            </div>

            <h2>Mover pedidos entre estados</h2>
            <p>
                Las transiciones son <em>forward-only</em> (hacia adelante) salvo cancelar. Una vez que un pedido
                está "completado", no vuelve a "en cocina". Si hubo un error, la corrección se hace con una
                devolución — el registro contable queda limpio.
            </p>

            <div className="callout callout-info">
                <p>
                    <strong>Estado "listo":</strong> cuando un pedido pasa a "listo", el cliente en la mesa puede
                    ver en su teléfono que su pedido está por llegar. Si tienes WhatsApp conectado, se puede
                    mandar un aviso automático.
                </p>
            </div>

            <h2>Mensajes de texto al cliente</h2>
            <p>
                Si el pedido tiene un número de teléfono, el cliente recibe un <strong>mensaje de texto (SMS)</strong>{' '}
                automático cuando su pedido cambia a un estado que le importa: entró en preparación, ya está
                listo, va en camino o fue entregado. El mensaje incluye el nombre de tu negocio y el código del
                pedido, por ejemplo: <em>"Flexy Burger: tu pedido #A3F9C1 va EN CAMINO"</em>.
            </p>
            <p>
                No tienes que hacer nada para que salga — funciona sin importar desde dónde se movió el pedido
                (el tablero, la pantalla de cocina o la caja). Si un mensaje no se pudo enviar, el panel te lo
                avisa para que puedas contactar al cliente por otro medio.
            </p>

            <h2>Inventario y recetas</h2>
            <p>
                Si tienes recetas configuradas en los platos, bistro flexy descuenta el inventario cuando la
                cocina marca el plato como listo. Si un ingrediente se acaba antes de que el turno termine, el
                plato se marca automáticamente como agotado en el menú público.
            </p>

            <h2>Actualizaciones en vivo</h2>
            <p>
                El tablero de pedidos se actualiza solo en tiempo real. No tienes que darle F5 — cuando llega
                un pedido nuevo, aparece en la columna "pendiente" y suena una notificación de audio (configurable
                en el navegador). Si la pestaña está al fondo, la notificación push del navegador te avisa.
            </p>
            <p>
                Al abrir el <strong>detalle de un pedido</strong>, la información también se refresca sola cada
                pocos segundos mientras tengas la pantalla abierta — si la cocina marca un plato como listo, lo
                ves sin cerrar y volver a entrar. Y si en algún momento quieres forzar la actualización, el botón
                de <strong>refrescar</strong> (la flecha circular) está siempre a la mano.
            </p>

            <h3>El tablero en el celular</h3>
            <p>
                En pantallas de celular el tablero cambia de columnas a una <strong>lista compacta</strong>,
                parecida a la pantalla de cocina: cada pedido es una fila con su estado, y cambias de estado con
                un toque en lugar de arrastrar. Es la misma información, acomodada para operar con una sola mano.
            </p>

            <h2>Pantalla KDS (cocina)</h2>
            <p>
                Cada estación de cocina tiene su propia vista de pantalla completa. Solo ve los ítems que le
                corresponden según las categorías asignadas. Desde esa vista el cocinero marca cada ítem como
                listo. Cuando todos los ítems de un pedido están listos, la comanda pasa a "listo" en el tablero
                del salón.
            </p>
            <p>
                La pantalla KDS se abre en <Link to="/manual/bistro/configuracion">configuración → KDS</Link> →
                link a la estación. Es una URL distinta que puedes poner en fullscreen en una tablet o monitor
                de cocina, independiente de la sesión del panel.
            </p>

            <h2>Tickets térmicos</h2>
            <p>
                Si tienes impresoras térmicas configuradas, el ticket se imprime automáticamente cuando el pedido
                pasa a "en cocina" (comanda) y cuando se cobra (recibo). Las comandas van a la impresora de cocina
                o barra según la categoría del plato.
            </p>
            <p>
                También puedes reimprimir cualquier ticket desde la tarjeta del pedido → menú → imprimir.
            </p>

            <h2>Devoluciones</h2>
            <p>
                Desde la tarjeta del pedido puedes hacer una devolución total o parcial. Seleccionas los ítems
                a devolver, el método de devolución y el motivo. El sistema crea un nuevo registro de cobro con
                monto negativo (crédito) — el pedido original queda intacto para la trazabilidad contable.
            </p>

            <div className="callout callout-warn">
                <p>
                    <strong>Devoluciones con tarjeta o transferencia:</strong> siempre hay que ingresar la
                    referencia del comprobante de la devolución. Es el único respaldo contable ante una auditoría
                    DIAN.
                </p>
            </div>

            <div className="callout callout-success">
                <p>
                    <strong>Pedidos activos en el dashboard:</strong> el panel de inicio siempre muestra cuántos
                    pedidos hay activos ahora mismo y en qué estado están, para que el dueño o gerente lo vea de
                    un vistazo sin entrar al tablero.
                </p>
            </div>
        </ManualLayout>
    );
}
