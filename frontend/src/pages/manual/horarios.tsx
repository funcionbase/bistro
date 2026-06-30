import ManualLayout from '@/layouts/manual-layout';

export default function ManualHorarios() {
    return (
        <ManualLayout
            currentSlug="horarios"
            pageTitle="Horarios"
            pageDescription="Define cuándo está abierto tu negocio: horario semanal, excepciones por fecha (feriados o eventos) y el estado en vivo de apertura/cierre."
            metaTitle="Horarios — Manual bistro.flexyflow.co"
            metaDescription="Cómo configurar el horario de tu negocio en bistro.flexyflow.co: horario semanal, excepciones por fecha, estado en vivo y efecto en el bot de WhatsApp."
            sectionLabel="el día a día"
            readingTime="5 min"
        >
            <h2>Horario semanal</h2>
            <p>
                En <strong>horarios</strong> configuras para cada día de la semana si el negocio está abierto, y
                de qué hora a qué hora. Puedes tener varios rangos por día (por ejemplo, almuerzo de 12 PM a 3 PM
                y cena de 6 PM a 10 PM).
            </p>
            <p>
                Los días que dejas sin marcar se tratan como cerrado. El cliente en el menú público ve un mensaje
                de "estamos cerrados" y no puede confirmar pedidos.
            </p>

            <div className="callout callout-warn">
                <p>
                    <strong>Sin horario configurado = siempre abierto.</strong> Si nunca configuras horarios, la app
                    asume que estás disponible las 24 horas. Para negocios con horario fijo, configúralo antes de
                    arrancar operaciones para evitar pedidos a deshora.
                </p>
            </div>

            <h2>Excepciones por fecha</h2>
            <p>
                Para fechas específicas donde el horario cambia (feriados, eventos especiales, temporadas altas),
                creas una excepción. La excepción reemplaza el horario semanal ese día.
            </p>
            <ul>
                <li>
                    <strong>Cerrado todo el día:</strong> el negocio no abre aunque sea un día de semana normal.
                    Útil para feriados (20 de julio, 7 de agosto) o vacaciones.
                </li>
                <li>
                    <strong>Horario especial:</strong> el negocio abre en horas distintas ese día. Útil para
                    navidad (abres solo al mediodía), un evento especial (abres más temprano) o un día de
                    mantenimiento (cierras más temprano).
                </li>
            </ul>

            <h2>Estado en vivo: abierto / cerrado</h2>
            <p>
                El sistema calcula en tiempo real si el negocio está abierto o cerrado con base en el horario y
                las excepciones del día. Este estado se refleja en:
            </p>
            <ul>
                <li>
                    <strong>Menú público:</strong> si está cerrado, aparece un aviso y los clientes no pueden
                    agregar al carrito.
                </li>
                <li>
                    <strong>Bot de WhatsApp:</strong> si el cliente escribe fuera de horario, el bot responde con
                    el mensaje de "fuera de horario" que configuraste. No arma pedidos si estás cerrado.
                </li>
                <li>
                    <strong>Panel de inicio:</strong> el dashboard muestra el estado actual del negocio.
                </li>
            </ul>

            <div className="callout callout-info">
                <p>
                    <strong>Zona horaria:</strong> bistro flexy usa la zona horaria de Colombia
                    (America/Bogotá, UTC-5) para todos los cálculos de horario. Si tu negocio está en otra zona
                    horaria (Leticia, San Andrés), el horario se mostrará en hora Bogotá.
                </p>
            </div>

            <h2>Quién puede editar horarios</h2>
            <p>
                Los horarios son configuración del negocio. Pueden editarlos los usuarios con permiso de
                actualización en el módulo de horarios — típicamente el propietario y el administrador. El
                empleado base no tiene este permiso por defecto.
            </p>
        </ManualLayout>
    );
}
