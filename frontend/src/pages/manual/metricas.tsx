import ManualLayout from '@/layouts/manual-layout';
import { Link } from 'react-router-dom';

export default function ManualMetricas() {
    return (
        <ManualLayout
            currentSlug="metricas"
            pageTitle="Métricas"
            pageDescription="Tu cabina de mando: KPIs del día, mapas de calor, ranking de platos, costo de insumos en tiempo real, ingeniería de menú y vista consolidada cuando tienes varias sedes."
            metaTitle="Métricas — Manual bistro.flexyflow.co"
            metaDescription="Ingresos en vivo, ticket promedio, mapas de calor por hora y día, ranking de platos, costo de insumos, ingeniería de menú, abandono de carrito y vista consolidada multi-sede."
            sectionLabel="números y reportes"
            readingTime="8 min"
        >
            <h2>Panel de inicio</h2>
            <p>
                El <strong>panel de control</strong> es la primera pantalla cuando entras a la app. Te muestra
                cómo va tu operación en el período que elijas (hoy, últimos 7 días, mes en curso o un rango
                personalizado).
            </p>

            <h3>KPIs del día</h3>
            <ul>
                <li>
                    <strong>Ingresos del día</strong> con el <strong>ticket promedio</strong> (cuánto gasta en
                    promedio cada cliente). El ticket promedio se calcula solo sobre pedidos que{' '}
                    <em>completaron</em> — no entran cancelados ni abandonados.
                </li>
                <li>
                    <strong>Conteo de pedidos</strong> del día y del período seleccionado.
                </li>
                <li>
                    <strong>Calificación promedio</strong> de tus clientes en el período.
                </li>
                <li>
                    <strong>Pedidos activos</strong> ahora mismo, separados por estado (pendiente, en cocina,
                    listo, en camino). Se refresca solo cada 30 segundos.
                </li>
                <li>
                    <strong>Comparación con el período anterior:</strong> un distintivo verde o rojo te dice si
                    subió o bajó. Si vienes de un negocio sin histórico, el distintivo se oculta.
                </li>
            </ul>

            <h3>Otros paneles del inicio</h3>
            <ul>
                <li>
                    <strong>Mapa de calor por hora:</strong> en qué horas del día se concentran tus pedidos.
                    Sirve para programar turnos.
                </li>
                <li>
                    <strong>Abandono de carrito:</strong> cuántos clientes empezaron un pedido y no lo
                    terminaron, y cuánto dinero estimado dejaste sobre la mesa.
                </li>
                <li>
                    <strong>Resumen de domicilios:</strong> cómo va la operación logística. Solo aparece si
                    tienes permiso para ver entregas.
                </li>
                <li>
                    <strong>Alertas:</strong> ver <Link to="/manual/bistro/alertas">alertas</Link>.
                </li>
            </ul>

            <h2>Página de métricas detallada</h2>
            <p>En <strong>métricas</strong> tienes la vista detallada con filtros de período:</p>
            <ul>
                <li>
                    <strong>Resumen del período:</strong> ingresos, ticket promedio, conteos por estado.
                </li>
                <li>
                    <strong>Mapa de calor por hora del día</strong> (24 buckets).
                </li>
                <li>
                    <strong>Mapa de calor semanal</strong> — los 7 días por las 24 horas. Te dice "los viernes a
                    las 8 PM es nuestra hora pico".
                </li>
                <li>
                    <strong>Ranking de platos:</strong>
                    <ul>
                        <li>Por <em>ingresos</em> (cuáles te dejan más plata).</li>
                        <li>Por <em>cantidad</em> (cuáles se venden más unidades).</li>
                        <li>
                            Por <em>margen</em> — cruza el precio de venta contra el costo del insumo.
                        </li>
                    </ul>
                </li>
                <li>
                    <strong>Abandono de carrito</strong> con tasa de conversión y dinero estimado perdido.
                </li>
                <li>
                    <strong>Escaneos del menú QR:</strong> cuántas veces escanearon el QR de tu menú en el
                    período, cuántos visitantes distintos fueron y cómo se reparte por día. Sirve para saber si
                    el QR de las mesas y los empaques realmente se usa.
                </li>
                <li>
                    <strong>Costo de insumos (food cost) en tiempo real</strong> — qué porcentaje del precio de
                    venta se va en insumos.
                </li>
                <li>
                    <strong>Histórico de costo por plato</strong> — para un ítem específico ves la curva de su
                    food cost en el tiempo.
                </li>
                <li>
                    <strong>Ingeniería de menú</strong> — clasifica tus platos en cuatro cuadrantes:
                    <ul>
                        <li>
                            <strong>Estrellas</strong> — alta popularidad, alto margen. Lo que más cuidas.
                        </li>
                        <li>
                            <strong>Vacas</strong> — alta popularidad, bajo margen. Se venden bien pero te dejan poco.
                        </li>
                        <li>
                            <strong>Acertijos</strong> — baja popularidad, alto margen. Hay que promocionarlos.
                        </li>
                        <li>
                            <strong>Perros</strong> — baja popularidad, bajo margen. Candidatos a sacarlos de la carta.
                        </li>
                    </ul>
                </li>
            </ul>

            <h3>Modo en vivo y caché</h3>
            <p>
                En la página de métricas tienes un interruptor <em>"En vivo"</em>. Cuando lo activas, el panel
                se refresca solo cada minuto. Se apaga solo a los 5 minutos para no consumir de más.
            </p>

            <h3>Vista consolidada si tienes varias sedes</h3>
            <p>
                Si tu operación tiene más de una sede, puedes activar la <strong>vista consolidada</strong> para
                ver los números sumados de todas las sedes al tiempo, o cambiar el selector para mirar solo una.
                La vista consolidada requiere un permiso específico — típicamente la tiene el dueño o el contador.
            </p>

            <h2>Ventas del día</h2>
            <p>
                En <strong>ventas del día</strong> tienes el listado de pedidos del período con filtros por
                estado, paginación y exportación.
            </p>

            <h3>Cierre de caja del día</h3>
            <p>
                Un componente especial te muestra el <strong>cierre de caja por fecha</strong>: para una fecha
                específica o un rango, ves un desglose por método de pago (efectivo, datáfono, transferencia)
                con cobros, devoluciones, neto y propinas.
            </p>

            <h3>Informe de cierre por turno</h3>
            <p>
                Debajo del cierre del día está el <strong>historial de turnos de caja</strong>, agrupado por
                fecha. Cada turno se despliega para ver su arqueo completo: con cuánto fondo abrió, cuánto se
                vendió por cada método de pago, las entradas y salidas de efectivo, cuánto se esperaba en el
                cajón y cuánto contó el cajero al cerrar — con la diferencia (sobrante o faltante) marcada.
            </p>
            <p>
                Es la herramienta para responder "¿por qué no cuadró la caja del martes?" sin llamar al cajero:
                todo el movimiento del turno está ahí, turno por turno, día por día.
            </p>

            <h2>Descargar reportes</h2>
            <ul>
                <li>
                    <strong>Reporte de métricas</strong> en PDF — para imprimir o compartir con un socio.
                </li>
                <li>
                    <strong>Reporte de pedidos</strong> en PDF (resumen con desglose tributario) o en CSV (datos
                    crudos para abrir en Excel).
                </li>
                <li>
                    <strong>Reporte de domiciliarios</strong> en PDF.
                </li>
                <li>
                    <strong>Cierre de caja del día</strong> en PDF.
                </li>
            </ul>
            <p>
                Los reportes en PDF tienen un tope de 500 filas. Para el detalle completo, descarga el CSV de
                pedidos — ese no tiene tope.
            </p>

            <h2>Quién puede ver qué</h2>
            <p>
                Todo el módulo de métricas y reportes requiere el permiso de <strong>ver reportes</strong>. Sin
                ese permiso, los paneles del panel de inicio se ocultan en silencio.
            </p>

            <div className="callout callout-success">
                <p>
                    <strong>Hábito recomendado:</strong> revisa el mapa de calor semanal una vez al mes y la
                    ingeniería de menú cada trimestre. Cualquier cosa que cambies (precios, ingredientes, plato
                    nuevo), vuelve a revisar dos semanas después para ver el impacto.
                </p>
            </div>
        </ManualLayout>
    );
}
