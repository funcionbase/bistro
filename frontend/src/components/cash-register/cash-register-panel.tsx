import ExpenseModal from '@/components/cash-register/expense-modal';
import { AchievementMark } from '@/components/ui/achievement-mark';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { CASH_EXPENSE_CATEGORIES, useCashRegister, type CashExpenseCategory, type CashSession } from '@/hooks/use-cash-register';
import { useCurrencyFormatter } from '@/hooks/use-currency-formatter';
import { useToken } from '@/hooks/use-token';
import type { PaymentMethod } from '@/types';
import { AlertCircle, CheckCircle2, Lock, MinusCircle, Unlock } from 'lucide-react';
import { useState } from 'react';

/**
 * Panel de sesión de caja. Muestra:
 *  - Si NO hay sesión activa: pantalla de "Abrir caja" con input de monto inicial.
 *  - Si SÍ hay sesión activa: banner compacto con info + botón "Cerrar caja".
 *
 * Comparte estado a través de useCashRegister (poll cada 10s — refleja apertura
 * o cierre hechos por otros usuarios de la empresa al instante).
 *
 * Props:
 *  - children: el contenido normal (caja) que solo se renderiza cuando hay
 *    sesión activa. Si no hay sesión, se reemplaza por la pantalla de apertura.
 */

interface Props {
    children: React.ReactNode;
}

export default function CashRegisterPanel({ children }: Props) {
    const token = useToken();
    const { session, loading, error, openSession, closeSession, refresh, recordExpense } = useCashRegister(token);

    if (loading && !session) {
        return <div className="text-muted-foreground flex items-center justify-center py-16 text-sm">Cargando estado de caja…</div>;
    }

    if (!session) {
        return <OpenSessionScreen onOpen={openSession} error={error} />;
    }

    return (
        <>
            <ActiveSessionBanner session={session} onClose={closeSession} onRefresh={refresh} onRecordExpense={recordExpense} />
            {children}
        </>
    );
}

function OpenSessionScreen({ onOpen, error }: { onOpen: (openingAmount: number, notes?: string) => Promise<void>; error: string | null }) {
    const formatCurrency = useCurrencyFormatter();
    const [amount, setAmount] = useState('');
    const [notes, setNotes] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);

    const parsed = parseFloat(amount);
    const isValid = Number.isFinite(parsed) && parsed >= 0;

    const handleSubmit = async () => {
        if (!isValid) {
            setSubmitError('Ingresa un monto válido (puede ser 0).');
            return;
        }
        setSubmitting(true);
        setSubmitError(null);
        try {
            await onOpen(parsed, notes.trim() || undefined);
        } catch (e) {
            setSubmitError((e as Error).message);
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="mx-auto max-w-xl py-12 md:py-20">
            <Card className="rounded-2xl shadow-sm">
                <CardContent className="space-y-8 p-6 md:p-10">
                    <div className="space-y-4 text-center">
                        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-[color:var(--color-status-warning)]/10">
                            <Lock className="h-6 w-6 text-[color:var(--color-status-warning)]" aria-hidden="true" />
                        </div>
                        <span className="bg-secondary text-secondary-foreground inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold tracking-[0.18em] uppercase">
                            Inicio de turno
                        </span>
                        <h1 className="text-foreground text-3xl leading-[1.05] font-semibold tracking-[-0.02em] md:text-4xl">Abrir caja</h1>
                        <p className="text-muted-foreground mx-auto max-w-md text-base">
                            Cuenta el efectivo que hay en la caja y ábrela con ese monto. El cierre del turno se concilia contra esa cifra.
                        </p>
                    </div>

                    <div className="space-y-4">
                        <div className="space-y-1">
                            <Label htmlFor="opening_amount">Efectivo inicial en caja</Label>
                            <Input
                                id="opening_amount"
                                type="number"
                                inputMode="decimal"
                                min={0}
                                step="0.01"
                                value={amount}
                                onChange={(e) => setAmount(e.target.value)}
                                placeholder="0"
                            />
                            {isValid && parsed > 0 && (
                                <p className="text-muted-foreground text-xs">
                                    Iniciarás con: <span className="font-semibold tabular-nums">{formatCurrency(parsed)}</span>
                                </p>
                            )}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="opening_notes">Notas (opcional)</Label>
                            <Input
                                id="opening_notes"
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                placeholder="Ej. monto recibido del turno anterior"
                                maxLength={500}
                            />
                        </div>

                        {(submitError || error) && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{submitError ?? error}</AlertDescription>
                            </Alert>
                        )}

                        <Button
                            onClick={() => void handleSubmit()}
                            disabled={submitting || !isValid}
                            className="w-full"
                            data-cta="abrir-caja"
                            data-cta-location="cash-register-panel"
                        >
                            <Unlock className="mr-2 h-4 w-4" />
                            {submitting ? 'Abriendo…' : 'Abrir caja'}
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}

function ActiveSessionBanner({
    session,
    onClose,
    onRefresh,
    onRecordExpense,
}: {
    session: CashSession;
    onClose: (
        closingAmount: number,
        notes?: string,
    ) => Promise<{
        opening_amount: number;
        closing_amount: number;
        expected_cash: number;
        cash_difference: number;
    }>;
    onRefresh: () => Promise<void>;
    onRecordExpense: (input: {
        amount: number;
        category: CashExpenseCategory;
        description?: string;
        payment_method?: PaymentMethod;
    }) => Promise<void>;
}) {
    const formatCurrency = useCurrencyFormatter();
    const [showClose, setShowClose] = useState(false);
    const [showExpense, setShowExpense] = useState(false);

    const openedAt = session.opened_at
        ? new Date(session.opened_at).toLocaleString('es-CO', {
              dateStyle: 'short',
              timeStyle: 'short',
              timeZone: 'America/Bogota',
          })
        : '—';

    const expenses = session.live.expenses;
    const cashExpensesTotal = expenses?.by_method?.cash ?? 0;

    return (
        <>
            <div
                role="status"
                className="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-md border border-[color:var(--color-status-safe)]/30 bg-[color:var(--color-status-safe)]/10 px-3 py-2 text-sm"
            >
                <div className="flex items-center gap-2 text-[color:var(--color-status-safe)]">
                    <CheckCircle2 className="h-4 w-4" />
                    <span className="font-medium">Caja abierta</span>
                    <span className="text-foreground/80 text-xs">
                        desde {openedAt}
                        {session.opened_by && <> · por {session.opened_by.name}</>}· inicial{' '}
                        <span className="tabular-nums">{formatCurrency(session.opening_amount)}</span>
                    </span>
                </div>
                <div className="flex flex-wrap items-center gap-2 text-xs">
                    {cashExpensesTotal > 0 && (
                        <span className="text-[color:var(--color-status-warning)] tabular-nums">
                            Egresos cash: <strong>−{formatCurrency(cashExpensesTotal)}</strong>
                        </span>
                    )}
                    <span className="text-[color:var(--color-status-safe)] tabular-nums">
                        Esperado en efectivo: <strong>{formatCurrency(session.live.expected_cash)}</strong>
                    </span>
                    <Button size="sm" variant="outline" onClick={() => setShowExpense(true)}>
                        <MinusCircle className="mr-1.5 h-3.5 w-3.5" />
                        Egreso
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setShowClose(true)}
                        data-cta="cerrar-caja"
                        data-cta-location="cash-register-panel"
                    >
                        <Lock className="mr-1.5 h-3.5 w-3.5" />
                        Cerrar caja
                    </Button>
                </div>
            </div>

            {showClose && <CloseSessionDialog session={session} onClose={() => setShowClose(false)} onSubmit={onClose} onRefresh={onRefresh} />}

            {showExpense && <ExpenseModal onClose={() => setShowExpense(false)} onSubmit={onRecordExpense} />}
        </>
    );
}

function CloseSessionDialog({
    session,
    onClose,
    onSubmit,
    onRefresh,
}: {
    session: CashSession;
    onClose: () => void;
    onSubmit: (
        closingAmount: number,
        notes?: string,
    ) => Promise<{
        opening_amount: number;
        closing_amount: number;
        expected_cash: number;
        cash_difference: number;
    }>;
    onRefresh: () => Promise<void>;
}) {
    const formatCurrency = useCurrencyFormatter();
    const [counted, setCounted] = useState('');
    const [notes, setNotes] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<{
        opening_amount: number;
        closing_amount: number;
        expected_cash: number;
        cash_difference: number;
    } | null>(null);

    const expected = session.live.expected_cash;
    const pendingOrders = session.live.pending_orders ?? 0;
    const parsed = parseFloat(counted);
    const isValid = Number.isFinite(parsed) && parsed >= 0;
    const canClose = isValid && pendingOrders === 0;
    const projectedDiff = isValid ? parsed - expected : null;

    const submit = async () => {
        if (!isValid) {
            setError('Ingresa el monto contado (puede ser 0).');
            return;
        }
        setSubmitting(true);
        setError(null);
        try {
            const data = await onSubmit(parsed, notes.trim() || undefined);
            setResult(data);
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setSubmitting(false);
        }
    };

    const cash = session.live.by_method.cash;
    const expenses = session.live.expenses;
    const cashExpensesTotal = expenses?.by_method?.cash ?? 0;

    if (result) {
        const diffExact = Math.abs(result.cash_difference) < 0.01;
        const diffSurplus = !diffExact && result.cash_difference > 0;
        const diffToneClass = diffExact
            ? 'border-[color:var(--color-status-safe)]/30 bg-[color:var(--color-status-safe)]/10 text-[color:var(--color-status-safe)]'
            : diffSurplus
              ? 'border-[color:var(--color-status-warning)]/30 bg-[color:var(--color-status-warning)]/10 text-[color:var(--color-status-warning)]'
              : 'border-[color:var(--color-status-critical)]/30 bg-[color:var(--color-status-critical)]/10 text-[color:var(--color-status-critical)]';

        return (
            <Dialog open onOpenChange={(open) => !open && onClose()}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Caja cerrada</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3 text-sm">
                        {diffExact && (
                            <AchievementMark
                                title="Cuadre exacto"
                                description="Cerraste sin diferencias en caja."
                                size="base"
                                className="pt-2 pb-1"
                            />
                        )}
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Inicial</span>
                            <span className="tabular-nums">{formatCurrency(result.opening_amount)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Esperado en caja</span>
                            <span className="tabular-nums">{formatCurrency(result.expected_cash)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Contado</span>
                            <span className="tabular-nums">{formatCurrency(result.closing_amount)}</span>
                        </div>
                        <div className={`flex justify-between rounded-md border p-2 font-semibold ${diffToneClass}`}>
                            <span>Diferencia</span>
                            <span className="tabular-nums">
                                {result.cash_difference >= 0 ? '+' : '−'}
                                {formatCurrency(Math.abs(result.cash_difference))}
                            </span>
                        </div>
                        {!diffExact && (
                            <p className="text-muted-foreground text-xs">
                                {diffSurplus
                                    ? 'Hay sobrante en caja: contaste más de lo esperado.'
                                    : 'Hay faltante en caja: contaste menos de lo esperado.'}
                            </p>
                        )}
                        <Button
                            onClick={() => {
                                onClose();
                                void onRefresh();
                            }}
                            className="w-full"
                        >
                            Aceptar
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        );
    }

    const projDiffExact = projectedDiff !== null && Math.abs(projectedDiff) < 0.01;
    const projDiffSurplus = projectedDiff !== null && !projDiffExact && projectedDiff > 0;
    const projDiffClass =
        projectedDiff === null
            ? ''
            : projDiffExact
              ? 'text-[color:var(--color-status-safe)]'
              : projDiffSurplus
                ? 'text-[color:var(--color-status-warning)]'
                : 'text-[color:var(--color-status-critical)]';

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Cerrar caja</DialogTitle>
                </DialogHeader>
                <div className="space-y-4">
                    <div className="border-border bg-muted/30 space-y-1 rounded-md border p-3 text-sm">
                        <div className="text-muted-foreground text-[11px] font-semibold tracking-[0.15em] uppercase">Resumen del turno</div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Efectivo inicial</span>
                            <span className="tabular-nums">{formatCurrency(session.opening_amount)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">+ Cobros en efectivo</span>
                            <span className="tabular-nums">{formatCurrency(cash.gross)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">+ Propinas en efectivo</span>
                            <span className="tabular-nums">{formatCurrency(cash.tips)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">− Devoluciones en efectivo</span>
                            <span className="tabular-nums">{formatCurrency(cash.refunds)}</span>
                        </div>
                        {cashExpensesTotal > 0 && (
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">− Egresos en efectivo</span>
                                <span className="tabular-nums">{formatCurrency(cashExpensesTotal)}</span>
                            </div>
                        )}
                        <div className="border-border flex justify-between border-t pt-1 font-semibold">
                            <span>Esperado en caja</span>
                            <span className="tabular-nums">{formatCurrency(expected)}</span>
                        </div>
                        {expenses && expenses.count > 0 && (
                            <div className="text-muted-foreground border-border border-t pt-1 text-xs">
                                {expenses.count} egreso{expenses.count === 1 ? '' : 's'} registrado
                                {expenses.count === 1 ? '' : 's'} · total <span className="tabular-nums">{formatCurrency(expenses.total)}</span>
                                {Object.keys(expenses.by_category).length > 0 && (
                                    <ul className="mt-1 space-y-0.5">
                                        {Object.entries(expenses.by_category).map(([cat, total]) => (
                                            <li key={cat} className="flex justify-between">
                                                <span>{CASH_EXPENSE_CATEGORIES[cat as CashExpenseCategory] ?? cat}</span>
                                                <span className="tabular-nums">−{formatCurrency(total)}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        )}
                    </div>

                    {pendingOrders > 0 && (
                        <Alert variant="warning">
                            <AlertCircle className="h-4 w-4" />
                            <AlertTitle>
                                Hay {pendingOrders} pedido{pendingOrders === 1 ? '' : 's'} sin terminar en el tablero.
                            </AlertTitle>
                            <AlertDescription className="text-xs">
                                Complétalo{pendingOrders === 1 ? '' : 's'}, cancélalo{pendingOrders === 1 ? '' : 's'} o devuélvelo
                                {pendingOrders === 1 ? '' : 's'} antes de cerrar la caja.
                            </AlertDescription>
                        </Alert>
                    )}

                    <div className="space-y-1">
                        <Label htmlFor="counted">Efectivo contado en caja</Label>
                        <Input
                            id="counted"
                            type="number"
                            inputMode="decimal"
                            min={0}
                            step="0.01"
                            value={counted}
                            onChange={(e) => setCounted(e.target.value)}
                            placeholder="0"
                            autoFocus
                        />
                        {projectedDiff !== null && (
                            <p className={`text-xs ${projDiffClass}`}>
                                Diferencia proyectada:{' '}
                                <span className="font-semibold tabular-nums">
                                    {projectedDiff >= 0 ? '+' : '−'}
                                    {formatCurrency(Math.abs(projectedDiff))}
                                </span>{' '}
                                {projDiffExact ? '· cuadre exacto' : projDiffSurplus ? '· sobrante' : '· faltante'}
                            </p>
                        )}
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="closing_notes">Notas (opcional)</Label>
                        <Input
                            id="closing_notes"
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            placeholder="Ej. faltante por consumo de empleados"
                            maxLength={500}
                        />
                    </div>

                    {error && (
                        <Alert variant="destructive" className="p-2 [&>svg]:top-2 [&>svg]:left-2 [&>svg~*]:pl-5">
                            <AlertCircle className="h-3.5 w-3.5" />
                            <AlertDescription className="text-xs">{error}</AlertDescription>
                        </Alert>
                    )}

                    <DialogFooter className="gap-2 sm:gap-2">
                        <Button variant="outline" className="flex-1" onClick={onClose} disabled={submitting}>
                            Cancelar
                        </Button>
                        <Button
                            variant="destructive"
                            className="flex-1"
                            onClick={() => void submit()}
                            disabled={submitting || !canClose}
                            title={pendingOrders > 0 ? 'Resuelve los pedidos pendientes del tablero antes de cerrar' : undefined}
                        >
                            <Lock className="mr-1.5 h-4 w-4" />
                            {submitting ? 'Cerrando…' : 'Confirmar cierre'}
                        </Button>
                    </DialogFooter>
                </div>
            </DialogContent>
        </Dialog>
    );
}
