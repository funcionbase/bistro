import ManualLayout from '@/layouts/manual-layout';
import { manualPageUrl, wikiSections } from '@/lib/manual-nav';
import { ChevronRight } from 'lucide-react';
import { Link } from 'react-router-dom';

const stats = [
    { value: '+ de 25', label: 'módulos documentados' },
    { value: '100%', label: 'en español colombiano' },
    { value: '$100K/mes', label: 'por empresa · sedes ilimitadas' },
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
                    <div key={s.label} className="rounded-2xl border border-border bg-card p-4 text-center">
                        <p className="text-2xl font-semibold text-primary">{s.value}</p>
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
                {wikiSections.map((section) => (
                        <div key={section.label} className="rounded-xl border border-border bg-card p-3">
                            <p className="mb-1 px-2 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
                                {section.label}
                            </p>
                            <div>
                                {section.pages.map((page) => (
                                        <Link
                                            key={page.slug}
                                            to={manualPageUrl(page.slug)}
                                            className="flex items-center justify-between rounded-lg px-2 py-2 text-sm text-foreground transition-colors hover:bg-muted"
                                        >
                                            <span>{page.label}</span>
                                            <ChevronRight className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                        </Link>
                                    ))}
                            </div>
                        </div>
                    ))}
            </div>

            <h2>Lo que cubre</h2>
            <ul>
                <li>Crear y gestionar menús, platos y recetas.</li>
                <li>Recibir pedidos por web, QR de mesa, domicilio y WhatsApp.</li>
                <li>Cobrar desde la caja con múltiples métodos de pago.</li>
                <li>Controlar el inventario, las bodegas y los costos de insumos.</li>
                <li>Gestionar compras de insumos y el catálogo de proveedores.</li>
                <li>Planificar los turnos del equipo con vista semanal y mensual.</li>
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
