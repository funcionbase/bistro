import { type ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * Render alternativo "card-stack" para listings densos cuando la pantalla es
 * demasiado angosta para una `<Table>` con varias columnas (ver
 * FRONTEND_UI_GUIDELINES.md §10).
 *
 * Patron canonico de uso: la pagina renderiza ambos componentes y los alterna
 * via Tailwind responsive utilities (`sm:hidden` / `hidden sm:table`):
 *
 * ```tsx
 * <DataCardList
 *   items={rows}
 *   getKey={(row) => row.id}
 *   className="sm:hidden"
 *   renderCard={(row) => (
 *     <DataCard
 *       title={row.name}
 *       subtitle={row.code}
 *       fields={[
 *         { label: 'Sede', value: row.branch_name },
 *         { label: 'Estado', value: <StatusBadge status={row.status} /> },
 *       ]}
 *       actions={<RowKebab row={row} />}
 *     />
 *   )}
 * />
 * <Table className="hidden sm:table">...</Table>
 * ```
 *
 * Por que no `display: table` con CSS magico: queremos control de orden y de
 * que campos se muestran en mobile (los criticos primero), cosa que un grid de
 * `<Table>` no permite sin clases por columna.
 */

interface DataCardListProps<T> {
    items: T[];
    getKey: (item: T) => string | number;
    renderCard: (item: T) => ReactNode;
    emptyState?: ReactNode;
    className?: string;
}

export function DataCardList<T>({ items, getKey, renderCard, emptyState, className }: DataCardListProps<T>) {
    if (items.length === 0) {
        return emptyState ? <div className={className}>{emptyState}</div> : null;
    }

    return (
        <div className={cn('grid grid-cols-1 gap-3', className)} role="list">
            {items.map((item) => (
                <div key={getKey(item)} role="listitem" className="min-w-0">
                    {renderCard(item)}
                </div>
            ))}
        </div>
    );
}

interface DataCardField {
    label: ReactNode;
    value: ReactNode;
    /** Si true, la celda toma toda la fila (util para descripciones largas). */
    full?: boolean;
}

interface DataCardProps {
    /** Encabezado fuerte (nombre, codigo, periodo). */
    title: ReactNode;
    /** Linea secundaria debajo del titulo (id, slug, descripcion corta). */
    subtitle?: ReactNode;
    /** Lista label/value mostrada como `<dl>` grid de 2 columnas. */
    fields?: DataCardField[];
    /** Footer principal (badge de estado, total, fecha). */
    footer?: ReactNode;
    /** Acciones de fila — usualmente un kebab `<DropdownMenu>` con icon-only. */
    actions?: ReactNode;
    /** Click en toda la card (navegar al detalle). Si se pasa, la card es boton. */
    onClick?: () => void;
    className?: string;
}

/**
 * Card individual para `DataCardList`. Diseno alineado con el patron `Table`
 * v3.1: `bg-card`, `rounded-lg`, `border`, `shadow-sm`. Padding `p-4` igual
 * que `TableCell.px-4 py-3` para preservar densidad operativa.
 */
export function DataCard({ title, subtitle, fields, footer, actions, onClick, className }: DataCardProps) {
    const Wrapper = onClick ? 'button' : 'div';

    return (
        <Wrapper
            type={onClick ? 'button' : undefined}
            onClick={onClick}
            className={cn(
                'bg-card flex w-full flex-col gap-3 rounded-lg border p-4 text-left shadow-sm transition-colors',
                onClick && 'hover:bg-muted/40 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-2',
                className,
            )}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <div className="text-foreground truncate text-sm font-semibold">{title}</div>
                    {subtitle && <div className="text-muted-foreground mt-0.5 truncate text-xs">{subtitle}</div>}
                </div>
                {actions && <div className="shrink-0">{actions}</div>}
            </div>

            {fields && fields.length > 0 && (
                <dl className="grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                    {fields.map((field, idx) => (
                        <div key={idx} className={cn('min-w-0', field.full && 'col-span-2')}>
                            <dt className="text-muted-foreground text-[11px] uppercase tracking-wide">{field.label}</dt>
                            <dd className="text-foreground mt-0.5 truncate">{field.value}</dd>
                        </div>
                    ))}
                </dl>
            )}

            {footer && <div className="border-border/60 flex items-center justify-between gap-2 border-t pt-3 text-xs">{footer}</div>}
        </Wrapper>
    );
}
