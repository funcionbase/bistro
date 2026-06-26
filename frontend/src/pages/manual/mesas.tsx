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
