import ManualLayout from '@/layouts/manual-layout';
import { Link } from 'react-router-dom';

export default function ManualInventario() {
    return (
        <ManualLayout
            currentSlug="inventario"
            pageTitle="Inventario"
            pageDescription="Control de insumos por bodega: entradas, mermas, ajustes, transferencias entre bodegas, historial de movimientos y valorización en tiempo real."
            metaTitle="Inventario — Manual bistro.flexyflow.co"
            metaDescription="Cómo gestionar el inventario de insumos en bistro.flexyflow.co: bodegas, movimientos (entrada/merma/ajuste/transferencia), historial y valorización."
            sectionLabel="el día a día"
            readingTime="8 min"
        >
            <h2>¿Para qué sirve el inventario?</h2>
            <p>
                El módulo de inventario lleva el control del stock de insumos (ingredientes, bebidas, empaque,
                suministros) y lo conecta directamente con las recetas del menú. Cada vez que el KDS marca un
                plato como listo, bistro flexy descuenta automáticamente los ingredientes de la receta. El
                resultado: siempre sabes cuánto tienes, qué cuesta y qué platos están en riesgo de agotarse.
            </p>
            <p>
                El inventario es por <strong>sede</strong>. Si tienes varias sedes, cada una lleva su propio
                control — pero un insumo puede estar en varias bodegas dentro de una misma sede.
            </p>

            <h2>Insumos (ingredientes)</h2>
            <p>
                Cada insumo tiene:
            </p>
            <ul>
                <li>
                    <strong>Nombre y unidad de medida</strong> (kg, g, ml, L, unidad, porción, etc.).
                </li>
                <li>
                    <strong>Costo por unidad</strong> — el costo actual. Cuando subes una compra, el costo se
                    actualiza automáticamente si cambias el precio unitario en la orden de compra.
                </li>
                <li>
                    <strong>Bodega</strong> — a cuál bodega pertenece (cocina, barra, almacén, etc.).
                </li>
                <li>
                    <strong>Stock actual</strong> — la cantidad disponible en ese momento, resultado de todos
                    los movimientos acumulados.
                </li>
                <li>
                    <strong>Umbral de alerta</strong> — si el stock cae por debajo de este valor, aparece una
                    alerta en el panel de{' '}
                    <Link to="/manual/bistro/alertas">alertas</Link>.
                </li>
            </ul>

            <h3>Crear y editar insumos</h3>
            <p>
                Desde <strong>inventario → nuevo insumo</strong>. El nombre debe ser único dentro de la sede.
                Si ya existe pero está archivado, lo puedes reactivar en lugar de crear uno nuevo.
            </p>
            <p>
                Para <strong>archivar</strong> un insumo: en el menú de la fila (…) → archivar. El sistema lo
                saca de la vista activa pero conserva todo su historial. No se puede archivar un insumo que
                está en una receta activa de un plato publicado.
            </p>

            <h2>Bodegas</h2>
            <p>
                Una sede puede tener varias <strong>bodegas</strong>: cocina, barra, congelador, almacén
                general. Las bodegas se configuran en{' '}
                <em>configuración → sedes → bodegas</em>. Cada insumo pertenece a una bodega, y el stock se
                lleva por bodega — no hay un "total de sede" que mezcle todo.
            </p>
            <p>
                Si necesitas mover insumos entre bodegas de la misma sede, usa una{' '}
                <strong>transferencia</strong> (ver abajo).
            </p>

            <div className="callout callout-warn">
                <p>
                    <strong>No puedes archivar una bodega con stock.</strong> Primero hay que sacar el stock
                    (transferir o registrar una merma) y luego archivar.
                </p>
            </div>

            <h2>Movimientos de inventario</h2>
            <p>
                Cada cambio en el stock queda registrado como un <strong>movimiento</strong>. Hay cuatro tipos:
            </p>

            <table>
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Cuándo se usa</th>
                        <th>Signo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <strong>Entrada</strong>
                        </td>
                        <td>
                            Llegó mercancía: una compra, una donación, una transferencia entre sedes. También
                            se genera automáticamente al marcar una orden de compra como "recibida".
                        </td>
                        <td>+ (suma)</td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Merma</strong>
                        </td>
                        <td>
                            Se perdió insumo: vencimiento, derrame, rotura. Lleva un campo de motivo para
                            tener trazabilidad.
                        </td>
                        <td>− (resta)</td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Ajuste</strong>
                        </td>
                        <td>
                            El conteo físico no coincide con el sistema. Corrección manual que puede sumar o
                            restar. Requiere motivo y queda en auditoría.
                        </td>
                        <td>± (puede ser ambos)</td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Transferencia</strong>
                        </td>
                        <td>
                            Mover insumos entre bodegas dentro de la misma sede. Sale de la bodega origen y
                            entra a la bodega destino en el mismo movimiento.
                        </td>
                        <td>− en origen / + en destino</td>
                    </tr>
                </tbody>
            </table>

            <div className="callout callout-info">
                <p>
                    <strong>Movimiento automático por receta:</strong> cuando el KDS marca un plato como
                    "listo", bistro flexy descuenta automáticamente cada ingrediente de la receta del plato,
                    según la cantidad configurada. Ese movimiento aparece en el historial como "consumo por
                    orden".
                </p>
            </div>

            <h2>Historial de movimientos</h2>
            <p>
                En la fila de cualquier insumo → icono de historial (reloj) → se abre el panel lateral con
                todos los movimientos de ese insumo en orden cronológico: fecha, tipo, cantidad, quién lo
                registró y el motivo (si aplica). Puedes filtrar por fecha.
            </p>

            <h2>Gráfica de valorización</h2>
            <p>
                Al tope de la página de inventario hay una gráfica que muestra el{' '}
                <strong>valor total del inventario</strong> (stock × costo unitario) a lo largo del tiempo.
                Útil para detectar pérdidas o días en que el stock se disparó por una compra grande.
            </p>
            <p>
                Puedes cambiar el rango de fechas con el selector. El cálculo usa el costo vigente en cada
                movimiento, no el costo actual.
            </p>

            <h2>Integración con recetas</h2>
            <p>
                Las recetas se configuran en el menú de cada plato (ver{' '}
                <Link to="/manual/bistro/menus">Menús</Link>). Cada ítem de receta apunta a un insumo del
                inventario con una cantidad. Para que el descuento automático funcione:
            </p>
            <ol>
                <li>El insumo debe existir en inventario y tener stock mayor a cero.</li>
                <li>La receta del plato debe tener ese insumo con la cantidad correcta.</li>
                <li>
                    El KDS debe marcar el plato como "listo" — el descuento se hace en ese momento, no cuando
                    llega el pedido.
                </li>
            </ol>
            <p>
                Si el stock de un ingrediente llega a cero y un plato lo requiere, bistro flexy marca ese plato
                como <strong>agotado</strong> en el menú público automáticamente.
            </p>

            <h2>Filtros y búsqueda</h2>
            <p>
                Desde la lista de inventario puedes filtrar por:
            </p>
            <ul>
                <li>
                    <strong>Bodega</strong> — para ver solo los insumos de cocina, solo los de barra, etc.
                </li>
                <li>
                    <strong>Estado</strong> — activos o archivados.
                </li>
                <li>
                    <strong>Búsqueda por nombre.</strong>
                </li>
            </ul>

            <h2>Quién puede hacer qué</h2>
            <table>
                <thead>
                    <tr>
                        <th>Acción</th>
                        <th>Quién la hace por defecto</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Ver inventario y movimientos</td>
                        <td>Propietario, Administrador, Bodeguero, Gerente.</td>
                    </tr>
                    <tr>
                        <td>Crear y editar insumos</td>
                        <td>Propietario, Administrador, Bodeguero.</td>
                    </tr>
                    <tr>
                        <td>Registrar movimientos (entrada / merma / ajuste)</td>
                        <td>Propietario, Administrador, Bodeguero.</td>
                    </tr>
                    <tr>
                        <td>Transferir entre bodegas</td>
                        <td>Propietario, Administrador, Bodeguero.</td>
                    </tr>
                    <tr>
                        <td>Archivar insumos</td>
                        <td>Propietario, Administrador, Bodeguero.</td>
                    </tr>
                </tbody>
            </table>

            <div className="callout callout-success">
                <p>
                    <strong>Rutina recomendada:</strong> haz un conteo físico una vez por semana y compáralo
                    con el sistema. Si hay diferencia, registra un ajuste con motivo "conteo físico MM/DD".
                    Así el historial queda limpio y puedes detectar mermas sistemáticas antes de que se vuelvan
                    un problema.
                </p>
            </div>
        </ManualLayout>
    );
}
