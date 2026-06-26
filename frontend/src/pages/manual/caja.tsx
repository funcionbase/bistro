import ManualLayout from '@/layouts/manual-layout';

export default function ManualCaja() {
    return (
        <ManualLayout
            currentSlug="caja"
            pageTitle="Caja y cobros"
            pageDescription="El POS integrado: abrir turno, tomar pedidos, cobrar con múltiples métodos de pago, propina, cupones, devoluciones, egresos e impresoras térmicas."
            metaTitle="Caja y cobros — Manual bistro.flexyflow.co"
            metaDescription="Manual de la caja POS de bistro.flexyflow.co: turnos, cobros divididos, devoluciones, egresos, impresoras USB y red, y modo sin conexión."
            sectionLabel="el día a día"
            readingTime="10 min"
        >
            <h2>Abrir el turno</h2>
            <p>
                Antes de cobrar el primer pedido, el cajero abre el turno desde{' '}
                <strong>caja → abrir turno</strong>. Ingresa el fondo inicial (el efectivo que arrancó en caja).
                En una misma sede solo puede haber un turno abierto a la vez — todos los cajeros operan la misma
                sesión.
            </p>

            <div className="callout callout-info">
                <p>
                    <strong>¿Cuánto fondo inicial?</strong> Típicamente es la plata que tienes en el cajón para dar
                    vueltas. No hay un monto mínimo — puede ser $0 si no recibes efectivo.
                </p>
            </div>

            <h2>Tomar un pedido desde la caja</h2>
            <p>
                En la vista de caja tienes acceso al menú activo. Agregas los platos al carrito, buscas al cliente
                por teléfono (o creas uno nuevo) y confirmas el pedido. El pedido entra al tablero de pedidos como
                cualquier otro y la comanda se manda a la cocina.
            </p>

            <h2>Cobrar un pedido</h2>
            <p>
                Cuando el pedido está listo, vas a <strong>cobrar</strong> y ves el detalle del pedido: ítems,
                subtotal, impuesto, total.
            </p>

            <h3>Métodos de pago</h3>
            <ul>
                <li>
                    <strong>Efectivo:</strong> ingresas cuánto recibiste y el sistema calcula el vuelto. La plata
                    queda registrada en el turno.
                </li>
                <li>
                    <strong>Datáfono (tarjeta):</strong> ingresas la referencia de la transacción del datáfono.
                    Sin referencia no deja cerrar.
                </li>
                <li>
                    <strong>Transferencia / Nequi / DaviPlata:</strong> ingresas el número de confirmación de la
                    transferencia.
                </li>
                <li>
                    <strong>Pago dividido:</strong> el cliente paga parte en efectivo y parte con tarjeta, por
                    ejemplo. Le asignas montos a cada método hasta completar el total.
                </li>
            </ul>

            <h3>Propina</h3>
            <p>
                Antes de cerrar el cobro, hay campos de propina con sugerencias rápidas (10%, 15%, 20%) o monto
                libre. La propina se registra aparte — no entra a la base gravable, no genera impuesto y no cuenta
                como ingreso del negocio en los reportes financieros. Sí aparece en el desglose por método de pago
                al cerrar caja.
            </p>

            <h3>Cupones</h3>
            <p>
                Si el cliente tiene un cupón, lo ingresas en el campo correspondiente antes de cerrar. El sistema
                verifica la validez, aplica el descuento y recalcula el total. Solo un cupón por pedido.
            </p>

            <h2>Devoluciones (refunds)</h2>
            <p>
                Desde cualquier pedido cobrado puedes hacer una devolución total o parcial:
            </p>
            <ul>
                <li>
                    <strong>Total:</strong> devuelve el monto completo del pedido en el mismo método de pago.
                </li>
                <li>
                    <strong>Parcial:</strong> seleccionas los ítems específicos que se devuelven.
                </li>
            </ul>
            <p>
                La devolución crea un nuevo comprobante con monto negativo — el pedido original queda intacto.
                Para tarjeta o transferencia siempre se exige la referencia del comprobante de devolución.
            </p>

            <div className="callout callout-warn">
                <p>
                    <strong>Efectivo vs digital:</strong> en efectivo la devolución se ejecuta al instante (el
                    cajero saca la plata del cajón). En tarjeta o transferencia es responsabilidad del operador
                    gestionar el reembolso con el banco o plataforma — bistro flexy solo lo registra.
                </p>
            </div>

            <h2>Egresos de caja</h2>
            <p>
                Los gastos que salen del cajón durante el turno (compra de cambio, pago de proveedor en efectivo,
                etc.) se registran como <strong>egresos</strong>. Cada egreso lleva: monto, motivo y quién lo
                autorizó. Quedan en el historial del turno y afectan el balance de cierre.
            </p>

            <h2>Cerrar el turno</h2>
            <p>
                Al final del día (o del turno) el cajero va a <strong>caja → cerrar turno</strong>. El sistema
                muestra el total esperado por método de pago según los cobros del turno. El cajero ingresa cuánto
                contó físicamente en efectivo. La diferencia queda registrada (positiva = sobrante, negativa =
                faltante).
            </p>
            <p>
                El cierre es <strong>inmutable</strong> — una vez cerrado no se puede reabrir. Si hay que
                corregir algo, se hace con un egreso o un cobro nuevo en el siguiente turno.
            </p>

            <h2>Impresoras térmicas</h2>
            <p>
                bistro flexy se conecta con impresoras térmicas ESC/POS por:
            </p>
            <ul>
                <li>
                    <strong>USB:</strong> directo desde el navegador (Chrome en escritorio). Sin drivers especiales.
                </li>
                <li>
                    <strong>Red (IP):</strong> a través del agente local de bistro flexy instalado en la computadora
                    del local. El agente es un pequeño programa que vive en segundo plano y recibe las comandas
                    del panel por websocket.
                </li>
            </ul>
            <p>
                Se configuran en <em>configuración → impresoras</em>. Cada impresora puede ser de tipo: cocina,
                barra, recibos. Las de cocina y barra reciben comandas por categoría del plato; las de recibos
                imprimen el tiquete del cliente al cerrar el cobro.
            </p>

            <h2>Modo sin conexión</h2>
            <p>
                Si se va el internet en pleno turno, la caja sigue funcionando en{' '}
                <strong>modo sin conexión</strong>. Los pedidos y cobros quedan en cola en el navegador y se
                sincronizan automáticamente cuando vuelve la conexión. Está pensado para horas sin internet, no
                para días enteros.
            </p>

            <div className="callout callout-info">
                <p>
                    <strong>Precaución con el offline:</strong> si el internet no vuelve antes del cierre de turno,
                    exporta las pendientes a JSON desde la vista de caja para que no se pierda ningún cobro. El
                    JSON lo puedes importar manualmente cuando se restablezca la conexión.
                </p>
            </div>

            <h2>Recibos</h2>
            <p>
                Después de cobrar, puedes:
            </p>
            <ul>
                <li>Imprimir el recibo en la térmica.</li>
                <li>Enviarle el recibo al cliente por WhatsApp (si tienes WhatsApp conectado).</li>
                <li>
                    Emitir la factura electrónica DIAN si el cliente la pide con sus datos fiscales. Ver{' '}
                    <span className="font-medium">facturación</span>.
                </li>
            </ul>

            <p>
                En caja también puedes agregar el nombre y la{' '}
                <strong>cédula / NIT del cliente</strong> para emitir la factura con sus datos. Si el cliente ya
                tiene datos guardados (porque compró antes), se precargan automáticamente.
            </p>

            <div className="callout callout-warn">
                <p>
                    El color{' '}
                    <span style={{ color: 'var(--primary)' }}>Verde</span> en el indicador de estado de impresora
                    significa que la impresora está lista. Si está en gris, revisa la conexión USB o la IP de red
                    antes de abrir el turno.
                </p>
            </div>
        </ManualLayout>
    );
}
