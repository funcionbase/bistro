<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Respuesta rápida del operador (§8.4b punto 7).
 *
 * A propósito SIN `BelongsToBranch`/`BranchScope`: `branch_id` nulo significa
 * "de toda la empresa", y el scope global lo escondería para los usuarios de
 * sede. El aislamiento por sede lo aplica `ChatQuickReplyController` a mano.
 *
 * @property ?string $branch_id
 * @property string $title
 * @property string $body
 */
class ChatQuickReply extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'branch_id',
        'title',
        'body',
        'created_by_user_id',
    ];

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @param  Builder<ChatQuickReply>  $query
     * @return Builder<ChatQuickReply>
     */
    public function scopeForCompany(Builder $query, string $nit): Builder
    {
        return $query->where('company_nit', $nit);
    }
}
