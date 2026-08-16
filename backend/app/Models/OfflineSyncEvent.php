<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora append-only de eventos de sincronización offline.
 *
 * Cada batch sincronizado registra una fila por tipo de evento (`order_synced`,
 * `receipt_synced`, `sync_failed`) con totales agregados. Inmutable post-creación
 * — alineado con la conservación de comprobantes contables (CLAUDE.md).
 *
 * @property string $company_nit
 * @property int|null $user_id
 * @property string $event_type
 * @property int $count
 * @property string $total_amount
 * @property array<string,mixed>|null $metadata
 */
class OfflineSyncEvent extends Model
{
    use BelongsToBranch, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'user_id',
        'event_type',
        'count',
        'total_amount',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'count' => 'integer',
            'total_amount' => 'decimal:2',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForCompany(Builder $query, string $companyNit): Builder
    {
        return $query->where('company_nit', $companyNit);
    }
}
