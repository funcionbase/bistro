import { AppLink } from '@/components/app-link';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { type SetupStep, useSetupGuide } from '@/hooks/use-setup-guide';
import { ArrowRight, CheckCircle2, X } from 'lucide-react';

function StepRow({ step, marker }: { step: SetupStep; marker: string }) {
    return (
        <div className="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
            {step.completed ? (
                <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-[color:var(--color-status-safe)]" />
            ) : (
                <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-muted-foreground text-xs text-muted-foreground">
                    {marker}
                </span>
            )}

            <div className="min-w-0 flex-1">
                <p className={`text-sm font-medium ${step.completed ? 'text-muted-foreground line-through' : ''}`}>{step.title}</p>
                {/* La descripción explica el "para qué" de cada paso: la ocultamos
                    solo cuando ya está hecho para no saturar la lista. */}
                {!step.completed && <p className="text-xs text-muted-foreground">{step.description}</p>}
            </div>

            {!step.completed && (
                <Button asChild variant="outline" size="sm" className="mt-0.5 shrink-0 gap-1">
                    <AppLink href={step.url}>
                        Ir
                        <ArrowRight className="h-3 w-3" />
                    </AppLink>
                </Button>
            )}
        </div>
    );
}

export function SetupGuide() {
    const { data, isLoading, dismiss } = useSetupGuide();

    if (isLoading || !data || data.dismissed) return null;

    const essentials = data.steps.filter((s) => !s.optional);
    const optionals = data.steps.filter((s) => s.optional);
    const essentialsDone = essentials.filter((s) => s.completed).length;

    return (
        <Card className="p-6">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h2 className="text-base font-semibold">Configura tu restaurante</h2>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        {essentialsDone} de {essentials.length} pasos esenciales
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    className="-mr-2 -mt-1 shrink-0"
                    onClick={() => dismiss.mutate()}
                    disabled={dismiss.isPending}
                    aria-label="Ocultar guía de configuración"
                >
                    <X className="h-4 w-4" />
                </Button>
            </div>

            {data.allDone ? (
                <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-[color:var(--color-status-safe)] bg-[color:var(--color-status-safe)]/10 px-4 py-3">
                    <p className="text-sm font-medium text-[color:var(--color-status-success)]">
                        ¡Listo! Tu restaurante está configurado. Ya puedes empezar a recibir pedidos.
                    </p>
                    <Button variant="outline" size="sm" onClick={() => dismiss.mutate()} disabled={dismiss.isPending}>
                        Ocultar guía
                    </Button>
                </div>
            ) : (
                <>
                    <div className="mt-4 divide-y divide-border">
                        {essentials.map((step, index) => (
                            <StepRow key={step.id} step={step} marker={String(index + 1)} />
                        ))}
                    </div>

                    {optionals.length > 0 && (
                        <>
                            <p className="mt-5 mb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                Opcional · mejora tu operación
                            </p>
                            <div className="divide-y divide-border">
                                {optionals.map((step) => (
                                    <StepRow key={step.id} step={step} marker="+" />
                                ))}
                            </div>
                        </>
                    )}
                </>
            )}
        </Card>
    );
}
