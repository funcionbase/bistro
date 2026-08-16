<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Evento disparado por una regla de alerta.
 *
 * Inmutable salvo en los campos `dismissed_at`, `actioned_at`, `actioned_note`
 * y `actioned_by` — el resto (payload, severity, target) refleja el snapshot
 * del momento del disparo y nunca se altera, para mantener trazabilidad.
 *
 * Status derivado:
 *  - active     → dismissed_at IS NULL AND actioned_at IS NULL
 *  - dismissed  → dismissed_at IS NOT NULL
 *  - actioned   → actioned_at IS NOT NULL
 *
 * Dedup diario: la BD impone UNIQUE(alert_rule_id, target_type, COALESCE(target_id,''), DATE(triggered_at)).
 *
 * @property int $id
 * @property int $alert_rule_id
 * @property string $company_nit
 * @property string $type
 * @property string $severity
 * @property string $target_type
 * @property ?string $target_id
 * @property array<string, mixed> $payload
 * @property Carbon $triggered_at
 * @property ?Carbon $dismissed_at
 * @property ?Carbon $actioned_at
 * @property ?string $actioned_note
 * @property ?int $actioned_by
 */
class AlertEvent extends Model
{
    use HasUuids;

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    public const TARGET_MENU_ITEM = 'menu_item';

    public const TARGET_INGREDIENT = 'ingredient';

    public const TARGET_GLOBAL = 'global';

    /** @var list<string> */
    protected $fillable = [
        'alert_rule_id',
        'company_nit',
        'type',
        'severity',
        'target_type',
        'target_id',
        'payload',
        'triggered_at',
        'dismissed_at',
        'actioned_at',
        'actioned_note',
        'actioned_by',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'triggered_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'actioned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AlertRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by');
    }

    public function scopeForCompany(Builder $query, string $nit): Builder
    {
        return $query->where('company_nit', $nit);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at')->whereNull('actioned_at');
    }

    public function scopeDismissed(Builder $query): Builder
    {
        return $query->whereNotNull('dismissed_at');
    }

    public function scopeActioned(Builder $query): Builder
    {
        return $query->whereNotNull('actioned_at');
    }

    public function getStatusAttribute(): string
    {
        if ($this->actioned_at !== null) {
            return 'actioned';
        }
        if ($this->dismissed_at !== null) {
            return 'dismissed';
        }

        return 'active';
    }
}
