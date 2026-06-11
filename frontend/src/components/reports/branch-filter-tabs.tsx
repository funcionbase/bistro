import { useActiveBranch } from '@/hooks/use-active-branch';
import { useSharedData } from '@/lib/shared-data';
import { cn } from '@/lib/utils';
import { MapPin } from 'lucide-react';

/**
 * Filtro de sede compartido entre Dashboard, Métricas e Informes.
 *
 * Multi-sede (#117):
 *  - 'active' (default): no envía ?branch al backend → BranchScope filtra por la sede
 *    activa del JWT.
 *  - 'all': consulta consolidada cross-sede (requiere `metrics.view_all_branches`).
 *  - <uuid>: consulta una sede específica distinta a la activa (también requiere permiso).
 *
 * Se oculta cuando la empresa solo tiene 1 sede accesible y el usuario no tiene
 * permiso de consolidar (no hay nada que filtrar).
 */
interface BranchFilterTabsProps {
    value: string;
    onChange: (value: string) => void;
    className?: string;
}

const baseTab = 'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors';
const activeTab = 'bg-primary text-primary-foreground';
const inactiveTab = 'border-border text-foreground hover:bg-muted border';

export default function BranchFilterTabs({ value, onChange, className = '' }: BranchFilterTabsProps) {
    const { activeBranch, branches } = useActiveBranch();
    const { permissions = [] } = useSharedData();
    const canViewAllBranches = permissions.includes('metrics.view_all_branches');

    if (branches.length <= 1 && !canViewAllBranches) {
        return null;
    }

    const otherBranches = branches.filter((b) => b.id !== activeBranch?.id);

    return (
        <div className={cn('flex flex-wrap items-center gap-2', className)}>
            <span className="text-muted-foreground inline-flex items-center gap-1 text-xs font-medium">
                <MapPin className="h-3.5 w-3.5" /> Sede:
            </span>
            <button
                onClick={() => onChange('active')}
                className={cn(baseTab, value === 'active' ? activeTab : inactiveTab)}
                title={activeBranch ? `Sede activa: ${activeBranch.name}` : 'Sede activa'}
            >
                {activeBranch ? activeBranch.name : 'Sede actual'}
            </button>
            {canViewAllBranches && (
                <>
                    <button onClick={() => onChange('all')} className={cn(baseTab, value === 'all' ? activeTab : inactiveTab)}>
                        Todas las sedes
                    </button>
                    {otherBranches.map((b) => (
                        <button key={b.id} onClick={() => onChange(b.id)} className={cn(baseTab, value === b.id ? activeTab : inactiveTab)}>
                            {b.name}
                        </button>
                    ))}
                </>
            )}
        </div>
    );
}
