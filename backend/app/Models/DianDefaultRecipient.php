<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cliente DIAN por defecto de una empresa.
 *
 * Override opcional sobre el "CONSUMIDOR FINAL" estándar
 * (`config('dian.default_final_consumer')`). Una sola fila por `company_nit`.
 *
 * Cuando aplica:
 *  - `applies_to_auto_emit_only=true` (default): solo se usa en el camino
 *    feliz "Pagar y emitir" sin captura explícita del adquirente.
 *  - `applies_to_auto_emit_only=false`: también cuando el cajero pulsa
 *    "Emitir documento" sin captura previa.
 *
 * @property string $company_nit
 * @property string $doc_type
 * @property string $doc_number
 * @property ?string $dv
 * @property string $legal_name
 * @property ?string $email
 * @property ?string $address
 * @property ?string $municipality_dane_code
 * @property ?array<int,string> $fiscal_responsibilities
 * @property bool $applies_to_auto_emit_only
 */
class DianDefaultRecipient extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'doc_type',
        'doc_number',
        'dv',
        'legal_name',
        'email',
        'address',
        'municipality_dane_code',
        'fiscal_responsibilities',
        'applies_to_auto_emit_only',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_responsibilities' => 'array',
            'applies_to_auto_emit_only' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }
}
