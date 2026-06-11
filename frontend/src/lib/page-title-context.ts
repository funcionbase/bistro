import { createContext, useContext } from 'react';

/**
 * Contexto que expone el título de la página actual hacia componentes
 * del shell SPA (header, breadcrumb) que viven más arriba en el tree.
 *
 * `PageShell` setea el título vía `setPageTitle()`. El `AutoBreadcrumb`
 * lo lee como leaf cuando la ruta tiene segmento dinámico.
 */
export interface PageTitleContextValue {
    title: string;
    setPageTitle: (title: string) => void;
}

export const PageTitleContext = createContext<PageTitleContextValue>({
    title: '',
    setPageTitle: () => {},
});

export function usePageTitle(): string {
    return useContext(PageTitleContext).title;
}

export function useSetPageTitle(): (title: string) => void {
    return useContext(PageTitleContext).setPageTitle;
}
