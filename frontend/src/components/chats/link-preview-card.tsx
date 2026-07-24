/**
 * Tarjeta de vista previa de un link dentro del chat, estilo WhatsApp — pero
 * SOLO para NUESTROS PROPIOS links de menú (`/menus...`), nunca para links
 * externos recibidos.
 *
 * Por qué solo los propios: previsualizar un link arbitrario obliga a fetchear
 * su HTML (SSRF si lo hace el backend del host compartido; CORS si lo hace el
 * navegador). Como el único link que enviamos nosotros es la carta
 * (`/menus?cart=`, `/menus?branch=`, `/menus/:nit`), y su Open Graph lo
 * conocemos (es el mismo que el Worker inyecta para WhatsApp — ver
 * `worker/index.ts`), la tarjeta se arma en el cliente SIN fetch, SIN backend y
 * SIN SSRF. Cualquier otro link no muestra tarjeta.
 */

// Espejo del OG que `worker/index.ts` inyecta en /menus para el crawler de
// WhatsApp. Mantener sincronizado con TITLE/DESC/OG_IMAGE de ese archivo.
const MENU_PREVIEW = {
    title: 'Nuestro menú — Pedí online',
    description: 'Mirá el menú y hacé tu pedido en segundos. Menú digital, sin filas.',
    image: '/og-menu.png',
};

// Hosts propios cuyos links de menú previsualizamos. El origin actual cubre
// dev/qa/pdn sin hardcodear; pedidos.flexyflow.co aparece en chats viejos.
const OWN_HOSTS = new Set(['pedidos.flexyflow.co']);

/** ¿Es un link de carta NUESTRO? (mismo origin o host propio conocido, path /menus). */
function ownMenuLink(rawUrl: string): boolean {
    try {
        const u = new URL(rawUrl);
        if (u.protocol !== 'https:' && u.protocol !== 'http:') return false;
        const sameOrigin = typeof window !== 'undefined' && u.host === window.location.host;
        const ownHost = sameOrigin || OWN_HOSTS.has(u.host);
        if (!ownHost) return false;
        // pedidos.flexyflow.co es todo carta; bistro solo en /menus.
        return OWN_HOSTS.has(u.host) || u.pathname === '/menus' || u.pathname.startsWith('/menus/');
    } catch {
        return false;
    }
}

export function LinkPreviewCard({ url }: { url: string }) {
    if (!ownMenuLink(url)) return null;

    return (
        <a
            href={url}
            target="_blank"
            rel="noopener noreferrer"
            // bg-background sobre cualquier burbuja (gris del operador, secondary
            // del bot, card del cliente) contrasta: todas son claras/oscuras según
            // el tema y el card sigue al tema.
            className="border-border bg-background mt-2 block overflow-hidden rounded-lg border no-underline transition-opacity hover:opacity-90"
        >
            <img
                src={MENU_PREVIEW.image}
                alt=""
                loading="lazy"
                className="max-h-40 w-full object-cover"
                onError={(e) => {
                    (e.currentTarget as HTMLImageElement).style.display = 'none';
                }}
            />
            <div className="flex flex-col gap-0.5 px-3 py-2">
                <span className="text-foreground text-sm font-medium">{MENU_PREVIEW.title}</span>
                <span className="text-muted-foreground line-clamp-2 text-xs">{MENU_PREVIEW.description}</span>
            </div>
        </a>
    );
}

/** Primera URL http(s) del texto, o null. Se usa para decidir qué link previsualizar. */
export function firstUrl(text: string): string | null {
    const m = text.match(/https?:\/\/[^\s<>"']+/i);
    return m ? m[0].replace(/[.,;:)\]}'"]+$/, '') : null;
}
