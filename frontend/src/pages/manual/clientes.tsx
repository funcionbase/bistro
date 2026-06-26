import ManualLayout from '@/layouts/manual-layout';

export default function ManualClientes() {
    return (
        <ManualLayout
            currentSlug="clientes"
            pageTitle="Clientes"
            pageDescription="El CRM integrado: lista con filtros, segmentación automática en 6 tipos, ficha del cliente con historial de pedidos, chats, notas privadas, etiquetas y fidelización."
            metaTitle="Clientes — Manual bistro.flexyflow.co"
            metaDescription="El CRM de bistro.flexyflow.co: segmentación automática de clientes, ficha con historial, notas privadas, etiquetas y fidelización consolidada entre sedes."
            sectionLabel="clientes y mercadeo"
            readingTime="8 min"
        >
            <h2>El CRM integrado</h2>
            <p>
                bistro flexy lleva un registro de cada cliente que te ha comprado o que te ha escrito por
                WhatsApp. No tienes que importar nada ni crear fichas a mano — se construyen solas con la
                actividad de la operación.
            </p>
            <p>
                La base de clientes es <strong>a nivel de empresa</strong> (no por sede). Si Laura pide en El
                Poblado y luego en Laureles, es la misma ficha con el historial completo de las dos sedes.
            </p>

            <h2>La lista de clientes</h2>
            <p>
                En <strong>clientes</strong> ves la lista con filtros por:
            </p>
            <ul>
                <li>Nombre o teléfono (búsqueda).</li>
                <li>Segmento (ver abajo).</li>
                <li>Etiquetas personalizadas.</li>
                <li>Fecha de último pedido.</li>
            </ul>

            <h2>Segmentación automática</h2>
            <p>
                El sistema clasifica a cada cliente en uno de seis segmentos según su comportamiento de compra.
                La clasificación se actualiza automáticamente con cada pedido:
            </p>
            <table>
                <thead>
                    <tr>
                        <th>Segmento</th>
                        <th>Qué significa</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>VIP</strong></td>
                        <td>Gasta mucho y compra con frecuencia. Tu cliente más valioso.</td>
                    </tr>
                    <tr>
                        <td><strong>Recurrente</strong></td>
                        <td>Compra seguido aunque no gaste tanto en cada pedido.</td>
                    </tr>
                    <tr>
                        <td><strong>Nuevo</strong></td>
                        <td>Primer pedido hace menos de 30 días.</td>
                    </tr>
                    <tr>
                        <td><strong>En riesgo</strong></td>
                        <td>Antes compraba seguido pero lleva semanas sin aparecer.</td>
                    </tr>
                    <tr>
                        <td><strong>Inactivo</strong></td>
                        <td>No ha comprado en varios meses.</td>
                    </tr>
                    <tr>
                        <td><strong>Regular</strong></td>
                        <td>Compra ocasionalmente, sin un patrón claro.</td>
                    </tr>
                </tbody>
            </table>
            <p>
                Por ejemplo, <strong>Laura</strong> es VIP: lleva 6 meses pidiendo cada semana, siempre pizza
                familiar. <strong>Don Hernán</strong> está "en riesgo": pedía dos veces al mes, pero lleva 45
                días sin comprar.
            </p>

            <h2>La ficha del cliente</h2>
            <p>
                Al entrar a un cliente ves su ficha completa:
            </p>
            <ul>
                <li>
                    <strong>Datos básicos:</strong> nombre, teléfono, correo, cumpleaños (si se capturó),
                    dirección principal.
                </li>
                <li>
                    <strong>Segmento y estadísticas:</strong> total gastado, número de pedidos, ticket promedio,
                    primera y última compra.
                </li>
                <li>
                    <strong>Historial de pedidos:</strong> todos los pedidos con detalle, fecha, sede y monto.
                </li>
                <li>
                    <strong>Chats:</strong> conversaciones de WhatsApp asociadas.
                </li>
                <li>
                    <strong>Fidelización:</strong> saldo de puntos, nivel (bronce/plata/oro), historial de
                    movimientos y canjes.
                </li>
                <li>
                    <strong>Notas privadas:</strong> texto interno que solo ve el equipo. No llega al cliente.
                    Útil para anotaciones ("alérgico a nueces", "siempre pide sin cebolla", "cliente difícil — fue
                    escalado").
                </li>
                <li>
                    <strong>Etiquetas:</strong> categorías libres que le asignas manualmente ("corporativo",
                    "fiel", "influencer", etc.). Sirven para filtrar la lista después.
                </li>
            </ul>

            <h2>Notas privadas</h2>
            <p>
                Las notas son append-only: no se editan, solo se agregan nuevas. Quedan con timestamp y quién
                la escribió. Así el equipo puede hacer seguimiento de situaciones a lo largo del tiempo sin
                perder el contexto.
            </p>

            <h2>Etiquetas</h2>
            <p>
                Creas las etiquetas que necesites. Puedes asignar varias por cliente. Las etiquetas aparecen en
                la lista de clientes y sirven para filtrar. Nadie puede crear etiquetas sin el permiso de
                editar clientes.
            </p>

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
                        <td>Ver clientes</td>
                        <td>Ver la lista, la ficha, el historial y las estadísticas.</td>
                    </tr>
                    <tr>
                        <td>Editar</td>
                        <td>Agregar notas, etiquetas y ajustar la información del cliente.</td>
                    </tr>
                </tbody>
            </table>

            <div className="callout callout-info">
                <p>
                    <strong>Privacidad:</strong> los teléfonos se muestran parcialmente ocultos en algunas vistas
                    para que el personal de caja no pueda copiar la base de datos completa. El propietario y el
                    administrador siempre ven el teléfono completo.
                </p>
            </div>
        </ManualLayout>
    );
}
