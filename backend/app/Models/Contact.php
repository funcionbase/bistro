<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Contacto de la empresa — antes llamado "Cliente" en la UI, desde #235
 * unificado bajo el concepto de Contact. Un contacto puede ser persona
 * natural o jurídica (empresa) según `kind`. La identidad canónica es
 * (company_nit, doc_number) cuando el doc está presente (UNIQUE parcial).
 * `phone` es nullable y puede repetirse: familia comparte número,
 * walk-ins legacy nacen sin doc, empresas a veces solo tienen email.
 *
 * @property string $company_nit
 * @property ?string $phone
 * @property ?string $name
 * @property ?'natural'|'company' $kind
 * @property ?string $notes
 * @property ?string $doc_type
 * @property ?string $doc_number
 * @property ?string $dv
 * @property ?string $legal_name
 * @property ?string $email
 * @property ?string $address
 * @property ?string $municipality_dane_code
 * @property ?array $fiscal_responsibilities
 * @property ?Carbon $dian_profile_completed_at
 */
class Contact extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    use HasUuids;

    public const KIND_NATURAL = 'natural';

    public const KIND_COMPANY = 'company';

    /** Catálogo DIAN de doc_type según naturaleza del contacto. */
    public const NATURAL_DOC_TYPES = ['CC', 'CE', 'TI', 'PA', 'RC'];

    public const COMPANY_DOC_TYPES = ['NIT', 'NIT_EXT'];

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'phone',
        'name',
        'kind',
        'notes',
        // Perfil fiscal DIAN del adquirente (#235). Cuando
        // `dian_profile_completed_at` está poblado, este contacto puede
        // recibir FEV directo desde una orden cobrada (lookup por phone).
        'doc_type',
        'doc_number',
        'dv',
        'legal_name',
        'email',
        'address',
        'municipality_dane_code',
        'fiscal_responsibilities',
        'dian_profile_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_responsibilities' => 'array',
            'dian_profile_completed_at' => 'datetime',
        ];
    }

    public function hasCompleteDianProfile(): bool
    {
        return $this->dian_profile_completed_at !== null
            && filled($this->doc_type)
            && filled($this->doc_number)
            && filled($this->legal_name);
    }

    /**
     * Deriva kind del doc_type cuando la columna `kind` aún no está poblada
     * (contactos antiguos con doc_type pero sin kind explícito).
     */
    public function effectiveKind(): ?string
    {
        if ($this->kind !== null) {
            return $this->kind;
        }

        if (in_array($this->doc_type, self::COMPANY_DOC_TYPES, true)) {
            return self::KIND_COMPANY;
        }

        if (in_array($this->doc_type, self::NATURAL_DOC_TYPES, true)) {
            return self::KIND_NATURAL;
        }

        return null;
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return HasMany<Chat, $this> */
    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class, 'contact_id');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'contact_id');
    }

    /** @return HasMany<ClientNote, $this> */
    public function notesRelation(): HasMany
    {
        return $this->hasMany(ClientNote::class, 'contact_id');
    }

    /** @return HasMany<ClientTag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(ClientTag::class, 'contact_id');
    }

    public function scopeForCompany(Builder $query, string $nit): Builder
    {
        return $query->where('company_nit', $nit);
    }
}
