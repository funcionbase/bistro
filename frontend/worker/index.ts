/**
 * Worker del SPA. Sirve los assets estáticos (binding ASSETS) tal cual, EXCEPTO
 * las rutas del menú público, donde reescribe los meta tags de Open Graph para
 * que la preview en WhatsApp sea del MENÚ (con un llamado a pedir), no del
 * marketing de la plataforma.
 *
 * El crawler de WhatsApp NO ejecuta JS, así que ve el HTML que devuelve el
 * Worker. Alcance acotado a las rutas de menú (run_worker_first en wrangler):
 *   - /menus/:nit         (QR directo por NIT)
 *   - /menus?branch=XXX   (QR de sede; "enviar la carta" desde el chat)
 *   - /menus              (alias)
 *
 * El nombre del restaurante NO se resuelve: es variable y se usa un copy neutro
 * ("Nuestro menú"), evitando un fetch por request del crawler.
 */

interface Env {
    ASSETS: { fetch(request: Request): Promise<Response> };
}

const OG_IMAGE = 'https://bistro.flexyflow.co/og-menu.png';
const TITLE = 'Nuestro menú — Pedí online';
const DESC = 'Mirá el menú y hacé tu pedido en segundos. Menú digital, sin filas.';

export default {
    async fetch(request: Request, env: Env): Promise<Response> {
        const url = new URL(request.url);
        const isMenu = url.pathname === '/menus' || url.pathname.startsWith('/menus/');

        if (!isMenu) {
            return env.ASSETS.fetch(request);
        }

        const indexResp = await env.ASSETS.fetch(new Request(new URL('/index.html', url.origin).toString()));

        const meta = (value: string) => ({
            element(el: { setAttribute(k: string, v: string): void }) {
                el.setAttribute('content', value);
            },
        });

        // HTMLRewriter es un global del runtime de Workers.
        return new HTMLRewriter()
            .on('title', {
                element(el: { setInnerContent(v: string): void }) {
                    el.setInnerContent(TITLE);
                },
            })
            .on('meta[name="description"]', meta(DESC))
            .on('meta[property="og:title"]', meta(TITLE))
            .on('meta[property="og:description"]', meta(DESC))
            .on('meta[property="og:image"]', meta(OG_IMAGE))
            .on('meta[property="og:image:width"]', meta('1200'))
            .on('meta[property="og:image:height"]', meta('630'))
            .on('meta[property="og:url"]', meta(url.toString()))
            .on('meta[property="og:type"]', meta('website'))
            .on('meta[name="twitter:title"]', meta(TITLE))
            .on('meta[name="twitter:image"]', meta(OG_IMAGE))
            .transform(indexResp);
    },
};

// Declaración mínima del global del runtime (no está en el tsconfig del SPA;
// wrangler/esbuild lo provee en runtime).
declare const HTMLRewriter: {
    new (): {
        on(selector: string, handlers: unknown): unknown;
        transform(response: Response): Response;
    };
};
