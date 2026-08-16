import ManualLayout from '@/layouts/manual-layout';
import { useEffect } from 'react';
import { Link } from 'react-router-dom';

const faqs = [
    {
        q: '¿Necesito instalar algo para usar bistro?',
        a: 'No. Es una app web progresiva (PWA). La abres en el navegador desde cualquier celular, tableta o computador, y si quieres el ícono en la pantalla de inicio de tu teléfono, elige "Agregar al inicio" desde el menú del navegador.',
    },
    {
        q: '¿Funciona offline?',
        a: 'Parcialmente. Si estás en la caja y se va el internet, puedes seguir viendo y usando lo que ya cargó. Pero los datos nuevos (pedidos que lleguen, cambios de menú, actualizaciones de stock) no sincronizan hasta que vuelva la conexión.',
    },
    {
        q: '¿Cuántos usuarios puedo tener?',
        a: 'Sin límite fijo por el plan. Invitas a los miembros del equipo que necesites, con el rol que les corresponda. Ver guía completa en Usuarios, roles y permisos.',
    },
    {
        q: '¿Cómo inicio sesión?',
        a: 'Solo con Google. No hay contraseña que recordar. El correo con el que te invitaron debe coincidir con el que usas en Google.',
    },
    {
        q: '¿Puedo cambiar mi contraseña?',
        a: 'No aplica — no hay contraseña. La autenticación es 100% OAuth con tu cuenta de Google. Si quieres más seguridad, activa la verificación en dos pasos en tu Google.',
    },
    {
        q: '¿Por qué se me cerró la sesión sola?',
        a: 'Por seguridad, la sesión se cierra después de 6 horas sin usar la app, y en todo caso a las 12 horas de haber entrado (aunque la estés usando). Es normal que al llegar en la mañana toque entrar de nuevo con Google — toma dos toques. Mientras estés trabajando activamente, la sesión se mantiene sola.',
    },
    {
        q: 'Entré con la cuenta de Google equivocada, ¿qué hago?',
        a: 'En la pantalla donde eliges el negocio hay un botón de "Cerrar sesión" — úsalo y vuelve a entrar. Al darle a "Continuar con Google", el sistema siempre te deja escoger con cuál cuenta entrar, así que selecciona la del correo con el que te invitaron.',
    },
    {
        q: 'Me apareció un aviso de "nueva versión disponible", ¿qué hago?',
        a: 'Dale al botón de actualizar del aviso. La app se recarga en segundos con la versión más reciente y sigues donde ibas. Si lo ignoras, la app sigue funcionando, pero conviene actualizar pronto para tener las últimas mejoras y correcciones.',
    },
    {
        q: '¿Qué pasa si el dueño del negocio se va?',
        a: 'El sistema exige que siempre haya al menos un Propietario activo. Para reemplazarlo, primero el dueño saliente le asigna el rol Propietario a otra persona, y luego puede salirse. No hay forma de quedar sin Propietario.',
    },
    {
        q: '¿Puedo tener varias sedes con el mismo NIT?',
        a: 'Sí, es la función de multi-sede. Cada sede tiene su caja, su tablero de pedidos, su inventario y su menú. Comparten la base de clientes, el programa de fidelización y la configuración fiscal DIAN. Ver sedes y bodegas.',
    },
    {
        q: '¿Cómo duplico el menú de una sede a otra?',
        a: 'Desde configuración → sedes, usa la opción "Copiar menú a...". A partir de ahí cada sede edita su copia por separado — los cambios en una no afectan a la otra.',
    },
    {
        q: '¿Puedo tener un menú diferente para mesa y para domicilio?',
        a: 'Sí. Al editar un plato o una categoría, decides si aparece en el menú de restaurante (mesa/mostrador), en el de domicilios, en ambos o en ninguno. Ver menús.',
    },
    {
        q: '¿Los precios del menú incluyen impuestos?',
        a: 'Depende de cómo lo configures en información del negocio → impuestos. Tienes la opción de que los precios mostrados incluyan el impuesto ("IVA/INC incluido") o de que se sume al cobrar.',
    },
    {
        q: '¿Cómo recibe pedidos por WhatsApp si el cliente no está en contacto?',
        a: 'Cuando un número nuevo escribe, el bot de WhatsApp arranca el flujo de pedido automáticamente. El cliente elige del menú, confirma y eso crea un pedido en el panel igual que si lo hubiera hecho por la app de mesa. Ver WhatsApp del negocio.',
    },
    {
        q: '¿Puedo conectar más de un número de WhatsApp?',
        a: 'Un número por sede. Si tienes tres sedes, cada una puede tener su propio número de WhatsApp. El chat de cada sede es independiente.',
    },
    {
        q: '¿Qué pasa si se va la luz en la caja?',
        a: 'Lo que ya se cargó sigue visible en el dispositivo. Los pedidos pendientes en cocina no se pierden porque viven en el servidor. Cuando vuelve la corriente y la conexión, todo sincroniza. Si tenías un pedido a medias, hay que retomarlo manualmente.',
    },
    {
        q: '¿Puedo cobrar con tarjeta desde el panel?',
        a: 'El panel registra el pago en "método: tarjeta" pero no procesa la transacción — para eso necesitas un datáfono físico o Wompi/Epayco conectado por separado. bistro registra el medio, el monto y la referencia del comprobante.',
    },
    {
        q: '¿Cómo divido una cuenta entre varios comensales?',
        a: 'Desde la sesión de mesa activa, hay un botón "dividir pago" que abre el flujo de pago dividido. Puedes dividir en partes iguales o asignar montos distintos a cada persona. Ver caja y cobros.',
    },
    {
        q: '¿Puedo hacer una devolución parcial?',
        a: 'Sí. En el detalle del pedido cerrado, abre la opción de devolución e introduce el monto o los ítems que vas a devolver. Se crea un recibo con monto negativo y queda el registro contable. Ver caja y cobros.',
    },
    {
        q: '¿Los cupones funcionan con domicilios y mesa a la vez?',
        a: 'Cada cupón se configura para canales específicos: mesa, domicilio o ambos. Si restringes a domicilio, el sistema no lo aplica a pedidos de mesa aunque el cliente tenga el código. Ver cupones y descuentos.',
    },
    {
        q: '¿Los puntos de fidelización se acumulan en todas las sedes?',
        a: 'Sí. El programa de fidelización es a nivel de negocio (NIT), no por sede. Un cliente que pide en Laureles y en El Poblado acumula puntos en el mismo saldo. Ver puntos de fidelidad.',
    },
    {
        q: '¿Puedo exportar mi base de clientes?',
        a: 'Sí. En clientes hay una opción de exportación a CSV con los datos básicos: nombre, teléfono, correo, segmento, total gastado, fecha de último pedido y etiquetas. Si necesitas importar una base de clientes existente, escríbenos al soporte para coordinarlo manualmente.',
    },
    {
        q: '¿Las métricas son en tiempo real?',
        a: 'Los KPIs del tablero principal (ventas del día, pedidos activos, ocupación de mesas) se actualizan cada pocos minutos. Los reportes históricos (semana, mes) se calculan con los datos cerrados del día anterior. Ver métricas.',
    },
    {
        q: '¿Dónde quedan las facturas electrónicas DIAN?',
        a: 'Cada documento (DEE POS y FEV) se guarda con su XML, su PDF y su CUFE/CUDE. Puedes descargarlos desde el historial de la orden. Se conservan los 5 o 10 años que exige la DIAN según el tipo de persona. Ver facturación.',
    },
    {
        q: '¿Qué pasa si la DIAN rechaza una factura?',
        a: 'El sistema te muestra una alerta, te dice cuál fue el error y te deja reenviarla o corregir los datos. Nada se pierde — el recibo interno de bistro queda aunque la DIAN no haya aceptado el documento todavía. Ver alertas.',
    },
    {
        q: '¿Puedo imprimir desde el celular?',
        a: 'Si la impresora tiene Bluetooth y el navegador del teléfono tiene soporte (Chrome en Android funciona bien), sí. En iPhone el soporte Bluetooth por navegador es limitado — la configuración más estable es una impresora en red local (WiFi) accesible desde cualquier dispositivo.',
    },
    {
        q: '¿Cuántas impresoras puedo conectar?',
        a: 'Sin límite fijo. Puedes tener impresoras de cocina, barra, caja y recibos, cada una con sus propias categorías de platos. Ver configuración → impresoras.',
    },
    {
        q: '¿Puedo cambiar el idioma a inglés?',
        a: 'Hoy el panel está disponible solo en español. La zona horaria (UTC-5, hora Colombia) y la moneda (pesos colombianos, COP) son fijas dado el enfoque en legislación colombiana.',
    },
    {
        q: '¿bistro usa analíticas o cookies de seguimiento?',
        a: 'La plataforma usa Google Analytics 4 (GA4) para entender cómo se usa la app y mejorarla. La primera vez que entras aparece un banner de consentimiento donde decides si aceptas o rechazas las analíticas. Si cambias de opinión, puedes revocar desde el mismo banner o escribirnos. Las analíticas son de la plataforma — el restaurante no tiene acceso al panel de GA4.',
    },
    {
        q: '¿Cómo cancelo mi suscripción?',
        a: 'Escríbele al equipo de bistro o a tu asesor asignado. La cancelación es manual por parte de nuestro equipo. Los datos del negocio se conservan por el período legal; después se eliminan según la política de privacidad.',
    },
    {
        q: '¿Qué pasa con mis datos si cancelo?',
        a: 'Los datos financieros (facturas DIAN, recibos) se conservan el tiempo exigido por ley. El resto queda en respaldo por 30 días y luego se elimina de los servidores activos. Puedes solicitar una exportación antes de cancelar.',
    },
    {
        q: '¿Dónde puedo reportar un bug o pedir una función nueva?',
        a: 'Desde el mismo panel hay un ícono de chat en la esquina inferior derecha que conecta directamente con el equipo de soporte. También puedes escribir al correo que aparece en la página de ayuda del sitio web.',
    },
];

export default function ManualFaq() {
    // JSON-LD FAQPage — candidatea rich results de preguntas en Google.
    useEffect(() => {
        const jsonLd = document.createElement('script');
        jsonLd.type = 'application/ld+json';
        jsonLd.id = 'faq-jsonld';
        jsonLd.textContent = JSON.stringify({
            '@context': 'https://schema.org',
            '@type': 'FAQPage',
            inLanguage: 'es-CO',
            mainEntity: faqs.map((f) => ({
                '@type': 'Question',
                name: f.q,
                acceptedAnswer: { '@type': 'Answer', text: f.a },
            })),
        });
        document.head.appendChild(jsonLd);
        return () => jsonLd.remove();
    }, []);

    return (
        <ManualLayout
            currentSlug="faq"
            pageTitle="Preguntas frecuentes"
            pageDescription="Las preguntas que más llegan al soporte. Si no encuentras lo que buscas, escríbenos desde el chat del panel."
            metaTitle="Preguntas frecuentes — Manual bistro.example.com"
            metaDescription="Respuestas a las dudas más comunes sobre cómo funciona bistro: pedidos, caja, WhatsApp, facturación DIAN, sedes y más."
            sectionLabel="ayuda"
            readingTime="10 min"
        >
            <div className="space-y-3">
                {faqs.map((faq, i) => (
                    <details
                        key={i}
                        className="group border border-[var(--color-border-light)] rounded-xl overflow-hidden"
                    >
                        <summary className="flex items-center justify-between gap-4 px-5 py-4 cursor-pointer font-semibold text-[var(--color-dark)] select-none list-none hover:bg-[var(--color-theme-light)] transition-colors">
                            <span>{faq.q}</span>
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="16"
                                height="16"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="2"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                className="shrink-0 transition-transform group-open:rotate-180 text-[var(--color-text-default)]"
                            >
                                <polyline points="6 9 12 15 18 9" />
                            </svg>
                        </summary>
                        <div className="px-5 pb-4 pt-1 text-[rgba(30,35,46,0.78)] leading-relaxed">{faq.a}</div>
                    </details>
                ))}
            </div>

            <h2>¿Sigues con dudas?</h2>
            <p>
                Escríbenos desde el <strong>chat del panel</strong> (ícono abajo a la derecha) o a través del
                formulario de contacto en{' '}
                <a
                    href="https://example.com"
                    target="_blank"
                    rel="noopener noreferrer"
                    style={{ color: 'var(--primary)', textDecoration: 'underline' }}
                >
                    example.com
                </a>
                . El equipo de soporte responde en horario de lunes a sábado de 8 AM a 6 PM (hora Colombia).
            </p>
            <p>
                Si encontraste algo que no está en este manual, revisa el índice completo en{' '}
                <Link to="/manual">inicio del manual</Link>.
            </p>
        </ManualLayout>
    );
}
