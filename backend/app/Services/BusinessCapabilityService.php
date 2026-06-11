<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BusinessType;

/**
 * Resuelve las capacidades (flags booleanos) que aplican a una sede combinando:
 *   1. default_capabilities del vertical (`business_types.default_capabilities`)
 *   2. capabilities_override de la sede (`branches.capabilities_override`)
 *
 * Las capabilities controlan qué módulos del producto aparecen/se habilitan
 * para una sede. Ej: una sede `dark_store` no tiene `tables`; una sede
 * `ghost_kitchen` no tiene `counter_orders`. El owner puede ajustar puntualmente
 * por sede vía `capabilities_override`.
 *
 * Capabilities canónicas (mantener sincronizadas con la migración):
 *   - pos_orders, counter_orders, tables, kds, prep_areas, delivery, recipes,
 *     inventory, reservations, catering_scheduling, multi_menu.
 */
class BusinessCapabilityService
{
    /**
     * Lista canónica de flags. Sirve como fallback cuando la sede no tiene
     * vertical asignado (caso defensivo — el onboarding lo exige).
     *
     * @var array<string, bool>
     */
    public const DEFAULT_FLAGS = [
        'pos_orders' => true,
        'counter_orders' => true,
        'tables' => false,
        'kds' => false,
        'prep_areas' => false,
        'delivery' => false,
        'recipes' => false,
        'inventory' => true,
        'reservations' => false,
        'catering_scheduling' => false,
        'multi_menu' => false,
    ];

    /**
     * Devuelve el mapa completo de capabilities resueltas para la sede.
     *
     * @return array<string, bool>
     */
    public function capabilities(Branch $branch): array
    {
        $defaults = self::DEFAULT_FLAGS;

        $vertical = $branch->relationLoaded('businessType')
            ? $branch->businessType
            : BusinessType::find($branch->business_type_id);

        if ($vertical instanceof BusinessType) {
            $defaults = array_merge($defaults, $vertical->default_capabilities ?? []);
        }

        $override = $branch->capabilities_override ?? [];
        if (! is_array($override)) {
            $override = [];
        }

        return array_merge($defaults, $override);
    }

    /**
     * Atajo booleano. Útil para gates de middleware y controllers.
     */
    public function userCan(Branch $branch, string $flag): bool
    {
        $caps = $this->capabilities($branch);

        return (bool) ($caps[$flag] ?? false);
    }
}
