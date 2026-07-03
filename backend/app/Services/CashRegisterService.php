<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CashRegister;
use App\Models\CashRegisterExpense;
use App\Models\CashRegisterIncome;
use App\Models\CashRegisterSession;
use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
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
        ?string $cashRegisterId = null,
    ): CashRegisterSession {
        return DB::transaction(function () use ($companyNit, $branchId, $openedBy, $openingAmount, $notes, $clientUuid, $openedAtClient, $cashRegisterId) {
            // Multi-caja (#117): toda sesión cuelga de una caja. Si el caller no
            // especifica cuál (cliente legacy / sede mono-caja), se usa la "Caja
            // principal" de la sede. El selector explícito lo envía Fase 2.
            $register = $cashRegisterId !== null
                ? $this->requireRegister($companyNit, $branchId, $cashRegisterId)
                : $this->defaultRegister($companyNit, $branchId);

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

            // Unicidad por CAJA (no por sede): una sede puede tener N cajas
            // abiertas a la vez, pero cada caja máximo una sesión `open`.
            $existing = CashRegisterSession::query()
                ->where('cash_register_id', $register->id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'cash_register' => 'Ya hay una sesión de caja abierta en esta caja.',
                ]);
            }

            return CashRegisterSession::create([
                'company_nit' => $companyNit,
                'branch_id' => $branchId,
                'cash_register_id' => $register->id,
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
     * Caja por defecto de una sede ("Caja principal"). La crea si la sede aún
     * no tiene ninguna caja vigente (onboarding / sede nueva). Resiliente a
     * carreras N-instance vía el UNIQUE parcial por nombre.
     */
    public function defaultRegister(string $companyNit, string $branchId): CashRegister
    {
        $existing = CashRegister::query()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->whereNull('archived_at')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return CashRegister::create([
                'company_nit' => $companyNit,
                'branch_id' => $branchId,
                'name' => 'Caja principal',
                'is_active' => true,
                'sort_order' => 0,
            ]);
        } catch (QueryException $e) {
            // Carrera: otra request sembró la caja al mismo tiempo (UNIQUE
            // parcial por nombre). Re-resolvemos la existente.
            return CashRegister::query()
                ->where('company_nit', $companyNit)
                ->where('branch_id', $branchId)
                ->whereNull('archived_at')
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->firstOrFail();
        }
    }

    /**
     * Resuelve una caja por id validando empresa + sede (defensa anti-tampering)
     * y que esté vigente. Lanza ValidationException si no cuadra.
     */
    public function requireRegister(string $companyNit, string $branchId, string $cashRegisterId): CashRegister
    {
        $register = CashRegister::query()
            ->where('id', $cashRegisterId)
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->whereNull('archived_at')
            ->first();

        if (! $register) {
            throw ValidationException::withMessages([
                'cash_register_id' => 'La caja seleccionada no existe en esta sede o fue archivada.',
            ]);
        }

        return $register;
    }

    /**
     * Cierra la sesión activa de una sede: calcula expected_cash y
     * cash_difference, persiste.
     *
     * Multi-sede (#117): la sesión a cerrar y el conteo de pedidos operativos
     * que bloquean el cierre se resuelven por SEDE (no por empresa). Sin el
     * filtro de sede, en una empresa con varias sedes abiertas `first()` podía
     * cerrar la sesión equivocada y el bloqueo contaba pedidos de otras sedes.
     */
    public function closeSession(
        string $companyNit,
        string $branchId,
        User $closedBy,
        float $closingAmount,
        ?string $notes = null,
        int $pendingSyncCount = 0,
        ?string $cashSessionId = null,
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

        return DB::transaction(function () use ($companyNit, $branchId, $closedBy, $closingAmount, $notes, $cashSessionId) {
            // Multi-caja (#117): se cierra la caja indicada por `cashSessionId`.
            // Fallback legacy: si no se indica y hay exactamente una abierta en
            // la sede, esa; si hay varias, se exige elegir.
            $query = CashRegisterSession::query()
                ->where('company_nit', $companyNit)
                ->where('branch_id', $branchId)
                ->where('status', 'open');

            if ($cashSessionId !== null && $cashSessionId !== '') {
                $query->where('id', $cashSessionId);
            } elseif ($this->openSessionsForBranch($companyNit, $branchId)->count() > 1) {
                throw ValidationException::withMessages([
                    'cash_session_id' => 'Hay varias cajas abiertas en esta sede. Indica cuál vas a cerrar.',
                ]);
            }

            $session = $query->lockForUpdate()->first();

            if (! $session) {
                throw ValidationException::withMessages([
                    'cash_register' => 'No hay una sesión de caja abierta para cerrar.',
                ]);
            }

            // No se puede cerrar caja con pedidos operativos en el tablero. El
            // cajero debe completarlos, cancelarlos o devolverlos primero —
            // si no, los pedidos quedan huérfanos entre sesiones y la
            // conciliación contable se complica. El conteo es POR SEDE.
            //
            // Multi-caja (#117, decisión de producto): cerrar UNA caja no se
            // bloquea por pedidos operativos si quedan OTRAS cajas abiertas en
            // la sede (esas los cobran). Solo se bloquea el cierre de la ÚLTIMA
            // caja abierta de la sede.
            $otherOpen = CashRegisterSession::query()
                ->where('company_nit', $companyNit)
                ->where('branch_id', $branchId)
                ->where('status', 'open')
                ->where('id', '!=', $session->id)
                ->exists();

            $pendingCount = $otherOpen ? 0 : Order::query()
                ->where('company_nit', $companyNit)
                ->where('branch_id', $branchId)
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
        ?string $cashSessionId = null,
    ): array {
        return DB::transaction(function () use ($companyNit, $branchId, $closedBy, $closingAmount, $notes, $closedAtClient, $cashSessionId) {
            // Multi-caja (#117): si el caller resolvió la sesión operada, se
            // cierra ESA; sin id (cliente legacy mono-caja) la única abierta.
            $session = CashRegisterSession::query()
                ->where('company_nit', $companyNit)
                ->where('branch_id', $branchId)
                ->where('status', 'open')
                ->when($cashSessionId !== null, fn ($q) => $q->where('id', $cashSessionId))
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

        $cashRefunds = $this->computeCashRefundsForSession($session->id);

        // Propinas en efectivo: por receipt (`payment_data.tip_amount`, que todo
        // creador de receipts persiste). Sumar `orders.tip_amount` vía JOIN
        // multiplicaba la propina por cada receipt cash de la misma orden
        // (pagos parciales por comensal) y la contaba entera en cada método/
        // sesión cuando el cobro se dividía — inflando expected_cash y
        // generando faltantes fantasma en el arqueo.
        $cashTips = (float) PaymentReceipt::where('cash_session_id', $session->id)
            ->where('payment_method', 'cash')
            ->sum(DB::raw("COALESCE((payment_data->>'tip_amount')::numeric, 0)"));

        $cashExpenses = (float) CashRegisterExpense::forSession($session->id)
            ->where('payment_method', 'cash')
            ->sum('amount');

        // Entradas de efectivo (no-venta) inyectadas a la caja durante el turno.
        // Suman al esperado igual que los cobros en efectivo.
        $cashIncomes = (float) CashRegisterIncome::forSession($session->id)
            ->where('payment_method', 'cash')
            ->sum('amount');

        return round((float) $session->opening_amount + $cashGross + $cashTips + $cashIncomes - $cashRefunds - $cashExpenses, 2);
    }

    /**
     * Refunds en efectivo de una sesión: solo los PaymentReceipt con
     * payment_method='refund' cuyo cobro original fue en efectivo.
     * El JOIN resuelve el método original porque los refunds siempre se
     * registran con payment_method='refund', no con el método original.
     */
    private function computeCashRefundsForSession(string $sessionId): float
    {
        // whereExists (no JOIN): una orden pagada con N receipts cash (pagos
        // parciales de mesa) multiplicaba el refund N veces en el JOIN,
        // inflando el descuento sobre expected_cash.
        return (float) DB::table('payment_receipts as ref')
            ->where('ref.cash_session_id', $sessionId)
            ->where('ref.payment_method', 'refund')
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('payment_receipts as orig')
                    ->whereColumn('orig.order_id', 'ref.order_id')
                    ->where('orig.payment_method', 'cash');
            })
            ->sum(DB::raw('-ref.amount'));
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
        bool $enforceNonNegativeCash = false,
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

        return DB::transaction(function () use ($session, $createdBy, $amount, $category, $description, $paymentMethod, $clientUuid, $occurredAtClient, $enforceNonNegativeCash) {
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

            // Chequeo anti-negativo DENTRO del lock de la sesión: dos egresos
            // concurrentes quedan serializados y el segundo ve el efectivo ya
            // descontado. Solo aplica al flujo online (el replay offline no se
            // bloquea: el egreso ya ocurrió físicamente en el cajón).
            if ($enforceNonNegativeCash && $paymentMethod === 'cash') {
                $expectedCash = $this->computeExpectedCash($fresh);
                if (round($amount, 2) > $expectedCash) {
                    throw ValidationException::withMessages([
                        'amount' => [
                            'El egreso supera el efectivo disponible en caja. '
                            .'Disponible: $'.number_format($expectedCash, 0, ',', '.')
                            .'. Para corregir un egreso anterior, registra un ingreso en sentido contrario.',
                        ],
                    ]);
                }
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
     * Registra una entrada de efectivo (aporte de socio, préstamo, ajuste…)
     * contra una sesión abierta. Espejo de recordExpense: append-only,
     * idempotente por client_uuid, exige sesión `open`. Incrementa el efectivo
     * esperado cuando `paymentMethod = cash`.
     */
    public function recordIncome(
        CashRegisterSession $session,
        User $createdBy,
        float $amount,
        string $category,
        ?string $description = null,
        string $paymentMethod = 'cash',
        ?string $clientUuid = null,
        ?Carbon $occurredAtClient = null,
    ): CashRegisterIncome {
        $categories = array_keys(config('cash_register.income_categories', []));
        $methods = config('cash_register.income_payment_methods', ['cash', 'card', 'transfer']);

        if (! in_array($category, $categories, true)) {
            throw ValidationException::withMessages([
                'category' => 'Categoría de ingreso inválida.',
            ]);
        }

        if (! in_array($paymentMethod, $methods, true)) {
            throw ValidationException::withMessages([
                'payment_method' => 'Método de pago inválido para ingreso.',
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'El monto del ingreso debe ser positivo.',
            ]);
        }

        return DB::transaction(function () use ($session, $createdBy, $amount, $category, $description, $paymentMethod, $clientUuid, $occurredAtClient) {
            // Idempotencia offline: ingreso ya aplicado (client_uuid) → devolverlo.
            if ($clientUuid !== null) {
                $byClient = CashRegisterIncome::query()->where('client_uuid', $clientUuid)->lockForUpdate()->first();
                if ($byClient) {
                    return $byClient;
                }
            }

            $fresh = CashRegisterSession::whereKey($session->id)->lockForUpdate()->first();

            if (! $fresh || $fresh->status !== 'open') {
                throw ValidationException::withMessages([
                    'cash_register' => 'La sesión de caja no está abierta — no se pueden registrar entradas.',
                ]);
            }

            return CashRegisterIncome::create([
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
     * Entradas de la sesión, de más reciente a más antigua, con el usuario que
     * registró cada una.
     *
     * @return Collection<int, CashRegisterIncome>
     */
    public function incomesForSession(CashRegisterSession $session): Collection
    {
        return CashRegisterIncome::forSession($session->id)
            ->with('createdBy:id,name')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Sesión abierta de una sede específica. Multi-caja (#117): si la sede tiene
     * varias cajas abiertas devuelve la primera por `opened_at` — solo apto para
     * flujos legacy mono-caja. Para cobros usar `resolveSessionForCharge`.
     */
    public function activeSessionForBranch(string $companyNit, string $branchId): ?CashRegisterSession
    {
        return CashRegisterSession::query()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->orderBy('opened_at')
            ->first();
    }

    /**
     * Sesiones abiertas de una sede (una por caja). @return Collection<int, CashRegisterSession>
     */
    public function openSessionsForBranch(string $companyNit, string $branchId): Collection
    {
        return CashRegisterSession::query()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->get();
    }

    /**
     * Resuelve la sesión sobre la que recae un cobro/egreso/cierre.
     *
     * - Si el cliente envía `cashSessionId`, se valida (open + empresa + sede).
     * - Si no (cliente legacy / sede mono-caja) y hay EXACTAMENTE una caja
     *   abierta en la sede, se usa esa (retrocompatibilidad).
     * - Si hay varias abiertas y no se especifica, se exige elegir (la
     *   ambigüedad descuadraría cajas).
     */
    public function resolveSessionForCharge(string $companyNit, string $branchId, ?string $cashSessionId): CashRegisterSession
    {
        if ($cashSessionId !== null && $cashSessionId !== '') {
            return $this->requireSession($companyNit, $branchId, $cashSessionId);
        }

        $open = $this->openSessionsForBranch($companyNit, $branchId);

        if ($open->isEmpty()) {
            throw ValidationException::withMessages([
                'cash_register' => 'La caja está cerrada. Debes abrirla antes de operar.',
            ]);
        }

        if ($open->count() > 1) {
            throw ValidationException::withMessages([
                'cash_session_id' => 'Hay varias cajas abiertas en esta sede. Indica en cuál se registra la operación.',
            ]);
        }

        return $open->first();
    }

    /**
     * Valida que una sesión exista, esté abierta y pertenezca a la empresa+sede
     * indicadas (defensa anti-tampering). Lanza ValidationException si no cuadra.
     */
    public function requireSession(string $companyNit, string $branchId, string $cashSessionId): CashRegisterSession
    {
        $session = CashRegisterSession::query()
            ->where('id', $cashSessionId)
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->first();

        if (! $session) {
            throw ValidationException::withMessages([
                'cash_session_id' => 'La caja seleccionada no está abierta en esta sede.',
            ]);
        }

        return $session;
    }

    /**
     * Catálogo de cajas de una sede con su sesión abierta (si la hay). Usado por
     * el selector y el panel supervisor. @return Collection<int, CashRegister>
     */
    public function registersForBranch(string $companyNit, string $branchId, bool $includeArchived = false): Collection
    {
        $query = CashRegister::query()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->with(['sessions' => fn ($q) => $q->where('status', 'open')->with('openedBy:id,name')])
            ->orderBy('sort_order')
            ->orderBy('created_at');

        if (! $includeArchived) {
            $query->whereNull('archived_at');
        }

        return $query->get();
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
     * Garantiza que haya una sesión abierta en la SEDE indicada o lanza
     * ValidationException. Devuelve la sesión activa para que el caller la
     * asocie al receipt creado.
     *
     * Multi-sede (#117): resuelve por `(company_nit, branch_id)` — no por
     * empresa. En empresas con varias sedes abiertas, resolver solo por empresa
     * podía atribuir el cobro a la sesión de otra sede y descuadrar ambas cajas.
     */
    public function requireActiveSession(string $companyNit, string $branchId): CashRegisterSession
    {
        $session = $this->activeSessionForBranch($companyNit, $branchId);

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

        // Los refunds se registran con payment_method='refund', no con el método
        // original del cobro. El GROUP BY anterior pone los refunds de efectivo
        // en byMethod['refund']['refunds'], dejando byMethod['cash']['refunds']=0.
        // Corregimos con el mismo JOIN que usa computeExpectedCash.
        $byMethod['cash']['refunds'] = $this->computeCashRefundsForSession($session->id);

        // Propina por receipt (`payment_data.tip_amount`), no por JOIN con
        // orders: el JOIN contaba la propina completa de la orden una vez por
        // cada receipt y en cada método cuando el cobro era dividido.
        $tipRows = PaymentReceipt::where('cash_session_id', $session->id)
            ->whereNotNull('payment_method')
            ->where('payment_method', '!=', 'refund')
            ->selectRaw("payment_method, SUM(COALESCE((payment_data->>'tip_amount')::numeric, 0)) AS tips_total")
            ->groupBy('payment_method')
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

        $incomeRows = CashRegisterIncome::forSession($session->id)
            ->selectRaw('payment_method, category, SUM(amount) AS total, COUNT(*) AS count')
            ->groupBy('payment_method', 'category')
            ->get();

        $incomesTotal = 0.0;
        $incomesCount = 0;
        $incomesByMethod = ['cash' => 0.0, 'card' => 0.0, 'transfer' => 0.0];
        $incomesByCategory = [];
        foreach ($incomeRows as $row) {
            $total = (float) $row->total;
            $incomesTotal += $total;
            $incomesCount += (int) $row->count;
            if (isset($incomesByMethod[$row->payment_method])) {
                $incomesByMethod[$row->payment_method] += $total;
            }
            $incomesByCategory[$row->category] = ($incomesByCategory[$row->category] ?? 0.0) + $total;
        }

        $expected = $this->computeExpectedCash($session);

        $orderCount = (int) Order::where('company_nit', $session->company_nit)
            ->whereHas('receipts', fn ($q) => $q->where('cash_session_id', $session->id)->where('payment_method', '!=', 'refund'))
            ->count();

        // Pedidos en estados operativos (pending/in_kitchen/ready/in_transit) —
        // bloquean el cierre de la ÚLTIMA caja de la sede. Si hay otras cajas
        // abiertas, esos pedidos los cobran ellas; el bloqueo es 0 (espejo
        // exacto de la lógica de `closeSession`).
        $otherOpen = CashRegisterSession::query()
            ->where('company_nit', $session->company_nit)
            ->where('branch_id', $session->branch_id)
            ->where('status', 'open')
            ->where('id', '!=', $session->id)
            ->exists();

        $pendingOrders = $otherOpen ? 0 : (int) Order::query()
            ->where('company_nit', $session->company_nit)
            ->where('branch_id', $session->branch_id)
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
            'incomes' => [
                'total' => round($incomesTotal, 2),
                'count' => $incomesCount,
                'by_method' => array_map(fn ($v) => round($v, 2), $incomesByMethod),
                'by_category' => array_map(fn ($v) => round($v, 2), $incomesByCategory),
            ],
        ];
    }
}
