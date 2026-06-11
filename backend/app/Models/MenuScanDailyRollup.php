<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Rollup diario de menu_scan_events (la fuente de los reportes).
 *
 * PK compuesta (company_nit, scan_date, table_number). table_number = '' representa
 * "QR genérico sin mesa" — no NULL, para que el unique compuesto funcione.
 *
 * @property string $company_nit
 * @property Carbon $scan_date
 * @property string $table_number
 * @property int $total_scans
 * @property int $unique_sessions
 */
class MenuScanDailyRollup extends Model
{
    use BelongsToBranch;

    protected $table = 'menu_scan_daily_rollup';

    public $incrementing = false;

    protected $primaryKey = null;

    public $timestamps = true;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'scan_date',
        'table_number',
        'total_scans',
        'unique_sessions',
    ];

    protected function casts(): array
    {
        return [
            'scan_date' => 'date',
            'total_scans' => 'integer',
            'unique_sessions' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }
}
