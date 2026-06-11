<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Mesa física del restaurante (#191).
 *
 * Una mesa pertenece a una sede (branch). El `qr_token` es único globalmente:
 * el QR físico que se imprime identifica directamente esta mesa, por eso
 * resolverlo bastará para saber a qué sede llegó el cliente.
 *
 * `status` se persiste como cache visual; la verdad de "ocupada" es derivada
 * de la existencia de una `table_session` activa.
 *
 * @property int $id
 * @property string $company_nit
 * @property string $branch_id
 * @property string $number
 * @property int $capacity
 * @property string $qr_token
 * @property string $status
 * @property Carbon|null $archived_at
 */
class Table extends Model
{
    use BelongsToBranch, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'branch_id',
        'number',
        'capacity',
        'qr_token',
        'status',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Table $table): void {
            if (empty($table->qr_token)) {
                $table->qr_token = self::generateQrToken();
            }
        });
    }

    /** Token aleatorio URL-safe para imprimir como QR (40 chars alfanuméricos). */
    public static function generateQrToken(): string
    {
        return Str::random(40);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return HasMany<TableSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(TableSession::class);
    }

    /** Sesión actualmente activa (open o locked) si existe. */
    public function activeSession(): ?TableSession
    {
        return $this->sessions()
            ->whereIn('status', config('tables.active_statuses'))
            ->first();
    }

    /** @param Builder<Table> $query */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', 'available');
    }

    /** @param Builder<Table> $query */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /** @param Builder<Table> $query */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
