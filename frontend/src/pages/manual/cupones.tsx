import ManualLayout from '@/layouts/manual-layout';
import { Link } from 'react-router-dom';

export default function ManualCupones() {
    return (
        <ManualLayout
            currentSlug="cupones"
            pageTitle="Cupones y descuentos"
            pageDescription="Promociones controladas: porcentaje o monto fijo, vigencia, alcance por sede, primer pedido, un solo uso por cliente, hora feliz automática e historial exportable."
            metaTitle="Cupones y descuentos — Manual bistro.flexyflow.co"
            metaDescription="Cupones por porcentaje o monto fijo, alcance por sede, hora feliz automática, un solo uso por teléfono, primer pedido, historial y exportación a PDF."
            sectionLabel="clientes y mercadeo"
            readingTime="8 min"
        >
            <h2>Crear un cupón</h2>
            <p>
                En <strong>cupones</strong> le das a <kbd>Nuevo cupón</kbd>. El formulario te pide:
            </p>
            <ul>
                <li>
                    <strong>Código:</strong> lo escribes (por ejemplo <em>BIENVENIDA10</em>) o le das a "Generar"
                    para que el sistema saque uno aleatorio (sin letras confusas como O/0, I/1). Solo letras,
                    números, guiones y guiones bajos. Mínimo 4, máximo 20 caracteres.{' '}
                    <strong>No se puede repetir dentro de tu negocio</strong>.
                </li>
                <li>
                    <strong>Tipo de descuento:</strong>
                    <ul>
                        <li>
                            <em>Porcentaje</em> — por ejemplo, 15% off. Tope máximo: 80%.
                        </li>
                        <li>
                            <em>Monto fijo</em> — por ejemplo, $5.000 menos. Tope máximo: $100.000.
                        </li>
                    </ul>
                </li>
                <li>
                    <strong>Vigencia:</strong> fecha de inicio y fecha de fin.
                </li>
                <li>
                    <strong>Tope de usos:</strong> cuando se alcanza, el cupón se agota automáticamente. Si lo
                    dejas vacío, es ilimitado.
                </li>
                <li>
                    <strong>Monto mínimo del pedido:</strong> el carrito tiene que llegar a este total para que
                    aplique. Útil para no regalar dinero en pedidos pequeños.
                </li>
                <li>
                    <strong>Solo primer pedido:</strong> el cliente solo puede usarlo si nunca te había comprado
                    antes. Para campañas de bienvenida.
                </li>
                <li>
                    <strong>Un solo uso por cliente:</strong> el cupón queda <em>amarrado al teléfono</em> del
                    primer cliente que lo usó. Aunque queden usos disponibles en el cupón general, ese mismo
                    teléfono no puede volver a aplicarlo. Útil para promociones personales que no quieres que se
                    compartan en grupos de WhatsApp.
                </li>
                <li>
                    <strong>Alcance (sedes):</strong> el cupón puede valer para <em>todas tus sedes</em> o solo
                    para las que tú escojas. Útil si quieres lanzar una promo solo en El Poblado y no en Laureles.
                </li>
                <li>
                    <strong>Aplicación automática:</strong> el sistema lo carga al carrito sin que el cliente
                    tenga que escribir el código (ver hora feliz, abajo).
                </li>
            </ul>

            <h2>Hora feliz: días y horas específicas (aplicación automática)</h2>
            <p>
                Una de las cosas más útiles: puedes crear un cupón que aplica solo{' '}
                <strong>ciertos días y ciertas horas</strong>, y que se aplica{' '}
                <strong>solito al cliente</strong> sin que tenga que escribir el código.
            </p>
            <ul>
                <li>
                    <strong>Días válidos:</strong> seleccionas los días de la semana (ej. solo martes y miércoles).
                </li>
                <li>
                    <strong>Horas válidas:</strong> rango horario (ej. 7 PM a 10 PM). Si tu hora feliz pasa
                    medianoche (10 PM a 2 AM), el sistema lo entiende.
                </li>
                <li>
                    <strong>Aplicación automática:</strong> el sistema busca el mejor cupón automático que aplica
                    y lo carga al pedido sin que el cliente lo escriba. Si hay varios elegibles, gana el de mayor
                    descuento. Al cliente le aparece un mensaje en el carrito tipo{' '}
                    <em>"¡Felicidades! Aplicamos MARTES2X1"</em>.
                </li>
            </ul>

            <h3>Un ejemplo de hora feliz</h3>
            <p>
                Una <strong>pizzería</strong> quiere llenar las noches de martes. Crea el cupón{' '}
                <em>MARTES2X1</em>:
            </p>
            <ul>
                <li>Tipo: porcentaje, 50%.</li>
                <li>Días válidos: solo martes.</li>
                <li>Horas válidas: 7 PM a 10 PM.</li>
                <li>Monto mínimo: $35.000 (para que aplique solo en pizza familiar).</li>
                <li>Aplicación automática: sí.</li>
            </ul>
            <p>
                El martes a las 7:30 PM, un cliente ordena pizza familiar por $40.000. Sin que escriba código,
                el sistema le aplica 50% solito. El total final son $20.000. Le aparece en el carrito:{' '}
                <em>"¡Felicidades! Aplicamos MARTES2X1 (-$20.000)"</em>.
            </p>

            <h2>Editar y eliminar</h2>
            <ul>
                <li>
                    <strong>Editar las reglas</strong> (descuento, vigencia, etc.): solo si{' '}
                    <strong>nadie ha usado el cupón todavía</strong>. Una vez que un cliente lo redime, las reglas
                    quedan fijas para que el historial sea fiel. Si necesitas cambiar reglas, crea uno nuevo.
                </li>
                <li>
                    <strong>Activar / desactivar:</strong> esto sí puedes hacerlo siempre, aunque el cupón tenga
                    usos. Sirve para pausar campañas en curso sin perder la configuración.
                </li>
                <li>
                    <strong>Eliminar:</strong> si nunca se usó, se borra. Si ya se redimió alguna vez, se archiva
                    pero queda en el historial.
                </li>
            </ul>

            <h2>Detalle del cupón</h2>
            <p>El detalle muestra el resumen y el historial de quién lo usó:</p>
            <ul>
                <li>Monto del pedido antes y después del descuento.</li>
                <li>
                    Teléfono del cliente, parcialmente oculto (ej. <em>+57 300 *** **34</em>).
                </li>
                <li>Las últimas 50 redenciones por página, con fecha y hora.</li>
            </ul>
            <p>
                Desde el detalle puedes <strong>exportar el historial a PDF</strong> para llevarlo a una reunión,
                anexarlo a un informe contable o mandárselo al socio que pregunta cuánto se gastó en la promo.
            </p>

            <h2>Cómo el cliente aplica el cupón</h2>
            <p>
                En el carrito (lado cliente o en la <Link to="/manual/bistro/caja">caja</Link> del POS), escribe
                el código y el descuento se calcula al instante. El sistema verifica:
            </p>
            <ol>
                <li>Que el cupón exista, esté activo y dentro de fechas.</li>
                <li>Que no haya alcanzado el tope de usos.</li>
                <li>Que el carrito cumpla el monto mínimo.</li>
                <li>Si aplica, que sea el primer pedido del cliente.</li>
                <li>Si tiene reglas de hora feliz, que estemos en día y hora válidos.</li>
                <li>
                    Si está limitado a sedes específicas, que la sede del pedido sea una de las permitidas.
                </li>
                <li>
                    Si el cupón es de un solo uso por persona, que ese teléfono no lo haya usado antes.
                </li>
            </ol>

            <p>
                <strong>Solo un cupón por pedido.</strong> Si el cliente intenta aplicar otro, el nuevo reemplaza
                al anterior.
            </p>

            <h3>Cupones de fidelización</h3>
            <p>
                Cuando un cliente canjea sus puntos (ver{' '}
                <Link to="/manual/bistro/fidelizacion">fidelización</Link>), el sistema le emite un cupón temporal
                único, amarrado a su teléfono y con vencimiento corto (por defecto 60 minutos). Ese cupón no
                aparece en el listado público de cupones — vive aparte para que tu campaña promocional y tu
                programa de puntos no se mezclen visualmente.
            </p>

            <h2>Cómo se trata el descuento contablemente</h2>
            <p>
                El descuento <strong>reduce la base gravable</strong> de la orden, no se trata como un pago. El
                subtotal y el impuesto se recalculan proporcionalmente para que el total cuadre. Esto es
                DIAN-friendly y deja la contabilidad bien hecha.
            </p>

            <div className="callout callout-warn">
                <p>
                    <strong>Atención:</strong> el cálculo del descuento siempre se hace en el servidor con los
                    precios oficiales del menú. Si un cliente "modifica" el precio desde el navegador, la app lo
                    ignora. No se pierde dinero por manipulación.
                </p>
            </div>

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
                        <td>Ver cupones</td>
                        <td>Consultar la lista, el detalle y el historial.</td>
                    </tr>
                    <tr>
                        <td>Crear</td>
                        <td>Agregar cupones nuevos.</td>
                    </tr>
                    <tr>
                        <td>Editar</td>
                        <td>Modificar (si nadie lo ha usado), activar o desactivar siempre.</td>
                    </tr>
                    <tr>
                        <td>Eliminar</td>
                        <td>Borrar el cupón.</td>
                    </tr>
                </tbody>
            </table>

            <div className="callout callout-success">
                <p>
                    <strong>Combinación recomendada:</strong> crea un cupón <em>BIENVENIDA15</em> con 15% off,
                    monto mínimo de $25.000 y marca "solo primer pedido". Lo promocionas en tu página de pedidos
                    y en redes. Es un imán para atraer al que duda en probar tu operación.
                </p>
            </div>
        </ManualLayout>
    );
}
