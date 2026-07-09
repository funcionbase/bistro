import { useSharedData } from '@/lib/shared-data';

/**
 * ¿El plan de facturación ACTIVO de la empresa incluye la feature dada?
 * (ej. `'dian'`, exclusiva del Plan Plus). Lee `activeCompany.plan_features`
 * del bootstrap — sin fetch adicional. `false` mientras no haya empresa activa.
 */
export function useHasPlanFeature(feature: string): boolean {
    const { activeCompany } = useSharedData();

    return activeCompany?.plan_features?.includes(feature) ?? false;
}
