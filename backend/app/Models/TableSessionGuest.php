<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Comensal anónimo dentro de una sesión de mesa (#191).
 *
 * Identidad runtime = cookie firmada `device_token` (httpOnly, signed). Identidad
 * persistente = FK a `contacts` (un cliente único por (company_nit, phone)).
 *
 * `display_name` y `phone` son snapshot del momento en que el comensal escribió:
 * pueden diferir del `contacts.name`/`phone` canónico si el contacto evoluciona
 * con el tiempo. Esto da trazabilidad histórica.
 *
 * @property int $id
 * @property int $table_session_id
 * @property int $contact_id
 * @property string $display_name
 * @property string $phone
 * @property string $device_token
 * @property Carbon $joined_at
 */
class TableSessionGuest extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'table_session_id',
        'contact_id',
        'display_name',
        'phone',
        'device_token',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TableSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TableSession::class, 'table_session_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'guest_id');
    }

    /** @return HasMany<PaymentReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(PaymentReceipt::class, 'guest_id');
    }
}
