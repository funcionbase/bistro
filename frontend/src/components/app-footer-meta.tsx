import { AppLink } from '@/components/app-link';
import { useSharedData } from '@/lib/shared-data';
import { BookOpen } from 'lucide-react';

// Inyectadas por Vite en build time (vite.config.ts `define`).
declare const __FRONTEND_VERSION__: string;
declare const __BACKEND_VERSION__: string;

/**
 * Pie global del área autenticada: link al manual de usuario y versiones
 * desplegadas (fv = frontend, bv = backend). Se monta una sola vez en
 * AppSidebarLayout, después del contenido de cada página; `mt-auto` lo
 * empuja al fondo cuando la página es más corta que el viewport.
 *
 * fv = versión del bundle cargado (build time — correcto por construcción).
 * bv = versión que reporta el backend en runtime vía /api/v1/bootstrap
 * (`versions.backend`); así refleja lo realmente desplegado sin importar
 * cuándo se compiló el frontend. Fallback al valor horneado en build solo
 * para backends previos a 1.30.2 que no envían el campo.
 */
export function AppFooterMeta() {
    const backendVersion = useSharedData().versions?.backend ?? __BACKEND_VERSION__;

    return (
        <div className="border-border text-muted-foreground mt-auto flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t px-4 py-4 text-xs sm:px-6">
            <AppLink href="/manual" className="hover:text-foreground inline-flex items-center gap-1.5 transition-colors">
                <BookOpen className="h-3.5 w-3.5" />
                Manual de usuario
            </AppLink>
            <span className="opacity-70">
                fv{__FRONTEND_VERSION__} bv{backendVersion}
            </span>
        </div>
    );
}
