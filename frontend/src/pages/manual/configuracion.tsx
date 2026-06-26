import ManualLayout from '@/layouts/manual-layout';
import { Link } from 'react-router-dom';

export default function ManualConfiguracion() {
    return (
        <ManualLayout
            currentSlug="configuracion"
            pageTitle="Configuración"
            pageDescription="Información del negocio, preferencias operativas, perfil fiscal DIAN, conexión a WhatsApp, impresoras térmicas, atajos y la cuenta personal de cada miembro del equipo."
            metaTitle="Configuración — Manual bistro.flexyflow.co"
            metaDescription="Datos del negocio, banca, QR de pagos, impuestos, perfil fiscal DIAN, conexión a WhatsApp, impresoras térmicas y configuración personal. Todo desde un solo lugar."
            sectionLabel="administración"
            readingTime="8 min"
        >
            <h2>Mapa de configuración</h2>
            <table>
                <thead>
                    <tr>
                        <th>Pantalla</th>
                        <th>Para qué sirve</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Información del negocio</td>
                        <td>Datos generales, banca, QR de pagos, logo, impuestos.</td>
                    </tr>
                    <tr>
                        <td>Preferencias</td>
                        <td>Regional, pedidos, notificaciones al cliente, branding del menú público.</td>
                    </tr>
                    <tr>
                        <td>Facturación electrónica DIAN</td>
                        <td>Perfil fiscal, resoluciones, proveedor tecnológico, adquirente por defecto.</td>
                    </tr>
                    <tr>
                        <td>WhatsApp</td>
                        <td>Conectar y administrar el número de WhatsApp Cloud API.</td>
                    </tr>
                    <tr>
                        <td>Impresoras</td>
                        <td>Térmicas para cocina, barra y recibos al cliente.</td>
                    </tr>
                    <tr>
                        <td>Sedes</td>
                        <td>
                            Crear, editar y archivar sucursales (ver{' '}
                            <Link to="/manual/bistro/sedes">sedes</Link>).
                        </td>
                    </tr>
                    <tr>
                        <td>Mi cuenta</td>
                        <td>Perfil personal, apariencia, notificaciones push.</td>
                    </tr>
                </tbody>
            </table>

            <h2>Información del negocio</h2>
            <ul>
                <li>
                    <strong>Nombre comercial</strong> (el que conoce el cliente) y{' '}
                    <strong>razón social</strong> (el del RUT).
                </li>
                <li>
                    <strong>NIT</strong> — quedó fijo desde el onboarding.{' '}
                    <strong>No se puede cambiar</strong> después porque viaja en toda la facturación electrónica
                    que ya emitiste.
                </li>
                <li>
                    <strong>Cuenta bancaria:</strong> banco, número y tipo (corriente o ahorros).
                </li>
                <li>
                    <strong>Llave Bre-B</strong> (opcional) — para transferencias instantáneas.
                </li>
                <li>
                    <strong>Logo</strong> — JPG, PNG, WEBP o SVG, máximo 5 MB.
                </li>
                <li>
                    <strong>QR de pagos:</strong> JPG o PNG, máximo 5 MB.
                </li>
            </ul>

            <div className="callout callout-info">
                <p>
                    <strong>Cambias el nombre comercial y se ve al instante:</strong> la barra lateral con el
                    nombre del negocio se actualiza sin que tengas que cerrar sesión ni refrescar.
                </p>
            </div>

            <h3>Configuración de impuestos</h3>
            <ul>
                <li>
                    <strong>Régimen tributario:</strong> Simple, INC 8%, IVA 19%, IVA 5%, Exento o Personalizado.
                </li>
                <li>
                    <strong>Tasa por defecto:</strong> el % de impuesto que se aplica a un plato cuando no le
                    pones uno específico.
                </li>
                <li>
                    <strong>Precios con impuesto incluido:</strong> si los precios del menú ya traen el impuesto
                    adentro o si se le suma al cobrar.
                </li>
            </ul>

            <h2>Preferencias</h2>

            <h3>Pedidos</h3>
            <ul>
                <li>
                    <strong>Área de cobertura</strong> de domicilios en kilómetros (de 1 a 100).
                </li>
                <li>
                    <strong>Monto mínimo de pedido</strong> en pesos.
                </li>
                <li>
                    <strong>Métodos de pago aceptados:</strong> efectivo, transferencia, tarjeta, Nequi, DaviPlata.
                </li>
                <li>
                    <strong>Auto-confirmar pedidos:</strong> si está activo, los pedidos entran directamente como
                    "en cocina" en vez de quedarse en "pendiente".
                </li>
            </ul>

            <h3>Branding del menú público</h3>
            <ul>
                <li>
                    <strong>Color principal</strong> en hexadecimal (ej. <code>#0052FF</code>). Se usa para
                    pintar los botones y headers del menú público con tu color de marca.
                </li>
            </ul>

            <h2>Facturación electrónica DIAN</h2>
            <ul>
                <li>
                    <strong>Perfil fiscal:</strong> razón social fiscal, dirección, régimen tributario,
                    responsabilidades fiscales, CIIU, representante legal, municipio.
                </li>
                <li>
                    <strong>Resoluciones de facturación:</strong> el rango de consecutivos que la DIAN te
                    autorizó. Puedes tener varias resoluciones activas a la vez.
                </li>
                <li>
                    <strong>Proveedor tecnológico:</strong> el operador autorizado (Facturalo, Factus, etc.).
                    Las credenciales quedan cifradas — nadie las puede ver en texto plano.
                </li>
                <li>
                    <strong>Adquirente por defecto:</strong> el "cliente genérico" (consumidor final DIAN,
                    NIT 222222222222) para tickets POS sin datos del cliente.
                </li>
            </ul>

            <div className="callout callout-warn">
                <p>
                    <strong>Sin perfil fiscal no se factura:</strong> si intentas emitir un documento electrónico
                    sin tener el perfil DIAN y la resolución vigente configurados, bistro flexy te bloquea.
                </p>
            </div>

            <h2>WhatsApp Cloud API</h2>
            <p>
                En <strong>configuración → WhatsApp</strong> conectas el número de WhatsApp del negocio. Ver la
                guía completa en <Link to="/manual/bistro/whatsapp">WhatsApp del negocio</Link>.
            </p>

            <div className="callout callout-info">
                <p>
                    <strong>Solo el Propietario:</strong> cambiar el número conectado o desconectar la cuenta de
                    WhatsApp <strong>solo lo puede hacer el dueño</strong>. Ni el administrador con todos los
                    permisos puede tocar eso.
                </p>
            </div>

            <h2>Impresoras térmicas</h2>
            <p>
                En <strong>configuración → impresoras</strong> configuras:
            </p>
            <ul>
                <li>
                    <strong>Nombre y tipo:</strong> cocina, barra, caja o recibos al cliente.
                </li>
                <li>
                    <strong>Conexión:</strong> USB, Bluetooth o por red local.
                </li>
                <li>
                    <strong>Ancho de papel:</strong> 58 o 80 mm.
                </li>
                <li>
                    <strong>Categorías que atiende</strong> (para impresoras de cocina/barra).
                </li>
            </ul>
            <p>
                Hay un botón <strong>"Probar impresora"</strong> que manda un ticket de prueba para verificar
                que la conexión funciona antes de abrir el local.
            </p>

            <h2>Configuración personal</h2>
            <p>
                Cada miembro administra su propia cuenta desde <strong>mi cuenta</strong>. Esta configuración es
                individual.
            </p>

            <h3>Apariencia (tema)</h3>
            <ul>
                <li>
                    Elige entre <strong>claro</strong>, <strong>oscuro</strong> o <em>según el sistema</em>.
                </li>
                <li>La preferencia se guarda en el navegador.</li>
            </ul>

            <h3>Notificaciones push</h3>
            <p>
                En <strong>mi cuenta → notificaciones</strong> decides en qué dispositivo quieres recibir avisos
                push (pedidos nuevos, devoluciones, mensajes urgentes, etc.).
            </p>

            <div className="callout callout-info">
                <p>
                    <strong>iPhone:</strong> en iOS las notificaciones push solo llegan si{' '}
                    <strong>instalas la app al "home screen"</strong> (botón Compartir → "Agregar al Inicio"). Es
                    una limitación de Apple, no de bistro flexy.
                </p>
            </div>

            <h2>Atajos de teclado</h2>
            <table>
                <thead>
                    <tr>
                        <th>Atajo</th>
                        <th>Va a</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <kbd>Alt + H</kbd>
                        </td>
                        <td>Panel de inicio</td>
                    </tr>
                    <tr>
                        <td>
                            <kbd>Alt + O</kbd>
                        </td>
                        <td>Tablero de pedidos</td>
                    </tr>
                    <tr>
                        <td>
                            <kbd>Alt + J</kbd>
                        </td>
                        <td>Caja</td>
                    </tr>
                    <tr>
                        <td>
                            <kbd>Alt + E</kbd>
                        </td>
                        <td>Domicilios</td>
                    </tr>
                    <tr>
                        <td>
                            <kbd>Alt + M</kbd>
                        </td>
                        <td>Menú</td>
                    </tr>
                    <tr>
                        <td>
                            <kbd>Alt + S</kbd>
                        </td>
                        <td>Chats</td>
                    </tr>
                    <tr>
                        <td>
                            <kbd>Alt + P</kbd>
                        </td>
                        <td>Cupones</td>
                    </tr>
                    <tr>
                        <td>
                            <kbd>Alt + B</kbd>
                        </td>
                        <td>Horarios</td>
                    </tr>
                    <tr>
                        <td>
                            <kbd>Alt + T</kbd>
                        </td>
                        <td>Métricas</td>
                    </tr>
                    <tr>
                        <td>
                            <kbd>Alt + R</kbd>
                        </td>
                        <td>Informes</td>
                    </tr>
                    <tr>
                        <td>
                            <kbd>Alt + U</kbd>
                        </td>
                        <td>Usuarios</td>
                    </tr>
                    <tr>
                        <td>
                            <kbd>Alt + L</kbd>
                        </td>
                        <td>Roles</td>
                    </tr>
                    <tr>
                        <td>
                            <kbd>Ctrl + B</kbd>
                        </td>
                        <td>Mostrar / ocultar menú lateral</td>
                    </tr>
                    <tr>
                        <td>
                            <kbd>?</kbd>
                        </td>
                        <td>Ventana de ayuda con todos los atajos</td>
                    </tr>
                </tbody>
            </table>
        </ManualLayout>
    );
}
