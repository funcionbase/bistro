import { useEffect } from 'react';

/**
 * Easter egg: intercepta Ctrl/Cmd+S y, en vez de dejar que el navegador guarde
 * la página, descarga un `index.html` maquetado con el design system de
 * flexyflow + un mensaje gracioso aleatorio (distinto cada vez).
 *
 * Port del `SavePageEasterEgg.astro` del sitio (flexyflow.co). NO es seguridad:
 * el frontend sigue siendo público (DevTools, ver código fuente). Solo neutraliza
 * el atajo de teclado y le saca una sonrisa a quien lo intente.
 *
 * Se monta una sola vez en el root de la SPA. Render = null (solo comportamiento).
 */

const MESSAGES = [
    'Nice try. El código está minificado y no nos da pena.',
    'Pulsaste Ctrl+S y solo conseguiste este HTML. Justicia poética.',
    'El frontend es público. Tu curiosidad, también ahora.',
    'Guardaste la página. La página te guardó a ti.',
    'Esto no es el código fuente. Es el código que mereces.',
    '¿Ingeniería inversa? Empieza por postularte: info@flexyflow.co',
    'Ctrl+S detectado. Plot twist: estabas guardando una carpeta vacía.',
    'Lo que tu navegador hace dos veces, nosotros lo interceptamos una vez.',
    'Descargaste un .html. Tu disco duro te observa con tristeza.',
    'Aquí no hay secretos. Solo CSS bien puesto y este mensaje.',
    'El producto de verdad vive en bistro.flexyflow.co. Tampoco lo robas.',
    'Querías el código. Te llevas la moraleja.',
    'Minificado, sin comentarios, sin source maps. Te ahorramos la lectura.',
    'Esto es lo único que vas a guardar hoy. De nada.',
    'Robarse un frontend es como fotocopiar un menú: tienes el papel, no la cocina.',
    'El HTML es tuyo. El backend, las integraciones y el equipo, no tanto.',
    'Ctrl+S es el atajo. Esto es el desvío.',
    'No copiamos plantillas. Por eso copiar la nuestra tampoco sirve de mucho.',
    "Guardaste la página por si acaso. El 'por si acaso' llegó: es este archivo.",
    'Tu navegador quería el HTML. Le dijimos que no en tu nombre.',
    'Llegaste hasta Ctrl+S, te gusta el detalle. Nos caes bien. Igual nada de código.',
    'Esto pesa menos que tu intención. Y dice más.',
    'Reverse engineering nivel: descargaste un saludo.',
    'La página que querías guardar te manda saludos desde la nube.',
    'Hicimos un easter egg para que no te fueras con las manos vacías.',
    'El código está en DevTools. Pero esto era más divertido, ¿no?',
    'Felicidades: eres oficialmente más curioso que el 99% de las visitas.',
    'Lo bueno de un frontend público es que no hay nada que esconder. Lo malo, también.',
    'Guardar página: cancelado. Sentido del humor: entregado.',
    'Esto es un HTML con estilo. Como todo lo que hacemos. Como nada que te lleves.',
    'Tomaste un atajo. El atajo tomó una decisión.',
    'El archivo más honesto de internet: te dice que no te lleva a ningún lado.',
    'Querías inspeccionar. Te inspeccionamos de vuelta. Estás leyendo, ¿ves?',
    'El frontend se ve. El criterio para armarlo, no se descarga.',
    'Ctrl+S: 0. Curiosidad: 1. bistro: presente.',
    'Te llevas un recuerdo, no un repositorio.',
    'Si esto fuera un restaurante, te acabamos de servir el pan de cortesía.',
    'Guardar la web no la hace tuya. Pero este HTML, sí. Disfrútalo.',
    'El código no se esconde, se entiende. Y entender toma más que un Ctrl+S.',
    'Plot twist: el código que buscabas necesitaba un equipo, no una descarga.',
    'Descargaste curiosidad pura, empaquetada en unos pocos kilobytes.',
    'Esto no frena la ingeniería inversa. Solo la hace más graciosa.',
    'Tu Ctrl+S fue escuchado, valorado y educadamente ignorado.',
    'Aquí tienes algo que guardar: no todo lo público vale la pena copiarse.',
    'El menú está a la vista. La receta, no. Bienvenido a bistro.',
    'Guardaste la página. Te diste cuenta de que tenía sentido del humor.',
    'Un frontend bonito es fácil de ver y difícil de igualar. Pruébalo.',
    'Esto es lo que pasa cuando un dev aburrido escribe el easter egg.',
    'Encontraste el huevo de pascua. No hay premio, hay estilo.',
    'El código fuente es público; la cocina que lo sirve, exclusiva.',
    'Ctrl+S interceptado con cariño y un poco de sarcasmo.',
    'Llévate el HTML. Déjanos la ventaja competitiva.',
    'No es magia, es CSS. Y aún así no te lo puedes llevar entero.',
    'Tu disco duro acaba de ganar un archivo y perder una ilusión.',
    'Si querías ver cómo trabajamos: así, hasta en los detalles que nadie pidió.',
];

function escapeHtml(str: string): string {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/** Documento HTML maquetado con el design system de flexyflow (tokens del sitio). */
function buildHtml(message: string): string {
    return `<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>bistro — Ctrl+S interceptado</title>
<meta name="robots" content="noindex" />
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500&family=Unbounded:wght@400;600;700&display=swap" rel="stylesheet" />
<style>
  @font-face {
    font-family: "FlexyFont";
    src: url("https://flexyflow.co/fonts/FlexyFont.otf") format("opentype");
    font-weight: 400 700;
    font-display: swap;
  }
  :root {
    --primary: #0052FF;
    --accent:  #C0FD79;
    --dark:    #232733;
    --text:    #1E232E;
    --paper:   #f6f5f3;
    --border:  #e5e5e5;
    --f-brand: "FlexyFont", "Unbounded", sans-serif;
    --f-head:  "Unbounded", sans-serif;
    --f-body:  "Poppins", sans-serif;
  }
  * , *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: var(--f-body);
    color: var(--text);
    background: var(--paper);
    line-height: 1.7;
    -webkit-font-smoothing: antialiased;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
  }
  ::selection { background: var(--accent); color: var(--dark); }
  .wrap {
    max-width: 1080px;
    margin: 0 auto;
    width: 100%;
    padding: 72px 48px;
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
  }
  .sec-label {
    display: flex;
    align-items: center;
    gap: 16px;
    font-family: var(--f-head);
    font-size: 0.72rem;
    font-weight: 600;
    letter-spacing: 0.22em;
    text-transform: uppercase;
    margin-bottom: 48px;
  }
  .sec-label .num { color: var(--primary); }
  .sec-label .line { flex: 1; height: 1px; background: currentColor; opacity: 0.18; }
  .display {
    font-family: var(--f-brand);
    font-weight: 700;
    font-size: clamp(2.1rem, 5.2vw, 4.4rem);
    line-height: 1.04;
    letter-spacing: -0.02em;
    margin-bottom: 32px;
  }
  .body {
    font-size: 1.05rem;
    opacity: 0.72;
    max-width: 50ch;
    margin-bottom: 40px;
  }
  .tag {
    align-self: flex-start;
    background: var(--accent);
    color: var(--dark);
    font-family: var(--f-head);
    font-size: 0.72rem;
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    padding: 11px 18px;
  }
  footer {
    border-top: 1px solid var(--border);
    padding: 28px 48px;
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    font-family: var(--f-head);
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
  }
  footer a { color: var(--primary); text-decoration: none; }
  @media (max-width: 720px) {
    .wrap { padding: 56px 24px; }
    footer { padding: 24px; }
  }
</style>
</head>
<body>
  <main class="wrap">
    <div class="sec-label">
      <span class="num">&#10005;</span>
      <span>Intento de guardado interceptado</span>
      <span class="line"></span>
    </div>
    <h1 class="display">${escapeHtml(message)}</h1>
    <p class="body">Esperabas nuestro código fuente y te llevas un index.html con mucha autoestima. El navegador cumplió: guardó <em>un</em> HTML — que no fuera el que querías es un tema entre tú y tus expectativas. El buen gusto, eso sí, sigue sin botón de descarga.</p>
    <span class="tag">Construimos la operación digital de tu negocio</span>
  </main>
  <footer>
    <span>bistro · SaaaaaaS :(</span>
    <a href="https://flexyflow.co">flexyflow.co</a>
  </footer>
</body>
</html>
`;
}

export function SavePageEasterEgg() {
    useEffect(() => {
        function onKeyDown(e: KeyboardEvent) {
            const isSaveShortcut = (e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && e.key.toLowerCase() === 's';
            if (!isSaveShortcut) {
                return;
            }

            // Evita el diálogo "Guardar página como…" del navegador.
            e.preventDefault();

            const message = MESSAGES[Math.floor(Math.random() * MESSAGES.length)];
            const blob = new Blob([buildHtml(message)], { type: 'text/html;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            // index.html a propósito: parece la página real guardada; la sorpresa
            // solo aparece al abrirlo.
            a.download = 'index.html';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        }

        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, []);

    return null;
}
