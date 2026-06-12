<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CashRegisterExpense;
use App\Models\CashRegisterSession;
use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lógica transaccional para abrir/cerrar la sesión de caja por empresa.
 *
 * Reglas:
 *  - Una sesión `open` a la vez por empresa (UNIQUE parcial en BD).
 *  - openSession lanza si ya hay una abierta.
 *  - closeSession calcula expected_cash desde los receipts asociados a la sesión
 *    + propinas en cash de las órdenes cobradas en esa sesión.
 *  - cash_difference = closing_amount − expected_cash.
 */
class CashRegisterService
{
    /**
     * Abre una sesión nueva. Falla si ya existe una `open` para la sede.
     *
     * Multi-sede (#117): la unicidad de sesión abierta es por SEDE (no por empresa).
     * Una empresa con varias sedes puede tener N sesiones abiertas (una por sede).
     */
    public function openSession(
        string $companyNit,
        string $branchId,
        User $openedBy,
        float $openingAmount,
        ?string $notes = null,
        ?string $clientUuid = null,
        ?Carbon $openedAtClient = null,
    ): CashRegisterSession {
        return DB::transaction(function () use ($companyNit, $branchId, $openedBy, $openingAmount, $notes, $clientUuid, $openedAtClient) {
            // Idempotencia offline: si esta apertura (client_uuid) ya se aplicó,
            // devolver la misma sesión — un reintento del sync no abre otra.
            if ($clientUuid !== null) {
                $byClient = CashRegisterSession::query()
                    ->where('company_nit', $companyNit)
                    ->where('branch_id', $branchId)
                    ->where('client_uuid', $clientUuid)
                    ->lockForUpdate()
                    ->first();
                if ($byClient) {
                    return $byClient;
                }
            }

            $existing = CashRegisterSession::query()
                ->where('company_nit', $companyNit)
                ->where('branch_id', $branchId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'cash_register' => 'Ya hay una sesión de caja abierta en esta sede.',
                ]);
            }

            return CashRegisterSession::create([
                'company_nit' => $companyNit,
                'branch_id' => $branchId,
                'client_uuid' => $clientUuid,
                'opened_by_user_id' => $openedBy->id,
                'opened_at' => now(),
                'opened_at_client' => $openedAtClient,
                'opening_amount' => round($openingAmount, 2),
                'status' => 'open',
                'opening_notes' => $notes,
            ]);
        });
    }

    /**
     * Cierra la sesión activa: calcula expected_cash y cash_difference, persiste.
     */
    public function closeSession(
        string $companyNit,
        User $closedBy,
        float $closingAmount,
        ?string $notes = null,
        int $pendingSyncCount = 0,
    ): CashRegisterSession {
        // Modo offline (#140): el cliente envía cuántas órdenes/cobros tiene aún
        // sin sincronizar en su IndexedDB. Política decidida: bloqueo duro sin
        // escape — el cajero DEBE recuperar conexión y drenar la cola antes de
        // cerrar. Sin esto, los receipts llegan tarde y la sesión cerrada no
        // cuadra con la realidad contable.
        if ($pendingSyncCount > 0) {
            throw ValidationException::withMessages([
                'cash_register' => sprintf(
                    'Cierre bloqueado: hay %d operación%s pendiente%s de sincronizar. Conecta a internet y espera al sync antes de cerrar.',
                    $pendingSyncCount,
                    $pendingSyncCount === 1 ? '' : 'es',
                    $pendingSyncCount === 1 ? '' : 's',
                ),
            ]);
        }

        return DB::transaction(function () use ($companyNit, $closedBy, $closingAmount, $notes) {
            $session = CashRegisterSession::forCompany($companyNit)
                ->open()
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw ValidationException::withMessages([
                    'cash_register' => 'No hay una sesión de caja abierta para cerrar.',
                ]);
            }

            // No se puede cerrar caja con pedidos operativos en el tablero. El
            // cajero debe completarlos, cancelarlos o devolverlos primero —
            // si no, los pedidos quedan huérfanos entre sesiones y la
            // conciliación contable se complica.
            $pendingCount = Order::forCompany($companyNit)
                ->whereIn('status', config('orders.operational'))
                ->count();

            if ($pendingCount > 0) {
                throw ValidationException::withMessages([
                    'cash_register' => sprintf(
                        'No se puede cerrar la caja: hay %d pedido%s en el tablero sin terminar. Complétalo%s, cancélalo%s o devuélvelo%s antes de cerrar.',
                        $pendingCount,
                        $pendingCount === 1 ? '' : 's',
                        $pendingCount === 1 ? '' : 's',
                        $pendingCount === 1 ? '' : 's',
                        $pendingCount === 1 ? '' : 's',
                    ),
                ]);
            }

            $expected = $this->computeExpectedCash($session);

            $session->fill([
                'closed_by_user_id' => $closedBy->id,
                'closed_at' => now(),
                'closing_amount' => round($closingAmount, 2),
                'expected_cash' => round($expected, 2),
                'cash_difference' => round($closingAmount - $expected, 2),
                'status' => 'closed',
                'closing_notes' => $notes,
            ])->save();

            return $session;
        });
    }

    /**
     * Cierre provisional desde el sync offline (plan-off.md §6.4). A diferencia
     * del cierre online, NO bloquea por pendientes de sync (el sync ya drenó las
     * ops previas: `cash.close` depende de todas las ops de la sesión) ni por
     * órdenes operativas (el cajero ya cerró físicamente; el server reconcilia).
     *
     * El `closing_amount` (conteo físico) lo capturó el cajero offline y es
     * inmutable; `expected_cash` y `cash_difference` los RECALCULA el server con
     * su verdad (receipts ya aplicados). Idempotente: si ya no hay sesión abierta
     * (re-sync), devuelve la última cerrada de la sede como `duplicate`.
     *
     * @return array{status: string, session: ?CashRegisterSession}
     */
    public function closeSessionFromSync(
        string $companyNit,
        string $branchId,
        User $closedBy,
        float $closingAmount,
        ?string $notes = null,
        ?Carbon $closedAtClient = null,
    ): array {
        return DB::transaction(function () use ($companyNit, $branchId, $closedBy, $closingAmount, $notes, $closedAtClient) {
            $session = CashRegisterSession::query()
                ->where('company_nit', $companyNit)
                ->where('branch_id', $branchId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if (! $session) {
                $last = CashRegisterSession::query()
                    ->where('company_nit', $companyNit)
                    ->where('branch_id', $branchId)
                    ->where('status', 'closed')
                    ->orderByDesc('closed_at')
                    ->first();

                return ['status' => 'duplicate', 'session' => $last];
            }

            $expected = $this->computeExpectedCash($session);

            $session->fill([
                'closed_by_user_id' => $closedBy->id,
                'closed_at' => now(),
                'closed_at_client' => $closedAtClient,
                'closing_amount' => round($closingAmount, 2),
                'expected_cash' => round($expected, 2),
                'cash_difference' => round($closingAmount - $expected, 2),
                'status' => 'closed',
                'closing_notes' => $notes,
            ])->save();

            return ['status' => 'closed', 'session' => $session];
        });
    }

    /**
     * Calcula el efectivo esperado en caja al momento del cierre.
     *
     *   expected =
     *     opening_amount
     *     + SUM(receipts cash gross) + SUM(orders.tip_amount cash en la sesión)
     *     − SUM(receipts cash refunds, abs)
     *
     * Sólo considera receipts vinculados explícitamente a la sesión.
     */
    public function computeExpectedCash(CashRegisterSession $session): float
    {
        $cashGross = (float) PaymentReceipt::where('cash_session_id', $session->id)
            ->where('payment_method', 'cash')
            ->where('amount', '>', 0)
            ->sum('amount');

        $cashRefunds = (float) PaymentReceipt::where('cash_session_id', $session->id)
            ->where('payment_method', 'refund')
            ->sum(DB::raw('-amount'));
        // refunds tienen amount negativo; -amount → positivo. Pero solo cuentan
        // los que devolvieron sobre cobros en efectivo. El método registrado en
        // refund-receipt es 'refund', NO 'cash', así que necesitamos resolver el
        // método ORIGINAL de cada refund (vía la orden y su payment original).
        $cashRefunds = (float) DB::table('payment_receipts as ref')
            ->join('payment_receipts as orig', function ($join) {
                $join->on('orig.order_id', '=', 'ref.order_id')
                    ->whereColumn('orig.payment_method', '!=', DB::raw("'refund'"));
            })
            ->where('ref.cash_session_id', $session->id)
            ->where('ref.payment_method', 'refund')
            ->where('orig.payment_method', 'cash')
            ->sum(DB::raw('-ref.amount'));

        // Propinas en efectivo: sum de orders.tip_amount cuyo receipt principal
        // (no-refund) sea cash y esté vinculado a la sesión.
        $cashTips = (float) DB::table('payment_receipts as pr')
            ->join('orders as o', 'o.id', '=', 'pr.order_id')
            ->where('pr.cash_session_id', $session->id)
            ->where('pr.payment_method', 'cash')
            ->where('o.tip_amount', '>', 0)
            ->sum('o.tip_amount');

        $cashExpenses = (float) CashRegisterExpense::forSession($session->id)
            ->where('payment_method', 'cash')
            ->sum('amount');

        return round((float) $session->opening_amount + $cashGross + $cashTips - $cashRefunds - $cashExpenses, 2);
    }

    /**
     * Registra un egreso contra la sesión activa. Append-only: NO se permite
     * editar ni borrar. Para corregir un egreso erróneo, registrar otro con
     * descripción explícita ("Reverso de #N").
     *
     * @throws ValidationException si la sesión no está abierta, la categoría
     *                             no es válida o el monto no es positivo.
     */
    public function recordExpense(
        CashRegisterSession $session,
        User $createdBy,
        float $amount,
        string $category,
        ?string $description = null,
        string $paymentMethod = 'cash',
        ?string $clientUuid = null,
        ?Carbon $occurredAtClient = null,
    ): CashRegisterExpense {
        $categories = array_keys(config('cash_register.expense_categories', []));
        $methods = config('cash_register.expense_payment_methods', ['cash', 'card', 'transfer']);

        if (! in_array($category, $categories, true)) {
            throw ValidationException::withMessages([
                'category' => 'Categoría de egreso inválida.',
            ]);
        }

        if (! in_array($paymentMethod, $methods, true)) {
            throw ValidationException::withMessages([
                'payment_method' => 'Método de pago inválido para egreso.',
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'El monto del egreso debe ser positivo.',
            ]);
        }

        return DB::transaction(function () use ($session, $createdBy, $amount, $category, $description, $paymentMethod, $clientUuid, $occurredAtClient) {
            // Idempotencia offline: egreso ya aplicado (client_uuid) → devolverlo.
            if ($clientUuid !== null) {
                $byClient = CashRegisterExpense::query()->where('client_uuid', $clientUuid)->lockForUpdate()->first();
                if ($byClient) {
                    return $byClient;
                }
            }

            $fresh = CashRegisterSession::whereKey($session->id)->lockForUpdate()->first();

            if (! $fresh || $fresh->status !== 'open') {
                throw ValidationException::withMessages([
                    'cash_register' => 'La sesión de caja no está abierta — no se pueden registrar egresos.',
                ]);
            }

            return CashRegisterExpense::create([
                'cash_session_id' => $fresh->id,
                'company_nit' => $fresh->company_nit,
                'branch_id' => $fresh->branch_id,
                'client_uuid' => $clientUuid,
                'amount' => round($amount, 2),
                'category' => $category,
                'payment_method' => $paymentMethod,
                'description' => $description,
                'created_by_user_id' => $createdBy->id,
                'created_at' => now(),
                'occurred_at_client' => $occurredAtClient,
            ]);
        });
    }

    /**
     * Egresos de la sesión, ordenados de más reciente a más antiguo. Eager-load
     * del usuario que registró cada egreso.
     *
     * @return Collection<int, CashRegisterExpense>
     */
    public function expensesForSession(CashRegisterSession $session): Collection
    {
        return CashRegisterExpense::forSession($session->id)
            ->with('createdBy:id,name')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Devuelve la sesión `open` actual de la empresa o null. Usado por
     * controllers que requieren caja abierta para operar (defensa profunda).
     */
    public function activeSession(string $companyNit): ?CashRegisterSession
    {
        return CashRegisterSession::forCompany($companyNit)->open()->first();
    }

    /** Sesión abierta de una sede específica (multi-sede). */
    public function activeSessionForBranch(string $companyNit, string $branchId): ?CashRegisterSession
    {
        return CashRegisterSession::query()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->first();
    }

    /** Sesión (de cualquier estado) que abrió una apertura offline concreta. */
    public function sessionByClientUuid(string $companyNit, string $branchId, string $clientUuid): ?CashRegisterSession
    {
        return CashRegisterSession::query()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->where('client_uuid', $clientUuid)
            ->first();
    }

    /**
     * Garantiza que haya una sesión abierta o lanza ValidationException. Devuelve
     * la sesión activa para que el caller la asocie al receipt creado.
     */
    public function requireActiveSession(string $companyNit): CashRegisterSession
    {
        $session = $this->activeSession($companyNit);

        if (! $session) {
            throw ValidationException::withMessages([
                'cash_register' => 'La caja está cerrada. Debes abrirla antes de operar.',
            ]);
        }

        return $session;
    }

    /**
     * Resumen de movimientos por método para una sesión (usado por el modal de
     * cierre para mostrar al cajero qué se espera de la caja física).
     *
     * @return array<string, mixed>
     */
    public function liveSummary(CashRegisterSession $session): array
    {
        $rows = PaymentReceipt::where('cash_session_id', $session->id)
            ->whereNotNull('payment_method')
            ->selectRaw('payment_method,
                SUM(CASE WHEN amount >= 0 THEN amount ELSE 0 END) AS gross,
                SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) AS refunds,
                SUM(amount) AS net,
                COUNT(*) AS receipts_count')
            ->groupBy('payment_method')
            ->get()
            ->keyBy('payment_method');

        $byMethod = [];
        foreach (['cash', 'card', 'transfer', 'refund'] as $m) {
            $row = $rows->get($m);
            $byMethod[$m] = [
                'gross' => (float) ($row->gross ?? 0),
                'refunds' => (float) ($row->refunds ?? 0),
                'net' => (float) ($row->net ?? 0),
                'count' => (int) ($row->receipts_count ?? 0),
                'tips' => 0.0,
            ];
        }

        $tipRows = DB::table('payment_receipts as pr')
            ->join('orders as o', 'o.id', '=', 'pr.order_id')
            ->where('pr.cash_session_id', $session->id)
            ->whereNotNull('pr.payment_method')
            ->where('pr.payment_method', '!=', 'refund')
            ->where('o.tip_amount', '>', 0)
            ->selectRaw('pr.payment_method, SUM(o.tip_amount) AS tips_total')
            ->groupBy('pr.payment_method')
            ->get();

        foreach ($tipRows as $tipRow) {
            if (isset($byMethod[$tipRow->payment_method])) {
                $byMethod[$tipRow->payment_method]['tips'] = (float) $tipRow->tips_total;
            }
        }

        $expenseRows = CashRegisterExpense::forSession($session->id)
            ->selectRaw('payment_method, category, SUM(amount) AS total, COUNT(*) AS count')
            ->groupBy('payment_method', 'category')
            ->get();

        $expensesTotal = 0.0;
        $expensesCount = 0;
        $expensesByMethod = ['cash' => 0.0, 'card' => 0.0, 'transfer' => 0.0];
        $expensesByCategory = [];
        foreach ($expenseRows as $row) {
            $total = (float) $row->total;
            $expensesTotal += $total;
            $expensesCount += (int) $row->count;
            if (isset($expensesByMethod[$row->payment_method])) {
                $expensesByMethod[$row->payment_method] += $total;
            }
            $expensesByCategory[$row->category] = ($expensesByCategory[$row->category] ?? 0.0) + $total;
        }

        $expected = $this->computeExpectedCash($session);

        $orderCount = (int) Order::where('company_nit', $session->company_nit)
            ->whereHas('receipts', fn ($q) => $q->where('cash_session_id', $session->id)->where('payment_method', '!=', 'refund'))
            ->count();

        // Pedidos en estados operativos (pending/in_kitchen/ready/in_transit) —
        // bloquean el cierre de caja. El frontend lee este número para
        // mostrar un aviso preventivo en el modal de cierre.
        $pendingOrders = (int) Order::forCompany($session->company_nit)
            ->whereIn('status', config('orders.operational'))
            ->count();

        return [
            'by_method' => $byMethod,
            'expected_cash' => round($expected, 2),
            'orders_count' => $orderCount,
            'pending_orders' => $pendingOrders,
            'expenses' => [
                'total' => round($expensesTotal, 2),
                'count' => $expensesCount,
                'by_method' => array_map(fn ($v) => round($v, 2), $expensesByMethod),
                'by_category' => array_map(fn ($v) => round($v, 2), $expensesByCategory),
            ],
        ];
    }
}
