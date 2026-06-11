<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BusinessType;

/**
 * Resuelve labels orientados al usuario final que dependen del vertical de la
 * sede. La idea es que el mismo módulo aparezca con palabras del oficio: en un
 * restaurante "cocina"/"mesero", en un café "barra"/"barista", en un bar
 * "barra"/"bartender", etc.
 *
 * Los slugs internos del código NO cambian — sólo las palabras visibles en UI,
 * emails, recibos, comandas. Backend resuelve el label en respuesta del endpoint
 * /api/v1/me/active-context; frontend lo cachea en el BusinessProvider.
 */
class BusinessLabelService
{
    /**
     * Mapa de slug de role → label por vertical. Aplica a roles abstractos
     * (server/prep_staff/register/courier) y también a los slugs CONCRETOS que
     * ya existen en BD (`waiter`, `cook`, `cashier`) para preservar la
     * compatibilidad con `company_roles.role_type` actual. El frontend resuelve
     * dinámicamente el label dependiendo del vertical de la sede activa.
     *
     * Roles concretos sin variación por vertical: manager, accountant,
     * marketing, inventory_manager, supervisor, owner, admin, employee.
     */
    private const ROLE_LABELS = [
        // Abstract slugs — nuevos consumibles desde código.
        'server' => [
            'restaurant' => 'Mesero',
            'bakery' => 'Vendedor',
            'cafe' => 'Barista',
            'fast_food' => 'Cajero de mostrador',
            'food_truck' => 'Atención al cliente',
            'ghost_kitchen' => 'Atención al cliente',
            'bar' => 'Bartender',
            'catering' => 'Coordinador de servicio',
            'dark_store' => 'Vendedor de tienda',
            '_default' => 'Atención al cliente',
        ],
        'prep_staff' => [
            'restaurant' => 'Cocinero',
            'bakery' => 'Panadero',
            'cafe' => 'Barista',
            'fast_food' => 'Cocinero de plancha',
            'food_truck' => 'Cocinero',
            'ghost_kitchen' => 'Cocinero',
            'bar' => 'Bartender de cocina',
            'catering' => 'Cocinero de eventos',
            'dark_store' => 'Operario de bodega',
            '_default' => 'Personal de preparación',
        ],
        'register' => [
            '_default' => 'Cajero',
        ],
        'courier' => [
            '_default' => 'Domiciliario',
        ],
        // Concrete slugs en BD — mismo mapeo dinámico por vertical, mantienen
        // sus templates de permisos sin cambio.
        'waiter' => [
            'restaurant' => 'Mesero',
            'bakery' => 'Vendedor',
            'cafe' => 'Barista',
            'fast_food' => 'Cajero de mostrador',
            'food_truck' => 'Atención al cliente',
            'ghost_kitchen' => 'Atención al cliente',
            'bar' => 'Bartender',
            'catering' => 'Coordinador de servicio',
            'dark_store' => 'Vendedor de tienda',
            '_default' => 'Mesero',
        ],
        'cook' => [
            'restaurant' => 'Cocinero',
            'bakery' => 'Panadero',
            'cafe' => 'Barista',
            'fast_food' => 'Cocinero de plancha',
            'food_truck' => 'Cocinero',
            'ghost_kitchen' => 'Cocinero',
            'bar' => 'Bartender de cocina',
            'catering' => 'Cocinero de eventos',
            'dark_store' => 'Operario de bodega',
            '_default' => 'Cocinero',
        ],
        'cashier' => [
            '_default' => 'Cajero',
        ],
    ];

    /**
     * Mapa de slug de status de orden → label por vertical. Los slugs siguen
     * existiendo en BD para preservar consistencia y compatibilidad histórica;
     * sólo cambia la palabra visible.
     */
    private const ORDER_STATUS_LABELS = [
        'in_kitchen' => [
            'cafe' => 'En barra',
            'bar' => 'En barra',
            'bakery' => 'En horno',
            '_default' => 'En cocina',
        ],
        'in_transit' => [
            'catering' => 'En ruta',
            '_default' => 'En domicilio',
        ],
        'ready' => [
            '_default' => 'Listo',
        ],
        'pending' => [
            '_default' => 'Pendiente',
        ],
        'completed' => [
            '_default' => 'Completada',
        ],
        'cancelled' => [
            '_default' => 'Cancelada',
        ],
        'refunded' => [
            '_default' => 'Reembolsada',
        ],
        'failed' => [
            '_default' => 'Fallida',
        ],
        'abandoned' => [
            '_default' => 'Abandonada',
        ],
        'pending_approval' => [
            '_default' => 'Esperando aprobación',
        ],
    ];

    /**
     * Mapa de módulos visibles (nav, breadcrumbs, dashboards) por vertical.
     */
    private const MODULE_LABELS = [
        'kds' => [
            'cafe' => 'Pantalla de barra',
            'bar' => 'Pantalla de barra',
            'bakery' => 'Pantalla de horno',
            '_default' => 'Pantalla de cocina (KDS)',
        ],
        'tables' => [
            'cafe' => 'Mesas',
            '_default' => 'Mesas',
        ],
        'delivery' => [
            'catering' => 'Servicios a domicilio',
            '_default' => 'Domicilios',
        ],
        'menu' => [
            'bakery' => 'Productos',
            'dark_store' => 'Catálogo',
            '_default' => 'Menú',
        ],
    ];

    /**
     * Devuelve un payload completo de labels para la sede dada. El frontend lo
     * consume y lo usa donde corresponde sin volver a llamar al backend.
     *
     * @return array{
     *   business_type: ?string,
     *   business_type_label: ?string,
     *   roles: array<string, string>,
     *   order_statuses: array<string, string>,
     *   modules: array<string, string>
     * }
     */
    public function labels(Branch $branch): array
    {
        $verticalSlug = $branch->business_type_id;
        $vertical = $branch->relationLoaded('businessType')
            ? $branch->businessType
            : ($verticalSlug ? BusinessType::find($verticalSlug) : null);

        $resolve = function (array $map) use ($verticalSlug): string {
            return $map[$verticalSlug] ?? $map['_default'] ?? '';
        };

        return [
            'business_type' => $verticalSlug,
            'business_type_label' => $vertical?->label_es,
            'roles' => collect(self::ROLE_LABELS)
                ->mapWithKeys(fn (array $map, string $role) => [$role => $resolve($map)])
                ->all(),
            'order_statuses' => collect(self::ORDER_STATUS_LABELS)
                ->mapWithKeys(fn (array $map, string $status) => [$status => $resolve($map)])
                ->all(),
            'modules' => collect(self::MODULE_LABELS)
                ->mapWithKeys(fn (array $map, string $module) => [$module => $resolve($map)])
                ->all(),
        ];
    }

    /**
     * Atajo para resolver el label de un rol abstracto. Roles concretos retornan
     * el slug recibido (el frontend ya tiene su tabla fija).
     */
    public function labelForRole(Branch $branch, string $roleSlug): string
    {
        $map = self::ROLE_LABELS[$roleSlug] ?? null;
        if ($map === null) {
            return $roleSlug;
        }

        return $map[$branch->business_type_id] ?? $map['_default'] ?? $roleSlug;
    }
}
