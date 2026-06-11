<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Adjunto de PO (factura PDF, remisión, soporte de pago).
 * Soft-delete por exigencia DIAN — los archivos no se eliminan físicamente
 * antes de la prescripción contable (5/10 años).
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property string $type
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size_bytes
 * @property int|null $uploaded_by
 * @property Carbon|null $deleted_at
 */
class PurchaseOrderAttachment extends Model
{
    use BelongsToBranch;
    use HasUuids;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'purchase_order_id',
        'type',
        'path',
        'original_name',
        'mime',
        'size_bytes',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
