export const MANUAL_BASE = '/manual';

export function manualPageUrl(slug: string): string {
    return slug ? `${MANUAL_BASE}/${slug}` : MANUAL_BASE;
}

export interface WikiPage {
    slug: string;
    label: string;
}

export interface WikiSection {
    label: string;
    pages: WikiPage[];
}

export const wikiSections: WikiSection[] = [
    {
        label: 'para arrancar',
        pages: [
            { slug: '', label: 'Inicio' },
            { slug: 'primeros-pasos', label: 'Primeros pasos' },
        ],
    },
    {
        label: 'el día a día',
        pages: [
            { slug: 'menus', label: 'Menús' },
            { slug: 'pedidos', label: 'Pedidos' },
            { slug: 'caja', label: 'Caja y cobros' },
            { slug: 'mesas', label: 'Mesas y QR' },
            { slug: 'entregas', label: 'Entregas / Domicilios' },
            { slug: 'horarios', label: 'Horarios' },
            { slug: 'chat', label: 'Chat' },
            { slug: 'inventario', label: 'Inventario' },
        ],
    },
    {
        label: 'clientes y mercadeo',
        pages: [
            { slug: 'clientes', label: 'Clientes' },
            { slug: 'cupones', label: 'Cupones y descuentos' },
            { slug: 'fidelizacion', label: 'Puntos de fidelidad' },
        ],
    },
    {
        label: 'números y reportes',
        pages: [
            { slug: 'metricas', label: 'Métricas' },
            { slug: 'alertas', label: 'Alertas' },
            { slug: 'facturacion', label: 'Facturación' },
        ],
    },
    {
        label: 'administración',
        pages: [
            { slug: 'usuarios', label: 'Usuarios, roles y permisos' },
            { slug: 'sedes', label: 'Sedes y bodegas' },
            { slug: 'compras', label: 'Compras y proveedores' },
            { slug: 'planner', label: 'Planificador de turnos' },
            { slug: 'configuracion', label: 'Configuración' },
            { slug: 'whatsapp', label: 'WhatsApp del negocio' },
        ],
    },
    {
        label: 'legal',
        pages: [{ slug: 'legal/contrato', label: 'Contrato de servicio' }],
    },
    {
        label: 'ayuda',
        pages: [{ slug: 'faq', label: 'Preguntas frecuentes' }],
    },
];

export const allWikiPages: WikiPage[] = wikiSections.flatMap((s) => s.pages);

export function getPrevNext(currentSlug: string): { prev: WikiPage | null; next: WikiPage | null } {
    const pages = allWikiPages;
    const idx = pages.findIndex((p) => p.slug === currentSlug);
    return {
        prev: idx > 0 ? pages[idx - 1] : null,
        next: idx < pages.length - 1 ? pages[idx + 1] : null,
    };
}
