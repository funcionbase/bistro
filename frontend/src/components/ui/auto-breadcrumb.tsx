import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { buildBreadcrumb } from '@/lib/breadcrumb-routes';
import { usePageTitle } from '@/lib/page-title-context';
import { cn } from '@/lib/utils';
import { Fragment } from 'react';
import { Link, useLocation } from 'react-router-dom';

interface AutoBreadcrumbProps {
    /**
     * Override manual del último crumb. Si se omite, se toma del
     * `PageTitleContext` que `PageShell` actualiza con su `title`.
     */
    leafLabel?: string;
    className?: string;
}

/**
 * Breadcrumb autogenerado a partir de `useLocation().pathname` y el mapeo
 * declarado en `lib/breadcrumb-routes.ts`. Cero configuración por página:
 * sigue la jerarquía del sidebar (FRONTEND_UI_GUIDELINES §6.2).
 *
 * Renderiza en el AppSidebarHeader del shell SPA — único punto de
 * verdad para la navegación contextual.
 */
export function AutoBreadcrumb({ leafLabel, className }: AutoBreadcrumbProps) {
    const { pathname } = useLocation();
    const pageTitle = usePageTitle();
    const items = buildBreadcrumb(pathname, leafLabel ?? (pageTitle || undefined));

    if (items.length === 0) {
        return null;
    }

    return (
        <Breadcrumb className={cn('min-w-0', className)}>
            <BreadcrumbList className="flex-nowrap">
                {items.map((item, idx) => {
                    const isLast = idx === items.length - 1;
                    return (
                        <Fragment key={`${idx}-${item.label}`}>
                            <BreadcrumbItem className="min-w-0">
                                {isLast || !item.href ? (
                                    <BreadcrumbPage className="text-foreground truncate">{item.label}</BreadcrumbPage>
                                ) : (
                                    <BreadcrumbLink asChild>
                                        <Link to={item.href} className="hover:text-foreground truncate">
                                            {item.label}
                                        </Link>
                                    </BreadcrumbLink>
                                )}
                            </BreadcrumbItem>
                            {!isLast && <BreadcrumbSeparator />}
                        </Fragment>
                    );
                })}
            </BreadcrumbList>
        </Breadcrumb>
    );
}
