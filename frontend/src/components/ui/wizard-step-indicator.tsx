import { Check } from 'lucide-react';
import { Fragment } from 'react';

import { cn } from '@/lib/utils';

interface WizardStep {
    /** Label corto debajo del círculo. Si se omite se usa solo número. */
    label?: string;
}

interface WizardStepIndicatorProps {
    /** Lista de pasos. La longitud define cuántos círculos pintar. */
    steps: ReadonlyArray<WizardStep>;
    /** Paso actual (1-indexed). */
    currentStep: number;
    className?: string;
}

/**
 * Indicador de progreso para wizards multi-step (onboarding, enrollment,
 * configuración inicial). Usa tokens del DS: bg-primary para pasos
 * completados/activos, bg-muted para pendientes, conector entre círculos.
 *
 * Modo compacto (sin labels): círculos chicos pegados. Modo con labels:
 * círculos con texto debajo y conector más ancho.
 *
 * Ver FRONTEND_UI_GUIDELINES.md §6.2 (catálogo) y §11 (formularios).
 */
export function WizardStepIndicator({ steps, currentStep, className }: WizardStepIndicatorProps) {
    const hasLabels = steps.some((s) => s.label);

    return (
        <div className={cn('flex w-full items-start justify-between gap-1 sm:w-auto sm:justify-center sm:gap-0', className)}>
            {steps.map((step, index) => {
                const stepNumber = index + 1;
                const isCompleted = stepNumber < currentStep;
                const isCurrent = stepNumber === currentStep;
                const isLast = index === steps.length - 1;

                return (
                    <Fragment key={index}>
                        <div
                            className={cn(
                                'flex flex-col items-center gap-1.5',
                                hasLabels && 'min-w-0 sm:min-w-[4rem]',
                            )}
                        >
                            <div
                                className={cn(
                                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold transition-colors',
                                    isCompleted && 'bg-primary text-primary-foreground',
                                    isCurrent && 'bg-primary text-primary-foreground ring-primary/20 ring-4',
                                    !isCompleted && !isCurrent && 'bg-muted text-muted-foreground',
                                )}
                                aria-current={isCurrent ? 'step' : undefined}
                            >
                                {isCompleted ? <Check className="h-4 w-4" /> : stepNumber}
                            </div>
                            {step.label && (
                                <span
                                    className={cn(
                                        'max-w-full truncate text-center text-[11px] sm:text-xs sm:whitespace-nowrap',
                                        isCurrent ? 'text-foreground font-medium' : 'text-muted-foreground',
                                    )}
                                    title={step.label}
                                >
                                    {step.label}
                                </span>
                            )}
                        </div>
                        {!isLast && (
                            <div
                                className={cn(
                                    'mt-4 min-w-4 flex-1 transition-colors sm:flex-none',
                                    hasLabels ? 'h-px sm:mx-3 sm:w-10' : 'h-0.5 sm:mx-1 sm:w-10',
                                    isCompleted ? 'bg-primary' : 'bg-border',
                                )}
                            />
                        )}
                    </Fragment>
                );
            })}
        </div>
    );
}
