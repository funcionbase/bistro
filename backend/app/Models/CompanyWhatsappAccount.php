<?php

namespace App\Models;

use Database\Factories\CompanyWhatsappAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Cuenta de WhatsApp de una empresa cliente (WABA + numero registrado en Cloud API).
 *
 * Una fila por company_nit. Provisioning_mode indica si se conecto via Embedded
 * Signup (cliente trae su numero) o via Number-as-a-Service (flexyflow provee).
 *
 * @property string $company_nit
 * @property string $provisioning_mode
 * @property string $status
 * @property ?string $waba_id
 * @property ?string $phone_number_id
 * @property ?string $business_id
 * @property ?string $phone_e164
 * @property ?string $display_name
 * @property ?string $access_token_encrypted
 * @property ?string $webhook_verify_token_encrypted
 * @property ?Carbon $connected_at
 */
class CompanyWhatsappAccount extends Model
{
    /** @use HasFactory<CompanyWhatsappAccountFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'provisioning_mode',
        'status',
        'waba_id',
        'phone_number_id',
        'business_id',
        'phone_e164',
        'display_name',
        'display_name_status',
        'quality_rating',
        'messaging_tier',
        'verified_name',
        'is_business_verified',
        'access_token_encrypted',
        'webhook_verify_token_encrypted',
        'webhook_subscribed_at',
        'connected_at',
        'disconnected_at',
        'last_synced_at',
        'last_error',
        'naas_provider',
        'naas_provider_account_ref',
        'naas_contract_id',
    ];

    protected function casts(): array
    {
        return [
            'is_business_verified' => 'boolean',
            'webhook_subscribed_at' => 'datetime',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'access_token_encrypted' => 'encrypted',
            'webhook_verify_token_encrypted' => 'encrypted',
        ];
    }

    /** @var array<string, mixed> */
    protected $hidden = [
        'access_token_encrypted',
        'webhook_verify_token_encrypted',
    ];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return HasMany<CompanyWhatsappAccountEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CompanyWhatsappAccountEvent::class)->latest();
    }

    public function scopeConnected(Builder $query): Builder
    {
        return $query->where('status', 'connected');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'verifying']);
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    public function accessToken(): ?string
    {
        return $this->access_token_encrypted;
    }
}
