import { preloadRoute } from '@/spa/route-preload';
import { forwardRef } from 'react';
import { Link as RouterLink, type LinkProps } from 'react-router-dom';

/**
 * Link de navegación del shell SPA.
 *
 * Envuelve el `<Link>` de React Router. Si el destino no está registrado
 * en el router, el catch-all hace hard navigation.
 *
 * Reenvía `ref` y TODAS las props restantes (incluidos atributos `data-*`)
 * a `RouterLink`. Esto es indispensable cuando un componente lo compone vía
 * Radix `Slot`/`asChild` — p.ej. `<SidebarMenuButton asChild isActive>` le
 * inyecta `data-active="true"`: sin reenviarlo, el `<a>` queda con la clase
 * `data-[active=true]:…` pero sin el atributo, y el item activo del sidebar
 * nunca se resalta.
 *
 * Prefetch: en hover/focus dispara `preloadRoute(href)`, que
 * descarga el chunk lazy de la ruta destino (el `prefetch='intent'` de RR es
 * no-op con `React.lazy`). Idempotente y gateado por el mapa de rutas, así que
 * links no registrados (p.ej. detalles) no hacen nada.
 */
interface AppLinkProps extends Omit<LinkProps, 'to' | 'prefetch'> {
    href: string;
    /** Prefetch en hover/focus. Compat de API: se mapea al `prefetch` de React Router. */
    prefetch?: boolean;
}

export const AppLink = forwardRef<HTMLAnchorElement, AppLinkProps>(function AppLink({ href, prefetch, onMouseEnter, onFocus, ...rest }, ref) {
    return (
        <RouterLink
            ref={ref}
            to={href}
            prefetch={prefetch ? 'intent' : 'none'}
            onMouseEnter={(event) => {
                preloadRoute(href);
                onMouseEnter?.(event);
            }}
            onFocus={(event) => {
                preloadRoute(href);
                onFocus?.(event);
            }}
            {...rest}
        />
    );
});
