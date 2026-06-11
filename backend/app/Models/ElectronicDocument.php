<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Documento electrónico DIAN (DEE POS, FEV, NC, ND).
 *
 * Inmutabilidad post-`accepted`: cualquier intento de mutar campos
 * financieros (`prefix`, `consecutive`, `full_number`, `unique_code`,
 * `xml_path`, `pdf_path`, `qr_data`, `dian_resolution_id`,
 * `dian_environment_code`) lanza `LogicException`. Para corregir un doc
 * aceptado se emite nota crédito con `references_document_id` apuntando al
 * original (regla §13 CLAUDE.md + conservación DIAN 10 años).
 *
 * Los demás campos (status, accepted_at, rejected_at, retry_count, etc.)
 * sí mutan — el documento "respira" hasta llegar a `accepted`/`rejected`.
 *
 * Status canónicos (transición monotónica):
 *   pending → queued → sent → accepted | rejected | error
 *   needs_recipient_data (estado lateral: faltan datos del adquirente)
 *
 * @property string $company_nit
 * @property string $branch_id
 * @property ?int $order_id
 * @property int $dian_resolution_id
 * @property string $document_type
 * @property string $prefix
 * @property int $consecutive
 * @property string $full_number
 * @property string $unique_code — CUFE (96 hex) o CUDE (96 hex)
 * @property string $unique_code_type — cufe|cude
 * @property Carbon $issued_at
 * @property ?string $xml_path
 * @property ?string $pdf_path
 * @property ?string $qr_data
 * @property string $status
 * @property ?string $provider_slug
 * @property ?string $provider_track_id
 * @property ?array<string,mixed> $provider_response_log
 * @property ?Carbon $sent_at
 * @property ?Carbon $accepted_at
 * @property ?Carbon $rejected_at
 * @property ?string $rejection_reason
 * @property int $retry_count
 * @property ?Carbon $last_retry_at
 * @property ?string $dian_environment_code
 * @property ?int $references_document_id
 */
class ElectronicDocument extends Model
{
    use BelongsToBranch, HasUuids;

    /** Campos financieros inmutables una vez el documento llegó a un estado terminal. */
    private const IMMUTABLE_AFTER_ACCEPTED = [
        'company_nit',
        'branch_id',
        'order_id',
        'dian_resolution_id',
        'document_type',
        'prefix',
        'consecutive',
        'full_number',
        'unique_code',
        'unique_code_type',
        'issued_at',
        'xml_path',
        'pdf_path',
        'qr_data',
        'dian_environment_code',
        'references_document_id',
    ];

    protected static function booted(): void
    {
        static::updating(function (ElectronicDocument $document): void {
            // Solo bloqueamos cuando el doc ORIGINALMENTE estaba accepted/rejected.
            // Si la transición actual es para SETEAR accepted_at/rejected_at, debe
            // pasar — porque ese update es justamente el que lleva el doc al estado
            // terminal.
            $originalStatus = $document->getOriginal('status');
            if (! in_array($originalStatus, ['accepted', 'rejected'], true)) {
                return;
            }

            foreach (self::IMMUTABLE_AFTER_ACCEPTED as $field) {
                if ($document->isDirty($field)) {
                    throw new LogicException(sprintf(
                        'ElectronicDocument.%s es inmutable post-%s (id=%d, full_number=%s). Para corregir, emitir nota crédito.',
                        $field,
                        $originalStatus,
                        $document->getKey(),
                        (string) $document->getOriginal('full_number'),
                    ));
                }
            }
        });
    }

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'branch_id',
        'order_id',
        'dian_resolution_id',
        'document_type',
        'prefix',
        'consecutive',
        'full_number',
        'unique_code',
        'unique_code_type',
        'issued_at',
        'xml_path',
        'pdf_path',
        'qr_data',
        'status',
        'provider_slug',
        'provider_track_id',
        'provider_response_log',
        'sent_at',
        'accepted_at',
        'rejected_at',
        'rejection_reason',
        'retry_count',
        'last_retry_at',
        'dian_environment_code',
        'references_document_id',
    ];

    protected function casts(): array
    {
        return [
            'consecutive' => 'integer',
            'issued_at' => 'datetime',
            'provider_response_log' => 'array',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'last_retry_at' => 'datetime',
            'retry_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<DianResolution, $this> */
    public function resolution(): BelongsTo
    {
        return $this->belongsTo(DianResolution::class, 'dian_resolution_id');
    }

    /** @return BelongsTo<ElectronicDocument, $this> */
    public function originalDocument(): BelongsTo
    {
        return $this->belongsTo(ElectronicDocument::class, 'references_document_id');
    }

    /** @return HasMany<ElectronicDocument, $this> */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(ElectronicDocument::class, 'references_document_id');
    }

    public function scopeForCompany(Builder $query, string $nit): Builder
    {
        return $query->where('company_nit', $nit);
    }

    public function scopeOfStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['accepted', 'rejected'], true);
    }

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }

    public function canBeRetried(): bool
    {
        return in_array($this->status, ['error', 'rejected'], true);
    }
}
