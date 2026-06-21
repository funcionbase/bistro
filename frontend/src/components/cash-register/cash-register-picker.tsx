import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type { CashRegister } from '@/hooks/use-cash-register';
import { useSharedData } from '@/lib/shared-data';
import { CheckCircle2, Lock, Unlock, UserCheck } from 'lucide-react';

interface Props {
    registers: CashRegister[];
    onSelect: (register: CashRegister) => void;
}

/**
 * Selector de caja para sedes multi-caja. Solo se muestra cuando la sede
 * tiene más de una caja activa. El usuario elige qué caja va a operar;
 * la elección se persiste por dispositivo en localStorage.
 *
 * - Caja abierta por el mismo usuario → "Continuar"
 * - Caja libre (sin sesión) → "Abrir caja"
 * - Caja abierta por otro → "Tomar caja" (requiere `cash_register.operate_others`)
 */
export default function CashRegisterPicker({ registers, onSelect }: Props) {
    const { permissions = [] } = useSharedData();
    const canOperateOthers = permissions.includes('cash_register.operate_others');

    return (
        <div className="mx-auto max-w-2xl py-12">
            <div className="mb-6 space-y-1 text-center">
                <span className="bg-secondary text-secondary-foreground inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold tracking-[0.18em] uppercase">
                    Inicio de turno
                </span>
                <h1 className="text-foreground text-2xl font-semibold tracking-tight">¿Qué caja vas a operar?</h1>
                <p className="text-muted-foreground text-sm">Elegí la caja asignada a tu punto de venta.</p>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                {registers.map((register) => {
                    const openSession = register.open_session;
                    const isFree = !openSession;
                    const isOpen = !!openSession;
                    const canTake = isOpen && canOperateOthers;
                    const cannotTake = isOpen && !canOperateOthers;

                    return (
                        <Card
                            key={register.id}
                            className={`transition-shadow ${cannotTake ? 'opacity-60' : 'hover:shadow-md'}`}
                        >
                            <CardContent className="flex flex-col gap-3 p-4">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="font-semibold truncate">{register.name}</p>
                                        {isOpen && openSession.opened_by && (
                                            <p className="text-muted-foreground text-xs mt-0.5 truncate">
                                                Operando: {openSession.opened_by.name}
                                            </p>
                                        )}
                                    </div>
                                    {isFree ? (
                                        <Badge variant="secondary" className="shrink-0">
                                            <Unlock className="mr-1 h-3 w-3" />
                                            Libre
                                        </Badge>
                                    ) : (
                                        <Badge variant="safe" className="shrink-0">
                                            <CheckCircle2 className="mr-1 h-3 w-3" />
                                            Abierta
                                        </Badge>
                                    )}
                                </div>

                                {isFree ? (
                                    <Button
                                        size="sm"
                                        className="w-full"
                                        onClick={() => onSelect(register)}
                                    >
                                        <Lock className="mr-1.5 h-3.5 w-3.5" />
                                        Abrir caja
                                    </Button>
                                ) : canTake ? (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="w-full"
                                        onClick={() => onSelect(register)}
                                    >
                                        <UserCheck className="mr-1.5 h-3.5 w-3.5" />
                                        Tomar caja
                                    </Button>
                                ) : (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="w-full"
                                        disabled
                                    >
                                        <Lock className="mr-1.5 h-3.5 w-3.5" />
                                        Ocupada
                                    </Button>
                                )}

                                {cannotTake && (
                                    <p className="text-muted-foreground text-xs text-center">
                                        Solo puedes ver su estado.
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    );
                })}
            </div>
        </div>
    );
}
