import ManualLayout from '@/layouts/manual-layout';
import { Link } from 'react-router-dom';

export default function ManualAlertas() {
    return (
        <ManualLayout
            currentSlug="alertas"
            pageTitle="Alertas"
            pageDescription="Avisos accionables sobre stock, costos, margen, popularidad, mora con flexyflow y emisión electrónica DIAN. El sistema vigila tu operación y te dice qué revisar primero."
            metaTitle="Alertas — Manual bistro.flexyflow.co"
            metaDescription="Avisos accionables cuando un insumo se acaba, un costo se dispara, un plato deja de ser rentable, una factura entra en mora o la DIAN rechaza una emisión electrónica."
            sectionLabel="números y reportes"
            readingTime="6 min"
        >
            <h2>Para qué sirven</h2>
            <p>
                Las alertas vigilan tu inventario, tus costos, la rentabilidad de cada plato, la popularidad de
                la carta, la mora con tu suscripción a flexyflow y la emisión electrónica DIAN. Cuando algo se
                sale del rango sano, te aparece una <strong>alerta accionable</strong> en el panel de inicio con
                la descripción y un enlace al sitio donde actuar.
            </p>
            <p>
                No se automatiza nada — el sistema te dice qué pasa, tú decides qué hacer. Es una segunda opinión
                que mira números que tú no tienes tiempo de mirar todos los días.
            </p>

            <h2>Alertas operativas</h2>

            <h3>Stock bajo</h3>
            <p>
                Cuando un insumo del inventario está por debajo de su stock mínimo, o cuando llegó a 0. La
                severidad cambia según qué tan apretado estás:
            </p>
            <ul>
                <li>
                    <strong>Crítica:</strong> stock en cero. Si vendes platos que usen ese insumo, te tocaría
                    agotarlos en la carta hasta que llegue la reposición.
                </li>
                <li>
                    <strong>Advertencia:</strong> bajaste del mínimo pero todavía tienes margen de un par de turnos.
                </li>
            </ul>
            <p>
                El mínimo se define <strong>por bodega</strong>. Si tienes cocina, barra y bodega seca por
                separado, cada una lleva su propio umbral. La alerta se prende si <em>cualquiera</em> de las
                bodegas activas se va por debajo del suyo.
            </p>

            <h3>Subida de costo de un insumo</h3>
            <p>
                Cuando un insumo te empezó a costar más caro que antes. Por ejemplo:{' '}
                <em>"El queso mozzarella subió 15% en los últimos 7 días"</em>. Útil para sentarte a negociar
                con el proveedor o ajustar el precio del plato antes de perder margen en silencio.
            </p>
            <p>
                El sistema usa el costo promedio ponderado (WAC) de tus compras reales — no precios de lista.
            </p>

            <h3>Margen bajo (plato que dejó de ser rentable)</h3>
            <p>
                Cuando un plato cae por debajo del margen mínimo. Por ejemplo:{' '}
                <em>"La Bandeja Paisa tiene margen de 22% (umbral: 30%)"</em>. Suele pasar después de una subida
                de costo del insumo.
            </p>
            <ul>
                <li>
                    <strong>Crítica:</strong> el margen quedó muy por debajo del umbral — el plato puede estar
                    dejándote pérdida en vez de utilidad.
                </li>
                <li>
                    <strong>Advertencia:</strong> está por debajo del mínimo sano pero todavía deja algo.
                </li>
            </ul>
            <p>
                La alerta te lleva directo al plato en{' '}
                <Link to="/manual/bistro/menus">menús</Link> para que decidas: subes el precio, cambias un
                ingrediente, renegocias con el proveedor o lo sacas de la carta.
            </p>

            <h3>Plato sin ventas</h3>
            <p>
                Cuando un plato del menú activo no se ha vendido en varios días (típicamente 14). Te ayuda a
                tomar decisiones: lo quitas de la carta para no inflarla, le bajas el precio, lo promocionas en
                redes, o lo dejas en borrador por si quieres reactivarlo más adelante.
            </p>

            <h2>Alertas administrativas</h2>

            <h3>Pagos vencidos con flexyflow</h3>
            <p>
                Si una factura de tu suscripción al panel quedó vencida, te aparece un aviso en la parte superior
                de la app. Es informativo, no bloquea — tu operación sigue funcionando con normalidad mientras se
                resuelve el pago.
            </p>
            <ul>
                <li>
                    <strong>Naranja:</strong> mora reciente, una factura vencida.
                </li>
                <li>
                    <strong>Rojo:</strong> mora prolongada, dos o más facturas vencidas. El equipo comercial de
                    flexyflow está al tanto y se pone en contacto.
                </li>
            </ul>
            <p>
                Si la mora se prolonga varios meses, la cuenta puede pasar a modo solo-lectura mientras se
                regulariza. Detalles en <Link to="/manual/bistro/facturacion">facturación</Link>.
            </p>

            <h3>Fallos de emisión electrónica DIAN</h3>
            <p>
                Si una factura electrónica o un documento equivalente POS quedó rechazado por la DIAN, o si el
                proveedor tecnológico no respondió, aparece un aviso para que el cajero o el administrador entre
                y reintente o corrija los datos.
            </p>
            <p>
                También avisa cuando una <strong>resolución DIAN</strong> está próxima a vencerse o se está
                acabando el rango de numeración autorizado.
            </p>

            <h2>Cómo se ven las alertas</h2>
            <p>
                En el <strong>panel de inicio</strong> aparece un bloque "Alertas" con todas las activas,
                ordenadas por severidad (críticas primero). Cada alerta te muestra:
            </p>
            <ul>
                <li>
                    <strong>Icono y color</strong> según severidad (rojo crítica, ámbar advertencia, azul
                    informativa).
                </li>
                <li>
                    <strong>Descripción legible</strong> ("La Bandeja Paisa tiene margen de 22%", "El queso
                    mozzarella subió 15% en 7 días").
                </li>
                <li>
                    <strong>Enlace directo</strong> al sitio donde actuar.
                </li>
                <li>
                    <strong>Botón "Descartar"</strong> — la quitas porque ya la viste y no quieres actuar.
                </li>
                <li>
                    <strong>Botón "Marcar revisado"</strong> — la quitas con una nota. Queda en auditoría.
                </li>
            </ul>

            <h2>Cómo se generan</h2>
            <p>
                Un proceso automático corre cada noche a las 5 AM y evalúa las cuatro reglas operativas (stock
                bajo, subida de costo, margen bajo, plato sin ventas) sobre los datos del día. Si hay alertas
                nuevas, las suma al panel. Si una alerta del día anterior sigue activa, se actualiza (no se
                duplica).
            </p>
            <p>
                Las alertas de mora con flexyflow y de emisión DIAN no esperan al cron: se prenden en tiempo
                casi-real cuando ocurre el evento.
            </p>

            <div className="callout callout-info">
                <p>
                    <strong>Pendiente para más adelante:</strong> avisos por WhatsApp o correo cuando aparezca una
                    alerta crítica, reglas personalizadas y la posibilidad de automatizar acciones (subir precios,
                    retirar platos automáticamente). Por ahora la decisión sigue siendo humana — es un riesgo
                    demasiado alto para delegar al sistema.
                </p>
            </div>
        </ManualLayout>
    );
}
