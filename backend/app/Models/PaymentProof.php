<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comprobante de pago manual subido por el cliente cuando su empresa está en
 * past_due o suspended. Append-only: las filas no se mutan salvo al revisar
 * (status, reviewed_by_user_id, reviewed_at, review_notes). DIAN: retención
 * mínima 10 años.
 *
 * @property int $id
 * @property string $uuid
 * @property string $company_nit
 * @property array<int>|null $invoice_ids
 * @property int|null $uploaded_by_user_id
 * @property string $file_path
 * @property string $mime
 * @property int $size_bytes
 * @property string $original_name
 * @property 'submitted'|'accepted'|'rejected' $status
 * @property int|null $reviewed_by_user_id
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $review_notes
 * @property CarbonImmutable $created_at
 */
class PaymentProof extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid',
        'company_nit',
        'invoice_ids',
        'uploaded_by_user_id',
        'file_path',
        'mime',
        'size_bytes',
        'original_name',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'invoice_ids' => 'array',
            'reviewed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
