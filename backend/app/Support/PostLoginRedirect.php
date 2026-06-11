<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CompanyUser;

/**
 * Resuelve la ruta inicial post-login según el rol del usuario en la
 * empresa activa (#119).
 *
 * El usuario con rol "Domiciliario" (o cualquier rol que solo tenga
 * permisos de courier sin acceso a administración) entra directo a
 * `/my-deliveries` en lugar de `/dashboard`. Esto reduce un click
 * extra en el flujo más caliente del courier y refleja que en su
 * sidebar no hay otra navegación relevante.
 *
 * La detección es por permisos (no por nombre de rol) para sobrevivir
 * a renombres y para que sea consistente con la lógica del sidebar
 * frontend (`@/lib/courier-mode.ts`).
 */
class PostLoginRedirect
{
    /**
     * Permisos que indican "este rol opera más allá del courier-only mode".
     * Si el usuario tiene cualquiera de estos, recibe el dashboard normal.
     */
    private const FULL_NAV_PERMISSIONS = [
        'reports.read',     // owner / admin / cashier ven reportes
        'company.update',   // admin / owner editan empresa
        'orders.create',    // cashier toma órdenes
        'menu.update',      // admin / owner editan menú
        'inventory.read',   // chef / admin ven inventario
        'shifts.read',      // admin / employee ven planificador
        'kds.read',         // cocinero/manager/supervisor ven el KDS
    ];

    /**
     * Permiso que indica que el rol es courier funcional (puede auto-asignarse).
     */
    private const COURIER_PERMISSION = 'deliveries.self_assign';

    /**
     * Nombre de la ruta a la que redirigir tras login completo
     * (empresa + sede activas elegidas).
     *
     * @param  list<string>  $permissions  Slugs del JWT del usuario en la empresa.
     */
    public static function routeName(array $permissions): string
    {
        $hasCourierPermission = in_array(self::COURIER_PERMISSION, $permissions, true);

        if (! $hasCourierPermission) {
            return 'dashboard';
        }

        foreach (self::FULL_NAV_PERMISSIONS as $slug) {
            if (in_array($slug, $permissions, true)) {
                return 'dashboard';
            }
        }

        return 'deliveries.mine';
    }

    /**
     * Helper para resolver desde un user + companyNit sin tener el JWT
     * todavía construido (caso `GoogleAuthController` justo antes de
     * emitirlo). Reproduce el filtrado del JWT (can_read=true) pero
     * directamente desde BD para no depender del token.
     *
     * Si el rol es system (owner) → dashboard. Si la membresía no
     * existe (raro post-login) → dashboard como fallback seguro.
     */
    public static function routeNameForUser(string $userId, string $companyNit): string
    {
        $membership = CompanyUser::where('company_nit', $companyNit)
            ->where('user_id', $userId)
            ->with('role.permissions.feature:id,slug')
            ->first();

        if ($membership === null || $membership->role === null) {
            return 'dashboard';
        }

        if ($membership->role->is_system) {
            return 'dashboard';
        }

        $slugs = $membership->role->permissions
            ->filter(fn ($perm) => $perm->can_read && $perm->feature !== null)
            ->map(fn ($perm) => $perm->feature->slug)
            ->values()
            ->all();

        return self::routeName($slugs);
    }
}
