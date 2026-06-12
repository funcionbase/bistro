<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Egreso registrado contra una sesión de caja abierta. Append-only: nunca se
 * actualiza ni se elimina; los reversos se hacen con un nuevo registro
 * (categoría `otro` con descripción explícita).
 *
 * Reduce `expected_cash` cuando `payment_method = cash`.
 *
 * @property int $id
 * @property int $cash_session_id
 * @property string $company_nit
 * @property string $amount — siempre positivo (CHECK en BD)
 * @property string $category — clave de config('cash_register.expense_categories')
 * @property string $payment_method — cash | card | transfer
 * @property string|null $description
 * @property int $created_by_user_id
 * @property Carbon $created_at
 */
class CashRegisterExpense extends Model
{
    use BelongsToBranch, HasUuids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'cash_session_id',
        'company_nit',
        'client_uuid',
        'amount',
        'category',
        'payment_method',
        'description',
        'created_by_user_id',
        'created_at',
        'occurred_at_client',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
            'occurred_at_client' => 'datetime',
        ];
    }

    /** @return BelongsTo<CashRegisterSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'cash_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    public function scopeForSession(Builder $q, string $sessionId): Builder
    {
        return $q->where('cash_session_id', $sessionId);
    }

    public function scopeForCompany(Builder $q, string $nit): Builder
    {
        return $q->where('company_nit', $nit);
    }
}
