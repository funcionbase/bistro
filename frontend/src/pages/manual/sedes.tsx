import ManualLayout from '@/layouts/manual-layout';
import { Link } from 'react-router-dom';

export default function ManualSedes() {
    return (
        <ManualLayout
            currentSlug="sedes"
            pageTitle="Sedes y bodegas"
            pageDescription="Cada sede de tu operación con su caja, su inventario, su menú, su tablero de pedidos y hasta su propia vertical de negocio. Aislamiento de datos por NIT y por sede."
            metaTitle="Sedes y bodegas — Manual bistro.flexyflow.co"
            metaDescription="Si tu negocio tiene varios locales bajo el mismo NIT, cada sede opera con su caja, su inventario, su menú y sus reportes aparte. Bodegas internas, copia de menú y reportes consolidados."
            sectionLabel="administración"
            readingTime="7 min"
        >
            <h2>¿Quién necesita esto?</h2>
            <p>
                Si manejas un solo local, no te preocupes — bistro flexy crea automáticamente una sede
                "Principal" al darte de alta y todo funciona sin que tengas que pensar en esto. Esta página es
                para:
            </p>
            <ul>
                <li>
                    Cadenas con varios locales bajo el mismo NIT (pizzerías, hamburgueserías, panaderías, bares,
                    sucursales en distintos barrios o ciudades).
                </li>
                <li>
                    Negocios que abren una sede nueva y necesitan separar su operación.
                </li>
                <li>
                    Operaciones con verticales mixtas: una cadena con un restaurante en Laureles y una dark
                    kitchen en El Poblado bajo el mismo NIT.
                </li>
            </ul>

            <h2>Cómo funciona</h2>
            <p>
                Un negocio (un NIT) puede tener <strong>tantas sedes como necesite</strong>. Cada sede tiene su
                propia operación independiente:
            </p>
            <ul>
                <li>
                    Su propia <Link to="/manual/bistro/caja">caja</Link> (los turnos no se cruzan entre sedes).
                </li>
                <li>
                    Su propio <Link to="/manual/bistro/pedidos">tablero de pedidos</Link> y de cocina (KDS).
                </li>
                <li>Su propio inventario, con una o varias bodegas internas.</li>
                <li>Sus propios reportes operativos.</li>
                <li>
                    Su propio menú: lo puedes copiar entre sedes y a partir de ahí cada una lo edita por
                    separado.
                </li>
                <li>
                    Su propia <strong>vertical de negocio</strong>: una sede puede ser restaurante, otra bar,
                    otra cafetería y otra dark kitchen.
                </li>
            </ul>
            <p>
                Comparten a nivel de negocio: la base de clientes (CRM), los cupones, el programa de
                fidelización, el plan contratado, la facturación y la configuración fiscal DIAN.
            </p>

            <h2>Crear y gestionar sedes</h2>
            <p>
                Desde <strong>configuración → sedes</strong> puedes:
            </p>
            <ul>
                <li>
                    <strong>Crear una sede nueva</strong> con nombre, identificador corto, dirección, ciudad y la
                    vertical de negocio que aplique.
                </li>
                <li>
                    <strong>Marcar una como predeterminada</strong> — la que se abre por defecto al entrar al
                    panel.
                </li>
                <li>
                    <strong>Asignar usuarios a cada sede</strong> — un miembro puede pertenecer a varias sedes a
                    la vez.
                </li>
                <li>
                    <strong>Cambiar la vertical</strong> de una sede operativa sin tener que recrearla.
                </li>
                <li>
                    <strong>Archivar</strong> sedes que ya no operan. El sistema no borra: solo las pone en
                    "histórico" para que no se pierdan datos.
                </li>
            </ul>

            <div className="callout callout-warn">
                <p>
                    <strong>Última sede activa:</strong> no puedes archivar la única sede activa que te queda.
                    Un negocio siempre debe tener al menos una sede operando.
                </p>
            </div>

            <h2>Cambio de sede activa</h2>
            <p>
                Si tu cuenta tiene acceso a varias sedes, te aparece un selector en el menú lateral. Lo abres,
                eliges la sede a la que quieres entrar y el panel se recarga con los datos de esa sede.
            </p>

            <div className="callout callout-info">
                <p>
                    <strong>Cambio bloqueado con caja abierta:</strong> si la sede en la que estás tiene una caja
                    en turno abierto, bistro flexy bloquea el cambio de sede hasta que se cierre el turno. El
                    Propietario sí puede saltarse este bloqueo con un permiso especial.
                </p>
            </div>

            <h2>Aislamiento por diseño</h2>
            <p>
                Cada fila de pedido, cobro, comanda, sesión de mesa y movimiento de inventario lleva en su ADN
                la sede a la que pertenece:
            </p>
            <ul>
                <li>
                    Si la cajera de El Poblado abre una mesa, no la ve la cajera de Laureles.
                </li>
                <li>
                    Una devolución se ejecuta en la sede donde se hizo el cobro original.
                </li>
                <li>
                    La sede de una orden <strong>no se puede cambiar</strong> después de creada.
                </li>
            </ul>

            <h2>Bodegas dentro de una sede</h2>
            <p>
                Cada sede puede tener una o varias <strong>bodegas</strong>: cocina, barra, congelador, almacén
                general. Los insumos viven en una bodega específica, no en la sede genérica.
            </p>
            <p>
                El stock, los movimientos y la valorización se calculan por bodega. No puedes archivar una bodega
                que tiene stock — primero hay que sacarlo o transferirlo.
            </p>

            <h2>Reportes consolidados (vista global)</h2>
            <p>
                El Propietario puede ver reportes <strong>consolidados</strong> de todas las sedes a la vez sin
                tener que ir cambiando sede una por una. Para otros miembros (un contador externo, un supervisor
                regional) se la puedes habilitar con el permiso <strong>"ver todas las sedes en reportes"</strong>.
            </p>

            <h2>Un ejemplo: cadena con vertical mixta</h2>
            <p>Una <strong>operación de tres sedes</strong> en Medellín, todas bajo el mismo NIT:</p>
            <ul>
                <li>
                    <strong>Sede Laureles</strong> (predeterminada) — vertical restaurante. Atiende mesa y
                    domicilio. Dos bodegas: cocina y barra.
                </li>
                <li>
                    <strong>Sede El Poblado</strong> — vertical dark kitchen. Solo domicilios desde una cocina
                    ciega. Una bodega: cocina.
                </li>
                <li>
                    <strong>Sede Envigado</strong> — vertical bar. Atiende mesa, sin domicilio. Dos bodegas:
                    cocina y barra.
                </li>
            </ul>
            <p>
                Don Hernán abre el reporte consolidado del mes: Laureles $42M, El Poblado $28M, Envigado $15M.
                <strong> Total empresa: $85.000.000.</strong>
            </p>

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
                        <td>Crear, editar y archivar sedes</td>
                        <td>Solo Propietario (se le puede asignar al Administrador).</td>
                    </tr>
                    <tr>
                        <td>Cambiar la vertical de una sede</td>
                        <td>Solo Propietario.</td>
                    </tr>
                    <tr>
                        <td>Asignar usuarios a una sede</td>
                        <td>Propietario por defecto; asignable al Administrador.</td>
                    </tr>
                    <tr>
                        <td>Copiar menú entre sedes</td>
                        <td>Propietario por defecto; asignable al Administrador.</td>
                    </tr>
                    <tr>
                        <td>Reportes consolidados de todas las sedes</td>
                        <td>Propietario por defecto; asignable a otros roles con el permiso especial.</td>
                    </tr>
                    <tr>
                        <td>Crear y editar bodegas dentro de una sede</td>
                        <td>Propietario, Administrador y Bodeguero.</td>
                    </tr>
                </tbody>
            </table>
        </ManualLayout>
    );
}
