import ManualLayout from '@/layouts/manual-layout';
import { Link } from 'react-router-dom';

export default function ManualFidelizacion() {
    return (
        <ManualLayout
            currentSlug="fidelizacion"
            pageTitle="Puntos de fidelidad"
            pageDescription="El cliente acumula puntos cada vez que te compra y los canjea por descuentos. Niveles, libro mayor auditado, expiración automática y reportes consolidados entre sedes."
            metaTitle="Puntos de fidelidad — Manual bistro.flexyflow.co"
            metaDescription="Programa de fidelización con puntos por compra, niveles (bronce, plata, oro), canje por cupones amarrados al teléfono, libro mayor auditado y reportes consolidados entre sedes."
            sectionLabel="clientes y mercadeo"
            readingTime="7 min"
        >
            <h2>Cómo funciona</h2>
            <p>
                Por cada pedido completado, el cliente acumula puntos en una cuenta que vive a nivel de empresa
                (no por sede). Esos puntos los puede canjear por descuentos predefinidos:{' '}
                <em>"500 pts = $5.000 de descuento"</em>, por ejemplo.
            </p>
            <p>
                El programa es <strong>opcional</strong> y se prende desde{' '}
                <Link to="/manual/bistro/configuracion">configuración</Link>. Si no lo activas, los clientes nunca
                ven el panel de puntos y la operación no cambia.
            </p>

            <h3>Una sola cuenta entre todas las sedes</h3>
            <p>
                Si tienes <Link to="/manual/bistro/sedes">varias sedes</Link>, los puntos del cliente se suman en
                una sola cuenta. <strong>Don Hernán pide en la sede de El Poblado el lunes y en la de Laureles el
                viernes</strong> — los puntos van al mismo saldo. Cuando canjee, puede usar el descuento en
                cualquier sede.
            </p>

            <h3>El libro mayor de puntos</h3>
            <p>
                Cada punto que entra o sale queda anotado como un <strong>movimiento auditado</strong>: cuándo,
                por qué, asociado a qué pedido. Nada se edita ni se borra después. Si hay que corregir algo, se
                hace con un movimiento nuevo (positivo o negativo). Es como una contabilidad — transparente y
                fácil de auditar.
            </p>

            <h2>Cómo se ganan los puntos</h2>
            <p>
                Cuando cierras un pedido cobrado (no aplica con cancelados ni devueltos), el sistema le asigna
                puntos al cliente con esta fórmula. El cálculo es <strong>idempotente</strong>: si por alguna
                razón el pedido se procesa dos veces (un reintento de cobro, por ejemplo), los puntos se otorgan
                una sola vez. Nunca se duplican.
            </p>
            <p>
                <code>puntos = pedido_total × tasa_de_puntos × multiplicador_de_nivel</code>
            </p>
            <ul>
                <li>
                    <strong>Tasa de puntos:</strong> por defecto, 1 punto por cada $100 gastados. Configurable.
                </li>
                <li>
                    <strong>Multiplicador de nivel:</strong> el cliente sube de nivel según cuánto ha gastado en
                    su vida con tu operación. Cada nivel tiene un multiplicador:
                    <ul>
                        <li>
                            <strong>Bronce</strong> — 1.0× (tasa base)
                        </li>
                        <li>
                            <strong>Plata</strong> — 1.2× (gana 20% más puntos)
                        </li>
                        <li>
                            <strong>Oro</strong> — 1.4× (gana 40% más puntos)
                        </li>
                    </ul>
                </li>
            </ul>

            <h3>Un ejemplo</h3>
            <p>Laura tiene nivel Plata. Hace un pedido de $35.000:</p>
            <p>
                <code>35.000 × (1 ÷ 100) × 1.2 = 420 puntos</code>
            </p>
            <p>
                Se le suman a su cuenta. Si ya tenía 1.080, ahora tiene 1.500. Listo para canjear su próximo
                descuento.
            </p>

            <h2>Catálogo de recompensas</h2>
            <p>
                Defines un catálogo de recompensas que el cliente puede canjear con sus puntos. Cada una tiene:
            </p>
            <ul>
                <li>
                    <strong>Etiqueta</strong> (ej. <em>"$5.000 de descuento"</em>).
                </li>
                <li>
                    <strong>Puntos necesarios</strong> (ej. 500 pts).
                </li>
                <li>
                    <strong>Tipo de descuento:</strong> hoy solo monto fijo (ej. $5.000 menos en el pedido).
                </li>
                <li>
                    <strong>Monto mínimo de pedido</strong> para poder usarlo (ej. el pedido debe ser de al
                    menos $25.000).
                </li>
            </ul>

            <h3>Cómo se hace un canje</h3>
            <ol>
                <li>
                    El cliente entra al carrito (o tú lo abres en su nombre desde la ficha del cliente).
                </li>
                <li>Ve su saldo de puntos y las recompensas disponibles.</li>
                <li>Elige una que tenga puntos suficientes. Le da a "Canjear".</li>
                <li>
                    El sistema le resta los puntos al instante y le genera un cupón único, válido por unos minutos
                    (configurable, 60 por defecto), <strong>amarrado solo a su teléfono</strong> — nadie más puede
                    usarlo aunque lo reenvíen por WhatsApp.
                </li>
                <li>
                    El cliente aplica ese cupón en el carrito como cualquier otro. En el lado cliente, el código
                    se inyecta automáticamente para que solo presione "Aplicar".
                </li>
            </ol>

            <p>
                Si el cupón expira sin usarse, <strong>los puntos no se devuelven</strong> — el cliente asumió
                ese riesgo al canjear. Pero si tú, el operador, anulas el canje antes de que se use, los puntos
                vuelven a la cuenta con un movimiento auditado y un motivo.
            </p>

            <h2>Niveles y subida automática</h2>
            <p>
                El nivel del cliente se recalcula cada vez que gana puntos. Se basa en su{' '}
                <strong>total acumulado de por vida</strong> (no en el saldo actual). Por ejemplo, los niveles
                por defecto:
            </p>
            <ul>
                <li>
                    <strong>Bronce</strong> — desde 0 puntos.
                </li>
                <li>
                    <strong>Plata</strong> — a partir de 2.000 puntos acumulados.
                </li>
                <li>
                    <strong>Oro</strong> — a partir de 10.000 puntos acumulados.
                </li>
            </ul>
            <p>
                Los umbrales son configurables. El cliente nunca "baja" de nivel porque canjeó — el nivel mide
                su gasto histórico, no su saldo actual.
            </p>

            <h2>Devoluciones y los puntos</h2>
            <ul>
                <li>
                    <strong>Devolución total</strong> de un pedido: los puntos que se otorgaron se reversan
                    automáticamente.
                </li>
                <li>
                    <strong>Devolución parcial</strong>: los puntos no se reversan (decisión pragmática — el
                    incentivo del cliente se mantiene).
                </li>
            </ul>

            <h2>Ajustes manuales</h2>
            <p>
                Desde la ficha del cliente puedes <strong>ajustar puntos a mano</strong>: sumar o restar con un
                motivo. Útil para:
            </p>
            <ul>
                <li>Compensar una mala experiencia ("+500 pts por la demora").</li>
                <li>Corregir un error operativo ("+200 pts, le faltaba un pedido").</li>
                <li>Reconocer algo especial ("+1000 pts por su cumpleaños").</li>
            </ul>
            <p>
                Cada ajuste manual queda auditado con tu identidad y el motivo. Tiene un tope (por defecto,
                10.000 puntos por ajuste) para evitar accidentes. Los ajustes positivos{' '}
                <strong>no</strong> suman al acumulado del nivel (para que un regalo no te suba a alguien de
                nivel artificialmente).
            </p>

            <h2>Expiración</h2>
            <p>
                Puedes configurar que los puntos se venzan si el cliente no compra durante un tiempo determinado
                (el período es configurable por ti). Si lo dejas en cero, los puntos nunca se vencen.
            </p>
            <p>
                La expiración se ejecuta <strong>cada madrugada en un proceso automático</strong>. Al cliente que
                se le venzan los puntos se le crea un movimiento auditado con el detalle. Ese mismo proceso
                también vence los cupones de canje que no se usaron a tiempo.
            </p>

            <h2>Reportes de fidelización</h2>
            <p>
                En <strong>fidelización → reportes</strong> tienes la vista consolidada del programa, con datos
                cruzados entre todas las sedes. Por defecto te muestra los últimos 30 días:
            </p>
            <ul>
                <li>
                    Puntos <strong>otorgados, canjeados, vencidos y reversados</strong> en el período.
                </li>
                <li>
                    Cuántos clientes activos tienes en el programa y cuántos eventos de ganancia/canje hubo.
                </li>
                <li>
                    <strong>Tasa de canje:</strong> qué porcentaje de cupones emitidos efectivamente se usó.
                </li>
                <li>Distribución de clientes por nivel (cuántos bronce, plata, oro).</li>
                <li>
                    <strong>Ingreso promedio por nivel:</strong> cuánto gasta en promedio un cliente bronce vs
                    uno plata vs uno oro.
                </li>
                <li>Los 20 mejores clientes por puntos acumulados de por vida.</li>
            </ul>

            <h2>Quién puede hacer qué</h2>
            <table>
                <thead>
                    <tr>
                        <th>Acción</th>
                        <th>Qué permite</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Ver fidelización</td>
                        <td>Consultar saldos, reportes y movimientos.</td>
                    </tr>
                    <tr>
                        <td>Editar</td>
                        <td>Ajustar puntos a mano y hacer canjes a nombre del cliente.</td>
                    </tr>
                </tbody>
            </table>

            <div className="callout callout-info">
                <p>
                    <strong>Consejo de mercadeo:</strong> avísales a los clientes nuevos del programa cuando hagan
                    su primer pedido. Un WhatsApp simple como <em>"¡Bienvenido! Acabas de ganar tus primeros 200
                    puntos. Con 500 puntos tienes $5.000 de descuento en tu próximo pedido"</em> genera mucha más
                    recompra que un cupón frío.
                </p>
            </div>
        </ManualLayout>
    );
}
