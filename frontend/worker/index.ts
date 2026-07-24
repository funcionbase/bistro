/**
 * Worker del SPA. Sirve los assets estáticos (binding ASSETS) tal cual, EXCEPTO
 * las rutas del menú público `/menus/:nit`, donde reescribe los meta tags de
 * Open Graph para que la preview en WhatsApp sea del MENÚ del restaurante (con
 * su nombre + un llamado a pedir), no del marketing de la plataforma.
 *
 * El crawler de WhatsApp NO ejecuta JS, así que ve el HTML que devuelve el
 * Worker. Para todo lo demás (home, panel, etc.) se conservan los OG del
 * index.html. Alcance acotado a /menus/* a propósito.
 */

interface Env {
    ASSETS: { fetch(request: Request): Promise<Response> };
}

const MENU_RE = /^\/menus\/([^/]+)\/?$/;
const API_BASE = 'https://bistro-api.flexyflow.co';
const OG_IMAGE = 'https://bistro.flexyflow.co/og-menu.svg';

function esc(s: string): string {
    return s.replace(/[<>&"]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' })[c] as string);
}

async function restaurantName(nit: string): Promise<string | null> {
    try {
        const r = await fetch(`${API_BASE}/api/v1/public/menu/${encodeURIComponent(nit)}`, {
            cf: { cacheTtl: 300, cacheEverything: true },
        } as RequestInit);
        if (!r.ok) return null;
        const data = (await r.json()) as Record<string, unknown>;
        // El branding (nombre) puede venir en distintas formas; se prueba en orden.
        const company = data.company as Record<string, unknown> | undefined;
        const branding = data.branding as Record<string, unknown> | undefined;
        const name = (company?.name ?? branding?.name ?? data.name ?? data.company_name) as string | undefined;
        return typeof name === 'string' && name.trim() !== '' ? name.trim() : null;
    } catch {
        return null;
    }
}

export default {
    async fetch(request: Request, env: Env): Promise<Response> {
        const url = new URL(request.url);
        const match = url.pathname.match(MENU_RE);

        if (!match) {
            return env.ASSETS.fetch(request);
        }

        const nit = decodeURIComponent(match[1]);
        const name = (await restaurantName(nit)) ?? 'Nuestro menú';

        const title = `${name} — Menú · Pedí online`;
        const desc = `Mirá el menú de ${name} y hacé tu pedido en segundos. Menú digital, sin filas.`;
        const canonical = url.toString();

        const indexResp = await env.ASSETS.fetch(new Request(new URL('/index.html', url.origin).toString()));

        const meta = (value: string) => ({
            element(el: { setAttribute(k: string, v: string): void }) {
                el.setAttribute('content', value);
            },
        });

        // HTMLRewriter es un global del runtime de Workers.
        const rw = new HTMLRewriter()
            .on('title', {
                element(el: { setInnerContent(v: string): void }) {
                    el.setInnerContent(esc(title));
                },
            })
            .on('meta[name="description"]', meta(desc))
            .on('meta[property="og:title"]', meta(title))
            .on('meta[property="og:description"]', meta(desc))
            .on('meta[property="og:image"]', meta(OG_IMAGE))
            .on('meta[property="og:url"]', meta(canonical))
            .on('meta[property="og:type"]', meta('website'))
            .on('meta[name="twitter:title"]', meta(title))
            .on('meta[name="twitter:image"]', meta(OG_IMAGE));

        return rw.transform(indexResp);
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
