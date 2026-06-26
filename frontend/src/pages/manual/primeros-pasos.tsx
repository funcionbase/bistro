import ManualLayout from '@/layouts/manual-layout';
import { Link } from 'react-router-dom';

export default function ManualPrimerosPasos() {
    return (
        <ManualLayout
            currentSlug="primeros-pasos"
            pageTitle="Primeros pasos"
            pageDescription="El primer ingreso, los datos del negocio, cómo agregar tu equipo y arrancar la operación desde cero."
            metaTitle="Primeros pasos — Manual bistro.flexyflow.co"
            metaDescription="Guía paso a paso para crear tu cuenta, configurar el negocio, agregar el equipo y hacer tu primer pedido en bistro.flexyflow.co."
            sectionLabel="para arrancar"
            readingTime="8 min"
        >
            <h2>1. Entra con tu cuenta de Google</h2>
            <p>
                En <a href="https://bistro.flexyflow.co" target="_blank" rel="noopener noreferrer">bistro.flexyflow.co</a> le
                das a <kbd>Continuar con Google</kbd>. El sistema usa OAuth, así que no hay contraseña que recordar: la
                seguridad de tu cuenta depende de la seguridad de tu Gmail.
            </p>

            <div className="callout callout-info">
                <p>
                    <strong>Si te aparece "intentos excedidos":</strong> el sistema limita 5 intentos de inicio de
                    sesión por minuto por dirección IP. Espera 60 segundos antes de volver a intentar. Si el problema
                    persiste, escríbenos por el chat de soporte.
                </p>
            </div>

            <h2>2. Completa tu perfil</h2>
            <p>
                La primera vez que ingresas, el sistema te pide tu nombre y cédula. Es información mínima para
                identificarte en el equipo — ningún cliente la ve. Si tienes un cargo específico en la operación
                (gerente, contador, etc.), agrégalo en tu perfil después desde{' '}
                <em>mi cuenta → perfil</em>.
            </p>

            <h2>3. Registra tu negocio</h2>
            <p>Después del perfil, el sistema te lleva al formulario de registro del negocio:</p>
            <ul>
                <li>
                    <strong>Nombre comercial:</strong> el que conoce el cliente (aparece en el menú público y en
                    los recibos).
                </li>
                <li>
                    <strong>NIT:</strong> el de la empresa o el de la persona natural. Queda fijo — si hay un error,
                    escríbenos.
                </li>
                <li>
                    <strong>Vertical:</strong> tipo de negocio (restaurante, bar, cafetería, panadería, dark kitchen).
                    Sirve para sembrar las estaciones de cocina y áreas de preparación correctas.
                </li>
                <li>
                    <strong>Documento:</strong> Cámara de Comercio, RUT o cédula del representante. Lo usamos para
                    verificar que el negocio existe. Un humano lo revisa y activa la cuenta.
                </li>
            </ul>

            <div className="callout callout-warn">
                <p>
                    <strong>Activación manual:</strong> no hay activación automática. Un miembro del equipo de
                    flexyflow revisa el documento y activa la cuenta. Si tienes apuro, escríbenos por el chat de
                    soporte desde la pantalla de "en revisión" y lo resolvemos rápido.
                </p>
            </div>

            <h2>4. Configura lo básico del negocio</h2>
            <p>
                Una vez activo, lo primero que vale la pena hacer antes de operar en serio:
            </p>
            <ul>
                <li>
                    <strong>Logo y QR de pagos:</strong> en{' '}
                    <em>configuración → información del negocio</em>. El logo aparece en el menú público y en los
                    recibos.
                </li>
                <li>
                    <strong>Cuenta bancaria:</strong> en el mismo formulario, para que el bot pueda decirle a los
                    clientes cómo pagar por transferencia.
                </li>
                <li>
                    <strong>Impuestos:</strong> el régimen tributario (RST, INC 8%, IVA 19%, etc.) desde{' '}
                    <em>configuración → información → impuestos</em>.
                </li>
                <li>
                    <strong>Horarios:</strong> cuándo estás abierto y cuándo cerrado. Sin esto el bot no sabe si
                    puede recibir pedidos. Ve a <Link to="/manual/bistro/horarios">horarios</Link>.
                </li>
            </ul>

            <h2>5. Sube tu menú</h2>
            <p>
                Sin menú no hay operación. En <Link to="/manual/bistro/menus">menús</Link> creas tus categorías y
                platos, les pones precio, fotos y recetas (para el control de inventario). Cuando publicas el menú,
                queda disponible en el enlace público de tu negocio y en el QR de mesa.
            </p>

            <div className="callout callout-info">
                <p>
                    <strong>Truco:</strong> no lo dejes todo perfecto antes de publicar. Publica con lo que tienes y
                    ve ajustando. Los clientes prefieren un menú incompleto a no encontrarte online.
                </p>
            </div>

            <h2>6. Invita a tu equipo</h2>
            <p>
                En <Link to="/manual/bistro/usuarios">usuarios y roles</Link> mandas invitaciones por correo. El
                sistema tiene roles listos para los cargos más comunes (cajero, cocinero, mesero, gerente, contador)
                y los permisos ya vienen configurados. La invitación dura 7 días.
            </p>

            <h2>7. Abre tu primera caja</h2>
            <p>
                En <Link to="/manual/bistro/caja">caja</Link> abres el turno con el fondo inicial. Desde ahí puedes
                tomar pedidos, cobrar, imprimir recibos y cerrar el día. Si tienes impresora térmica, conéctala en{' '}
                <em>configuración → impresoras</em> antes de abrir.
            </p>

            <div className="callout callout-success">
                <p>
                    <strong>Listo para operar.</strong> Tienes el negocio registrado, el menú publicado, el equipo
                    invitado y la caja abierta. Lo demás (domicilios, WhatsApp, fidelización, DIAN) lo vas
                    activando a medida que la operación lo pida.
                </p>
            </div>
        </ManualLayout>
    );
}
