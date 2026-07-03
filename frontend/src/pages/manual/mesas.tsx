import ManualLayout from '@/layouts/manual-layout';

export default function ManualMesas() {
    return (
        <ManualLayout
            currentSlug="mesas"
            pageTitle="Mesas y QR"
            pageDescription="El mapa de mesas, las sesiones con QR, aprobación del mesero, cobro dividido y cierre de mesa."
            metaTitle="Mesas y QR — Manual bistro.flexyflow.co"
            metaDescription="Cómo funciona el sistema de mesas y QR de bistro.flexyflow.co: sesiones, aprobación, cobro dividido entre comensales y cierre automático."
            sectionLabel="el día a día"
            readingTime="7 min"
        >
            <h2>El mapa de mesas</h2>
            <p>
                En <strong>pedidos → mesas</strong> ves el mapa de todas las mesas del local. Cada mesa muestra
                su estado: libre, ocupada, o con sesión activa. Puedes abrir una sesión desde cualquier mesa
                libre o ver el detalle de las ocupadas.
            </p>
            <p>
                Las mesas se configuran en <em>configuración → mesas</em>: les pones nombre (Mesa 1, Terraza 3,
                Barra 2), capacidad y área (salón, terraza, bar).
            </p>

            <h2>Sesión de mesa con QR</h2>
            <p>
                Cada mesa tiene un QR físico (lo imprimes desde el panel). Cuando el cliente lo escanea, el sistema
                abre una sesión de mesa y lo lleva directo al menú de tu negocio — sin app, sin login, desde el
                navegador del celular.
            </p>
            <p>
                El cliente puede ver la carta, agregar al carrito y confirmar el pedido. El pedido llega al tablero
                del panel con la mesa asociada, listo para que el mesero lo apruebe.
            </p>

            <h3>Aprobación del mesero</h3>
            <p>
                Los pedidos de mesa llegan en estado <strong>pendiente</strong>. El mesero los aprueba (pasan a
                "en cocina") o los rechaza con un motivo. Esto permite corregir errores antes de que la cocina
                empiece a preparar.
            </p>
            <p>
                Si tienes <em>auto-confirmar</em> activado en preferencias, la aprobación manual no aplica y los
                pedidos entran directo en cocina.
            </p>

            <div className="callout callout-warn">
                <p>
                    <strong>QR de sesión vs QR fijo:</strong> hay dos modos. (1) QR fijo — siempre apunta al menú
                    del negocio; el cliente escoge la mesa manualmente. (2) QR por mesa — cada mesa tiene su propio
                    QR y el sistema sabe a cuál mesa pertenece. El modo 2 requiere tener las mesas configuradas.
                </p>
            </div>

            <h3>Pedir para llevar desde el QR de la sede</h3>
            <p>
                El QR fijo de la sede también sirve para clientes que <strong>no van a sentarse</strong>: al
                escanearlo pueden pedir <strong>para llevar o a domicilio</strong> sin escoger mesa. El pedido
                llega al tablero como cualquier otro, marcado con su tipo, y sigue el flujo normal de aprobación
                y cobro. Es ideal para imprimirlo en la barra, la entrada o el empaque.
            </p>

            <h2>Múltiples rondas de pedidos en la misma sesión</h2>
            <p>
                En la misma sesión de mesa, el cliente puede hacer <strong>varias rondas de pedidos</strong> sin
                cerrar la sesión ni escanear el QR de nuevo. Ordena las entradas, el mesero las aprueba, y más
                tarde puede pedir el plato fuerte con un segundo pedido en la misma sesión. El total acumulado
                de la mesa crece con cada ronda aprobada.
            </p>
            <p>
                Desde la vista del mesero, cada ronda de pedidos aparece como un pedido separado dentro de la
                misma sesión, para que sea fácil ver qué aprobó cuándo.
            </p>
            <p>
                El cliente también lo ve así en su celular: su cuenta aparece <strong>agrupada por tandas</strong>,
                cada una con lo que pidió y su subtotal, más el total acumulado de la mesa. Así nadie se pierde de
                cuánto va la cuenta aunque hayan pedido en tres momentos distintos.
            </p>

            <h3>Agregar productos desde el panel</h3>
            <p>
                El mesero también puede <strong>agregar productos a una mesa activa</strong> directamente desde el
                detalle del pedido en el panel — útil cuando el cliente pide algo de viva voz en vez de usar el
                celular. Lo agregado entra a la misma cuenta de la mesa, como una tanda más.
            </p>

            <h3>Cancelar un ítem ya aprobado</h3>
            <p>
                Si el cliente o el mesero necesita cancelar un ítem que ya fue aprobado (pero que todavía no
                pasó a la cocina), hay un botón <strong>"Cancelar ítem"</strong> disponible en la tarjeta del
                pedido. Se puede ingresar un motivo opcional (por ejemplo, "cliente cambió de opinión"). Los
                ítems cancelados quedan registrados en el historial de la sesión.
            </p>

            <h3>Asignar mesa a un pedido QR sin mesa</h3>
            <p>
                Cuando un pedido llega por QR sin mesa asignada (por ejemplo, de alguien que pidió por el bot
                de WhatsApp y quiere atenerse en salón), el mesero puede asignarle una mesa física directamente
                desde la sesión. La mesa queda vinculada al pedido y ya aparece en el mapa.
            </p>

            <h2>La cuenta en vivo</h2>
            <p>
                El cliente puede ver su cuenta en tiempo real escaneando el QR de la mesa. Puede agregar más
                pedidos en la misma sesión (sujeto a aprobación del mesero). Cuando decide pagar, el mesero va a
                la mesa en el panel y ejecuta el cobro.
            </p>

            <h2>Cobro dividido</h2>
            <p>
                Cuando hay varios comensales en la misma mesa, puedes dividir la cuenta:
            </p>
            <ul>
                <li>
                    <strong>Partes iguales:</strong> divides el total entre N personas.
                </li>
                <li>
                    <strong>Por ítem:</strong> cada persona paga lo que pidió.
                </li>
                <li>
                    <strong>Mixto:</strong> algunas partes iguales, otras por ítem.
                </li>
            </ul>
            <p>
                Cada parte puede pagarse con método diferente (uno en efectivo, otro con tarjeta). El cobro total
                de la mesa cierra cuando se pagan todas las partes.
            </p>

            <h2>Cierre de mesa</h2>
            <p>
                Cuando se cobra el último pago, la sesión de mesa se cierra automáticamente y la mesa queda libre
                en el mapa. El sistema registra el historial completo de la sesión: quién ordenó qué, cuándo, y
                cómo se pagó.
            </p>

            <div className="callout callout-info">
                <p>
                    <strong>La mesa no se libera sola con cuenta pendiente:</strong> aunque los comensales lleven
                    un buen rato sin tocar el celular, mientras haya productos servidos sin pagar la mesa sigue
                    ocupada y la cuenta sigue visible en el panel. La sesión solo se cierra cuando se cobra (o
                    cuando el mesero la cierra a mano). Las mesas que se cierran solas por inactividad son
                    únicamente las que no tienen nada pendiente de pago.
                </p>
            </div>

            <div className="callout callout-success">
                <p>
                    <strong>Flujo rápido:</strong> cliente escanea QR → ordena desde el celular → mesero aprueba →
                    cocina prepara → mesero trae → cliente pide la cuenta → cobro dividido si aplica → mesa libre.
                    Todo sin papel ni gritos cruzados.
                </p>
            </div>
        </ManualLayout>
    );
}
