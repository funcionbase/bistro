<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Menú de restaurante con estructura JSON v3. Solo un menú puede estar 'active'
 * por SEDE a la vez (company_nit + branch_id); las sedes son independientes
 * (#117) y no comparten carta. activate() y MenuSchedulerService desactivan
 * únicamente los demás menús de la MISMA sede.
 *
 * structure: JSON con la estructura v3 de categorías e ítems (versiones anteriores son incompatibles).
 * active_days: JSON array de enteros 0–6 (donde 0=domingo, per Carbon), usado por MenuSchedulerService.
 * Estados: draft | scheduled | active. El estado 'draft' no participa en la programación automática.
 * Las imágenes de platos se almacenan en el disco configurado en config menu.image_disk.
 *
 * @property array<int, int> $active_days — días de la semana activos (0=domingo, 6=sábado)
 * @property array<string, mixed>|null $structure — estructura JSON v3 del menú
 * @property string $status — draft | scheduled | active
 */
class RestaurantMenu extends Model
{
    use BelongsToBranch, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'name',
        'description',
        'status',
        'active_days',
        'structure',
    ];

    protected function casts(): array
    {
        return [
            'structure' => 'array',
            'active_days' => 'array',
            'status' => 'string',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @param Builder<RestaurantMenu> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** @param Builder<RestaurantMenu> $query */
    public function scopeForCompany(Builder $query, string $nit): Builder
    {
        return $query->where('company_nit', $nit);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * ¿El menú está programado para mostrarse en el día de la semana dado?
     *
     * `active_days` null = sin programación → siempre disponible (mismo criterio
     * que MenuSchedulerService, que solo gestiona menús con active_days). Si hay
     * programación, solo aplica cuando el día está incluido. Convención del día:
     * 0 = domingo … 6 = sábado (Carbon::dayOfWeek), igual que active_days.
     *
     * Es el 3er nivel de precedencia de operabilidad (Excepciones > Horario
     * semanal > Programación de menú) y se evalúa en lectura del menú público
     * para no depender del tick horario del scheduler.
     */
    public function isScheduledForDay(int $dayOfWeek): bool
    {
        $days = $this->active_days;

        return $days === null || in_array($dayOfWeek, $days, strict: true);
    }

    public function getConfiguredDisk(): string
    {
        return config('menu.image_disk', 'local');
    }

    /**
     * Índice memoizado `menu_item_id → ['item' => array, 'category' => string]`.
     *
     * FoodCostMetricsService y otros consumidores buscan repetidamente items
     * dentro del JSON `structure`. Sin memoization, cada lookup recorre todas
     * las categorías y todos los items (O(N) por lookup, N×M total). Esta
     * función indexa una sola vez por instance del modelo y entrega O(1) por
     * lookup posterior.
     *
     * @return array<string, array{item: array<string, mixed>, category: string|null}>
     */
    public function menuItemIndex(): array
    {
        // Memoization en-instance: el caller suele tener una sola instancia
        // del menú activo por request. Volver a calcular el índice tras
        // mutar `structure` requiere `refresh()` y luego volver a llamar.
        if ($this->menuItemIndexCache !== null) {
            return $this->menuItemIndexCache;
        }

        $index = [];
        foreach ($this->structure['categories'] ?? [] as $category) {
            $categoryName = $category['name'] ?? null;
            foreach ($category['items'] ?? [] as $item) {
                $itemId = $item['id'] ?? null;
                if ($itemId === null) {
                    continue;
                }
                $index[(string) $itemId] = [
                    'item' => $item,
                    'category' => $categoryName,
                ];
            }
        }

        return $this->menuItemIndexCache = $index;
    }

    /**
     * Lookup directo de un item por id. Devuelve null si no existe.
     *
     * @return array<string, mixed>|null el item enriquecido con `category`.
     */
    public function findMenuItem(string $menuItemId): ?array
    {
        $entry = $this->menuItemIndex()[$menuItemId] ?? null;

        return $entry === null ? null : $entry['item'] + ['category' => $entry['category']];
    }

    /**
     * Mapa `menu_item_id → kds_station_id` (#115). null = la categoría no
     * declara estación y el item debe enrutarse a la estación `is_default`
     * de la sede (fallback). Consumido por `KdsController` para filtrar
     * tickets por estación sin pegar otra query por item.
     *
     * @return array<string, int|null>
     */
    public function menuItemStationMap(): array
    {
        $map = [];
        foreach ($this->structure['categories'] ?? [] as $category) {
            $stationId = $category['kds_station_id'] ?? null;
            foreach ($category['items'] ?? [] as $item) {
                $itemId = $item['id'] ?? null;
                if ($itemId === null) {
                    continue;
                }
                $map[(string) $itemId] = is_int($stationId) ? $stationId : null;
            }
        }

        return $map;
    }

    /** @var array<string, array{item: array<string, mixed>, category: string|null}>|null */
    private ?array $menuItemIndexCache = null;
}
