import ManualLayout from '@/layouts/manual-layout';
import { Link } from 'react-router-dom';

export default function ManualFacturacion() {
    return (
        <ManualLayout
            currentSlug="facturacion"
            pageTitle="Facturación"
            pageDescription="Acá conviven dos facturaciones: la suscripción que tu negocio le paga a flexyflow y la facturación electrónica DIAN que tu negocio le emite a sus clientes finales."
            metaTitle="Facturación — Manual bistro.flexyflow.co"
            metaDescription="Dos cosas distintas: lo que le pagas a flexyflow por usar el panel (suscripción mensual) y las facturas electrónicas que tu negocio le emite a sus clientes ante la DIAN."
            sectionLabel="números y reportes"
            readingTime="8 min"
        >
            <div className="callout callout-info">
                <p>
                    <strong>Acá conviven dos facturaciones distintas — no las revuelvas:</strong>
                </p>
                <ul>
                    <li>
                        <strong>La de flexyflow:</strong> lo que tu negocio le paga al panel cada mes por usar el
                        servicio. Una sola factura mensual.
                    </li>
                    <li>
                        <strong>La electrónica DIAN:</strong> las facturas que tu negocio le emite a sus clientes
                        finales. Una por venta (o un documento equivalente POS).
                    </li>
                </ul>
            </div>

            <h2>Suscripción a flexyflow</h2>
            <p>Lo que pagas para tener el panel funcionando. Una sola suscripción activa por negocio.</p>

            <h3>Plan activo</h3>
            <p>En <strong>facturación</strong> ves la suscripción actual con:</p>
            <ul>
                <li>
                    <strong>Plan contratado.</strong> Hoy todos los negocios arrancan en el{' '}
                    <em>Plan Default</em> ($100.000 COP/mes, IVA 19% incluido). Si tu asesor te configuró un
                    descuento por código promocional, lo ves marcado con un ícono de porcentaje.
                </li>
                <li>
                    <strong>Precio</strong> y ciclo de facturación (mensual).
                </li>
                <li>
                    <strong>Estado:</strong> activa, pausada o cancelada.
                </li>
                <li>
                    <strong>Período actual</strong> (las fechas que cubre la próxima factura).
                </li>
                <li>
                    <strong>Fecha de la próxima factura.</strong> Las facturas se generan automáticamente el{' '}
                    <strong>día 1 de cada mes a las 3 de la madrugada</strong> hora Colombia (post-pago: se factura
                    el mes que acaba de pasar).
                </li>
            </ul>

            <h3>Aviso de pagos pendientes</h3>
            <p>Cuando tienes facturas vencidas, aparece un aviso en la parte superior de la app:</p>
            <ul>
                <li>
                    <strong>Naranja</strong> — mora reciente, una factura vencida.
                </li>
                <li>
                    <strong>Rojo</strong> — mora prolongada, dos o más facturas vencidas.
                </li>
            </ul>
            <p>
                <strong>El aviso es informativo — no te bloquea de inmediato.</strong> Si la mora se extiende
                varios meses (típicamente 3), la cuenta puede pasar a <em>solo lectura</em>: sigues entrando y
                consultando, pero no puedes operar hasta regularizar.
            </p>

            <h3>Cómo registrar un pago</h3>
            <p>
                Desde la sección <strong>comprobantes de pago</strong> subes el soporte del pago (transferencia
                bancaria, consignación) en JPG, PNG o PDF. El equipo de flexyflow lo revisa y aplica el pago a
                la factura correspondiente.
            </p>

            <h3>Historial de facturas</h3>
            <table>
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Período</th>
                        <th>Monto</th>
                        <th>Vencimiento</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Mensual o Prorrateo</td>
                        <td>Mes facturado</td>
                        <td>Valor (con icono % si tiene descuento)</td>
                        <td>Fecha límite</td>
                        <td>Pendiente, Pagada o Vencida</td>
                        <td>Ver / Descargar PDF</td>
                    </tr>
                </tbody>
            </table>

            <h3>Tipos de factura</h3>
            <ul>
                <li>
                    <strong>Mensual</strong> — la factura normal del mes corrido.
                </li>
                <li>
                    <strong>Prorrateo</strong> — cuando tu suscripción empezó a mediados del mes.
                </li>
                <li>
                    <strong>Nota de crédito</strong> — anulación de una factura previa. Las facturas pagadas o
                    anuladas son inmutables: nunca se editan, todo ajuste queda como un movimiento nuevo.
                </li>
            </ul>

            <h3>Códigos promocionales</h3>
            <p>
                Si tienes un código promocional, lo aplicas desde la misma página de facturación. Antes de
                aplicarlo, el sistema te muestra una <strong>vista previa</strong> con el descuento, los meses que
                cubre y el ahorro mensual estimado.
            </p>

            <div className="callout callout-warn">
                <p>
                    <strong>Importante:</strong> el cambio de plan o la gestión comercial avanzada se manejan con
                    el equipo de flexyflow, no desde la app.
                </p>
            </div>

            <h2>Facturación electrónica DIAN</h2>
            <p>
                Aquí ya no estamos hablando de lo que pagas tú: estamos hablando de los documentos que{' '}
                <strong>tu negocio le emite a sus clientes</strong> y reporta ante la DIAN.
            </p>

            <h3>Tipos de documento que emite</h3>
            <ul>
                <li>
                    <strong>Documento equivalente POS (DEE POS):</strong> el tradicional "tirilla". Es lo que sale
                    por defecto cuando el cliente paga en caja sin pedir factura. Lleva un código único llamado{' '}
                    <strong>CUDE</strong>.
                </li>
                <li>
                    <strong>Factura electrónica de venta (FEV):</strong> cuando el cliente sí pide factura con sus
                    datos. Lleva un código único llamado <strong>CUFE</strong>.
                </li>
                <li>
                    <strong>Notas crédito</strong> — para anular o devolver cualquiera de las dos.{' '}
                    <strong>Nunca</strong> se edita un documento ya emitido — todo cambio queda trazable.
                </li>
                <li>
                    <strong>Notas débito</strong> — para aumentar el valor de una factura ya emitida.
                </li>
            </ul>

            <h3>Resoluciones DIAN</h3>
            <p>
                Para emitir cualquiera de los anteriores, tu negocio necesita al menos{' '}
                <strong>dos resoluciones activas</strong> autorizadas por la DIAN: una para POS y otra para
                factura electrónica. Cada resolución viene con un prefijo, un rango de numeración y una clave
                técnica.
            </p>
            <p>
                Si una resolución se está agotando o por vencerse, te llega una{' '}
                <Link to="/manual/bistro/alertas">alerta</Link> para que pidas la siguiente con anticipación.
            </p>

            <h3>Dónde quedan guardados</h3>
            <p>
                Cada documento emitido guarda su XML y su PDF (con código QR y CUFE/CUDE) en almacenamiento
                seguro. Los conservas por <strong>5 años</strong> si tu negocio es persona natural o{' '}
                <strong>10 años</strong> si es persona jurídica — son los plazos de la DIAN. El sistema no
                permite borrarlos antes de plazo.
            </p>

            <div className="callout callout-success">
                <p>
                    <strong>En resumen:</strong> la <em>suscripción a flexyflow</em> es una factura mensual que tú
                    recibes y pagas. La <em>facturación electrónica DIAN</em> son miles de documentos pequeños que
                    tu negocio emite cada mes a sus clientes. La primera la gestionas con tu asesor; la segunda
                    corre sola en caja una vez que la dejas configurada.
                </p>
            </div>
        </ManualLayout>
    );
}
