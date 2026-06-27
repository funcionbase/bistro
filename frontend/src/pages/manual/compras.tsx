import ManualLayout from '@/layouts/manual-layout';
import { Link } from 'react-router-dom';

export default function ManualCompras() {
    return (
        <ManualLayout
            currentSlug="compras"
            pageTitle="Compras y proveedores"
            pageDescription="Órdenes de compra de insumos con trazabilidad completa: desde el borrador hasta el pago, con adjuntos y vínculo al inventario. Gestión del catálogo de proveedores."
            metaTitle="Compras y proveedores — Manual bistro.flexyflow.co"
            metaDescription="Cómo gestionar compras de insumos y proveedores en bistro.flexyflow.co: órdenes de compra, estados, adjuntos, registro de pago y vínculo con inventario."
            sectionLabel="administración"
            readingTime="7 min"
        >
            <h2>Proveedores</h2>
            <p>
                Antes de crear órdenes de compra, registra tus proveedores en{' '}
                <strong>compras → proveedores</strong>. Cada proveedor tiene nombre, NIT/cédula, teléfono y
                correo. Son la "lista de contactos" de tus vendedores habituales (distribuidoras, fruterías,
                carnicerías, proveedores de empaque, etc.).
            </p>
            <p>
                Crear el proveedor es un paso de un minuto y hace que las órdenes de compra queden vinculadas
                al contacto correcto — lo cual agiliza el historial de compras por proveedor.
            </p>

            <h2>Órdenes de compra</h2>
            <p>
                Una <strong>orden de compra</strong> (OC) documenta qué compraste, a quién, en qué cantidad y
                a qué precio. Vive en <strong>compras → órdenes de compra</strong>.
            </p>

            <h3>Crear una OC</h3>
            <ol>
                <li>Dale a <kbd>Nueva orden</kbd>.</li>
                <li>Elige el proveedor y la fecha esperada de entrega.</li>
                <li>
                    Agrega los ítems: cada ítem apunta a un insumo del inventario, con cantidad y precio
                    unitario.
                </li>
                <li>Guarda como borrador o envía directo al estado "pendiente".</li>
            </ol>

            <h3>Estados de la OC</h3>
            <table>
                <thead>
                    <tr>
                        <th>Estado</th>
                        <th>Qué significa</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <strong>Borrador</strong>
                        </td>
                        <td>
                            Se puede editar. Aún no se ha enviado al proveedor ni afecta el inventario.
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Pendiente</strong>
                        </td>
                        <td>Enviada al proveedor. Se espera la entrega.</td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Recibida</strong>
                        </td>
                        <td>
                            La mercancía llegó. Al marcar como recibida, el sistema ofrece registrar la entrada
                            de stock en el inventario automáticamente.
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Pagada</strong>
                        </td>
                        <td>
                            Se confirmó el pago al proveedor. Estado final positivo. Lleva monto pagado y
                            método.
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Cancelada</strong>
                        </td>
                        <td>Se anuló antes de recibir la mercancía.</td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Anulada</strong>
                        </td>
                        <td>
                            Revocada después de recibida o pagada. Requiere justificación. El movimiento de
                            inventario asociado NO se revierte automáticamente — debes hacer un ajuste manual si
                            la mercancía fue devuelta.
                        </td>
                    </tr>
                </tbody>
            </table>

            <div className="callout callout-info">
                <p>
                    <strong>Entrada de inventario automática:</strong> cuando marcas la OC como "recibida",
                    bistro flexy te ofrece registrar la entrada de stock en{' '}
                    <Link to="/manual/bistro/inventario">inventario</Link> por cada ítem de la OC. Puedes
                    aceptarla tal cual o ajustar cantidades si la entrega fue parcial. Si prefieres registrar
                    la entrada a mano, también puedes hacerlo desde inventario directamente.
                </p>
            </div>

            <h2>Adjuntos</h2>
            <p>
                Cada OC tiene un panel de adjuntos donde puedes subir la factura del proveedor, la remisión,
                el soporte de pago u otros documentos. Admite PDF, JPG y PNG. Los adjuntos quedan vinculados
                a la OC para auditoría.
            </p>

            <div className="callout callout-warn">
                <p>
                    <strong>DIAN — conservación de facturas de compra:</strong> las facturas de proveedor son
                    soportes contables. La ley colombiana exige conservarlas <strong>5 años</strong> (personas
                    naturales) o <strong>10 años</strong> (jurídicas). Subelas al panel de adjuntos de la OC
                    para no perder el respaldo.
                </p>
            </div>

            <h2>KPIs del encabezado</h2>
            <p>
                Al tope de la página de compras ves tres indicadores en tiempo real:
            </p>
            <ul>
                <li>
                    <strong>Total de OCs:</strong> cuántas órdenes hay en el período.
                </li>
                <li>
                    <strong>Borradores pendientes:</strong> OCs sin enviar al proveedor.
                </li>
                <li>
                    <strong>Valor pendiente de pago:</strong> suma de OCs recibidas pero aún sin pagar.
                </li>
            </ul>
            <p>
                Puedes filtrar la lista por estado (borrador, pendiente, recibida, pagada, cancelada, anulada)
                y buscar por proveedor o número de OC.
            </p>

            <h2>Cómo fluye una compra típica</h2>
            <ol>
                <li>
                    <strong>Creas la OC en borrador</strong> (lunes cuando haces el pedido al proveedor por
                    teléfono).
                </li>
                <li>
                    <strong>La mandas a "pendiente"</strong> cuando confirmas el pedido al proveedor (ya saben
                    que llega el miércoles).
                </li>
                <li>
                    <strong>El miércoles llega la mercancía</strong> — la marcas "recibida" y confirmas la
                    entrada de stock.
                </li>
                <li>
                    <strong>Subes la factura</strong> al panel de adjuntos.
                </li>
                <li>
                    <strong>Pagas al proveedor</strong> y la marcas "pagada" con el monto y el método
                    (efectivo, transferencia).
                </li>
            </ol>

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
                        <td>Ver órdenes de compra</td>
                        <td>Propietario, Administrador, Bodeguero, Contador.</td>
                    </tr>
                    <tr>
                        <td>Crear y editar borradores</td>
                        <td>Propietario, Administrador, Bodeguero.</td>
                    </tr>
                    <tr>
                        <td>Marcar como recibida o cancelada</td>
                        <td>Propietario, Administrador, Bodeguero.</td>
                    </tr>
                    <tr>
                        <td>Marcar como pagada</td>
                        <td>Propietario, Administrador (Bodeguero no puede pagar por defecto).</td>
                    </tr>
                    <tr>
                        <td>Anular una OC</td>
                        <td>Solo Propietario y Administrador.</td>
                    </tr>
                    <tr>
                        <td>Gestionar proveedores</td>
                        <td>Propietario, Administrador, Bodeguero.</td>
                    </tr>
                </tbody>
            </table>
        </ManualLayout>
    );
}
