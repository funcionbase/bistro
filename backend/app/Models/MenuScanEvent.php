<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Evento de escaneo del QR público del menú (analítica de tráfico).
 *
 * Tabla simple (NO particionada — el particionamiento por mes se retiró
 * para no acumular tablas hijas). Append-only: la app sólo INSERT,
 * la retención > 90 días vive en `DropOldMenuScanPartitionsJob` con DELETE
 * por rango sobre `scanned_at`.
 *
 * No es un modelo financiero — no requiere DB::transaction ni AuditLog.
 *
 * @property int $id
 * @property string $company_nit
 * @property ?string $table_number
 * @property Carbon $scanned_at
 * @property ?string $session_id
 * @property ?string $user_agent
 * @property ?string $ip_hash binario raw (32 B SHA-256)
 * @property bool $is_bot
 */
class MenuScanEvent extends Model
{
    use BelongsToBranch, HasUuids;

    protected $table = 'menu_scan_events';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'table_number',
        'scanned_at',
        'session_id',
        'user_agent',
        'ip_hash',
        'is_bot',
    ];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
            'is_bot' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }
}
