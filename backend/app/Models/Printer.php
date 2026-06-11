<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Impresora térmica configurada para una empresa. Las comandas se rutean por
 * `categories` (lista cerrada de nombres de categoría del menú) y el destino
 * se discrimina por `type` (kitchen|bar|cashier|customer_receipt).
 *
 * @property string $company_nit
 * @property string $name
 * @property string $type
 * @property string $connection
 * @property string $address
 * @property int $paper_width
 * @property array<int,string>|null $categories
 * @property bool $is_active
 * @property Carbon|null $last_test_at
 */
class Printer extends Model
{
    use BelongsToBranch, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'name',
        'type',
        'connection',
        'address',
        'paper_width',
        'categories',
        'is_active',
        'last_test_at',
    ];

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'is_active' => 'boolean',
            'paper_width' => 'integer',
            'last_test_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @param Builder<self> $query */
    public function scopeForCompany(Builder $query, string $nit): Builder
    {
        return $query->where('company_nit', $nit);
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function matchesCategory(?string $category): bool
    {
        if ($category === null || $category === '') {
            return false;
        }

        $list = $this->categories ?? [];

        return in_array($category, $list, true);
    }
}
