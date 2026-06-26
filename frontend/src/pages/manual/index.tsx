import ManualLayout from '@/layouts/manual-layout';
import { manualPageUrl, wikiSections } from '@/lib/manual-nav';
import { ChevronRight } from 'lucide-react';
import { Link } from 'react-router-dom';

const stats = [
    { value: '+ de 20', label: 'módulos documentados' },
    { value: '100%', label: 'en español colombiano' },
    { value: 'gratuito', label: 'sin registro' },
];

export default function ManualIndex() {
    return (
        <ManualLayout
            currentSlug=""
            pageTitle="Manual de bistro.flexyflow.co"
            pageDescription="Todo lo que necesitas para dominar el panel: desde el primer ingreso hasta la facturación electrónica DIAN."
            metaTitle="Manual — bistro.flexyflow.co"
            metaDescription="Manual de usuario de bistro.flexyflow.co: guías de pedidos, caja, menús, clientes, reportes, DIAN y más."
        >
            {/* Stats */}
            <div className="mb-8 grid grid-cols-3 gap-4">
                {stats.map((s) => (
                    <div key={s.label} className="rounded-xl border border-border bg-card p-4 text-center">
                        <p className="text-2xl font-bold text-primary">{s.value}</p>
                        <p className="mt-0.5 text-xs text-muted-foreground">{s.label}</p>
                    </div>
                ))}
            </div>

            <p>
                Este manual cubre todas las funcionalidades de{' '}
                <a href="https://bistro.flexyflow.co" target="_blank" rel="noopener noreferrer">
                    bistro.flexyflow.co
                </a>
                , el panel de gestión para restaurantes, bares, panaderías y dark kitchens. Está organizado por área
                funcional — puedes leerlo de principio a fin o ir directo a la sección que necesitas.
            </p>

            <h2>Secciones del manual</h2>

            <div className="not-prose grid gap-4 sm:grid-cols-2">
                {wikiSections
                    .filter((s) => s.label !== 'legal')
                    .map((section) => (
                        <div key={section.label} className="rounded-xl border border-border bg-card p-3">
                            <p className="mb-1 px-2 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
                                {section.label}
                            </p>
                            <div>
                                {section.pages.map((page) => {
                                    const isExternal = page.slug === 'legal/contrato';
                                    if (isExternal) {
                                        return (
                                            <a
                                                key={page.slug}
                                                href="https://flexyflow.co/wiki/restaurante/legal/contrato/"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="flex items-center justify-between rounded-lg px-2 py-2 text-sm text-foreground transition-colors hover:bg-muted"
                                            >
                                                <span>{page.label}</span>
                                                <svg className="h-3 w-3 shrink-0 text-muted-foreground" viewBox="0 0 12 12" fill="none">
                                                    <path d="M2 10L10 2M10 2H5M10 2V7" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
                                                </svg>
                                            </a>
                                        );
                                    }
                                    return (
                                        <Link
                                            key={page.slug}
                                            to={manualPageUrl(page.slug)}
                                            className="flex items-center justify-between rounded-lg px-2 py-2 text-sm text-foreground transition-colors hover:bg-muted"
                                        >
                                            <span>{page.label}</span>
                                            <ChevronRight className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                        </Link>
                                    );
                                })}
                            </div>
                        </div>
                    ))}
            </div>

            <h2>Lo que cubre</h2>
            <ul>
                <li>Crear y gestionar menús, platos y recetas.</li>
                <li>Recibir pedidos por web, QR de mesa, domicilio y WhatsApp.</li>
                <li>Cobrar desde la caja con múltiples métodos de pago.</li>
                <li>Controlar el inventario y los costos de insumos.</li>
                <li>Ver métricas en vivo e ingeniería de menú.</li>
                <li>Gestionar clientes, cupones y puntos de fidelidad.</li>
                <li>Emitir facturas electrónicas ante la DIAN.</li>
                <li>Administrar usuarios, roles y varias sedes bajo el mismo NIT.</li>
            </ul>

            <div className="callout callout-info">
                <p>
                    <strong>¿Ejemplo rápido?</strong> Una pizzería puede tener su menú publicado en{' '}
                    <a href="https://bistro.flexyflow.co" target="_blank" rel="noopener noreferrer">
                        bistro.flexyflow.co
                    </a>
                    , recibir pedidos por WhatsApp, cobrarlos desde la caja con datáfono o efectivo, imprimir la
                    comanda en la cocina y generar la tirilla DIAN, todo sin salir de la app.
                </p>
            </div>
        </ManualLayout>
    );
}
