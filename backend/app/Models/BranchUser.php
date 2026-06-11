<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pivot user × branch. Existe como modelo (no Pivot) para auditar quién otorgó el acceso.
 *
 * @property int $id
 * @property string $branch_id — uuid
 * @property int $user_id
 * @property ?int $granted_by_user_id
 * @property Carbon $granted_at
 */
class BranchUser extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'branch_id',
        'user_id',
        'granted_by_user_id',
        'granted_at',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
