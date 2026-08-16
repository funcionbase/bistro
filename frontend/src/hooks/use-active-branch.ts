import { useSharedData } from '@/lib/shared-data';
import { type Branch } from '@/types';

/**
 * Hook que devuelve la sede activa y la lista de sedes accesibles del usuario
 * desde el contexto SharedData (agnóstico Inertia/SPA).
 *
 * Multi-sede: el `activeBranch` es null si el usuario tiene N sedes y
 * todavía no ha seleccionado una. Las páginas que requieran sede activa deben
 * redirigir a `route('auth.branch-selector')` cuando esto ocurra.
 */
export function useActiveBranch(): {
    activeBranch: Branch | null;
    branches: Branch[];
} {
    const sharedData = useSharedData();

    return {
        activeBranch: sharedData.activeBranch ?? null,
        branches: sharedData.branches ?? [],
    };
}
