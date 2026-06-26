import ManualLayout from '@/layouts/manual-layout';

export default function ManualMenus() {
    return (
        <ManualLayout
            currentSlug="menus"
            pageTitle="Menús"
            pageDescription="Cómo crear y publicar tu carta, editar platos, subir fotos, definir recetas para el inventario, configurar el KDS y generar el QR."
            metaTitle="Menús — Manual bistro.flexyflow.co"
            metaDescription="Cómo crear categorías, platos, recetas, fotos, menú público, QR y configurar el KDS de cocina en bistro.flexyflow.co."
            sectionLabel="el día a día"
            readingTime="9 min"
        >
            <h2>La lista de menús</h2>
            <p>
                Un negocio puede tener varios menús (carta de almuerzo, carta de cena, menú de temporada). Solo uno
                puede estar <strong>publicado</strong> (activo y visible al cliente) a la vez. Los demás quedan como
                borradores — los editas sin que el cliente los vea y los publicas cuando los necesites.
            </p>
            <p>
                Desde la lista puedes crear un menú nuevo, ver cuál está publicado y cambiar cuál se activa. Al
                publicar un borrador, el anterior queda como borrador automáticamente — nunca queda el negocio sin
                carta.
            </p>

            <h2>El editor de menú</h2>
            <p>
                Adentro del menú organizas <strong>categorías</strong> y dentro de cada categoría van los{' '}
                <strong>platos</strong> (o ítems). El orden de las categorías y de los platos dentro de cada una
                se cambia arrastrando.
            </p>

            <h3>Categorías</h3>
            <ul>
                <li>Nombre y descripción opcional.</li>
                <li>
                    <strong>Disponibilidad:</strong> siempre disponible, o solo en ciertas horas (útil para
                    desayunos o menú ejecutivo).
                </li>
                <li>
                    <strong>Estación de cocina (KDS):</strong> qué pantalla de cocina prepara esta categoría.
                    Puedes tener "cocina caliente" para platos y "barra" para bebidas, por ejemplo.
                </li>
            </ul>

            <h3>Platos</h3>
            <ul>
                <li>Nombre, descripción, precio.</li>
                <li>
                    <strong>Impuesto específico:</strong> si este plato tiene un régimen distinto al del negocio
                    (por ejemplo, la cerveza lleva IVA 19% mientras el resto lleva INC 8%).
                </li>
                <li>
                    <strong>Estado:</strong> disponible, agotado o borrador. El agotado lo ve el cliente pero
                    sin poder pedirlo; el borrador no se muestra.
                </li>
                <li>
                    <strong>Receta:</strong> lista de ingredientes con cantidades. Cuando se marca listo en
                    cocina, el inventario se descuenta automáticamente.
                </li>
            </ul>

            <h2>Fotografías</h2>
            <p>
                Cada plato acepta hasta 5 fotos (JPG, PNG, WEBP, máximo 5 MB cada una). La primera foto es la
                portada. Arrastrar para reordenar.
            </p>
            <p>
                Las fotos se sirven optimizadas desde el CDN — no tienes que preocuparte por el tamaño que sube el
                cliente en su celular.
            </p>

            <h2>Límites</h2>
            <ul>
                <li>Máximo 10 categorías por menú.</li>
                <li>Máximo 50 platos por categoría.</li>
                <li>Máximo 500 platos en total por menú.</li>
            </ul>

            <h2>Menú público</h2>
            <p>
                El menú publicado queda disponible en la página de pedidos de tu negocio. Cualquier cliente puede
                verlo sin iniciar sesión. El color de los botones y el logo son los que configuraste en{' '}
                <em>configuración → información del negocio</em>.
            </p>
            <p>
                Los platos agotados se muestran tachados. Los borradores no aparecen. Si el negocio está cerrado
                (según los horarios configurados), la página avisa y no deja agregar al carrito.
            </p>

            <h2>Estaciones KDS</h2>
            <p>
                Cada categoría del menú puede ir a una estación de cocina diferente. Cuando la orden entra,
                bistro flexy separa los ítems por estación y manda cada parte a la pantalla correcta. El cocinero
                ve solo lo suyo, el bartender solo lo de barra.
            </p>

            <h3>Configuración de estaciones</h3>
            <p>
                Las estaciones se configuran en <em>configuración → KDS</em>. Al crear el negocio se siembran
                automáticamente según la vertical (restaurante, bar, cafetería, dark kitchen). Puedes renombrarlas,
                cambiarles el color y añadir o eliminar estaciones según lo que tenga tu local.
            </p>

            <h2>QR del menú</h2>
            <p>
                En <em>menús → QR</em> generas el código QR que apunta al menú público de tu negocio. Puedes
                descargarlo en PNG o SVG para imprimirlo en mesas, carteleras o empaques.
            </p>
            <p>
                El QR nunca cambia aunque actualices el menú — apunta al negocio, no a un snapshot del menú.
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
                        <td>Ver menú</td>
                        <td>Consultar la carta y sus detalles.</td>
                    </tr>
                    <tr>
                        <td>Crear</td>
                        <td>Agregar categorías, platos, menús nuevos.</td>
                    </tr>
                    <tr>
                        <td>Actualizar</td>
                        <td>Editar precios, fotos, disponibilidad, recetas. Marcar agotados.</td>
                    </tr>
                    <tr>
                        <td>Eliminar</td>
                        <td>Borrar platos o categorías. Solo si no tienen pedidos en curso.</td>
                    </tr>
                    <tr>
                        <td>Publicar</td>
                        <td>Cambiar cuál menú está activo (visible al cliente).</td>
                    </tr>
                </tbody>
            </table>

            <div className="callout callout-info">
                <p>
                    <strong>Tip para empezar:</strong> empieza con 2-3 categorías y los platos más vendidos. Un menú
                    corto bien fotografiado convierte mejor que una carta de 80 platos sin foto.
                </p>
            </div>
        </ManualLayout>
    );
}
