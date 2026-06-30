import { AppLink } from '@/components/app-link';
import { CalendarDays, CalendarRange } from 'lucide-react';

import { cn } from '@/lib/utils';

type PlannerView = 'week' | 'month';

interface PlannerViewTabsProps {
    active: PlannerView;
    className?: string;
}

const baseTab = 'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors';
const activeTab = 'bg-primary text-primary-foreground';
const inactiveTab = 'border-border text-foreground hover:bg-muted border';

export function PlannerViewTabs({ active, className }: PlannerViewTabsProps) {
    return (
        <div className={cn('flex items-center gap-2', className)}>
            <AppLink
                href="/planner?view=week"
                aria-current={active === 'week' ? 'page' : undefined}
                className={cn(baseTab, active === 'week' ? activeTab : inactiveTab)}
            >
                <CalendarRange className="h-4 w-4" /> Semana
            </AppLink>
            <AppLink
                href="/planner?view=month"
                aria-current={active === 'month' ? 'page' : undefined}
                className={cn(baseTab, active === 'month' ? activeTab : inactiveTab)}
            >
                <CalendarDays className="h-4 w-4" /> Mes
            </AppLink>
        </div>
    );
}
