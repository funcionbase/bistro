<?php

namespace App\Services;

use App\Models\RestaurantMenu;
use Illuminate\Support\Carbon;

/**
 * Activa y desactiva menús de restaurante según el día de la semana actual.
 *
 * Solo opera sobre menús con active_days definido y status != 'draft'.
 * Por SEDE (company_nit + branch_id): activa el primer menú cuyo active_days
 * incluya el dayOfWeek de hoy (Carbon 0=domingo) y pone los demás de ESA sede
 * en estado 'scheduled'. Si ninguno coincide, todos quedan 'scheduled'.
 *
 * Las sedes son independientes: cada branch opera su propia carta y
 * están físicamente separadas, así que la programación de una sede NUNCA toca
 * los menús de otra. Agrupar por empresa degradaba el menú de las demás sedes
 * a 'scheduled', dejándolas sin carta servible.
 *
 * Es idempotente: solo actualiza registros cuyo estado cambiaría efectivamente.
 */
class MenuSchedulerService
{
    /**
     * Sync schedule for all branches or a single company's branches.
     *
     * Only touches non-draft menus that have active_days configured.
     * Per branch (company_nit + branch_id): activates the menu whose active_days
     * includes today, sets the other menus OF THAT BRANCH to 'scheduled'. If none
     * match today, all stay 'scheduled'. Branches never affect each other.
     *
     * @return array{synced: int, activated: int}
     */
    public function sync(?string $companyNit = null): array
    {
        // Día en la zona del negocio (misma que BusinessHoursService y la lectura
        // del menú público), para que "hoy" no discrepe cerca de medianoche.
        $tz = (string) config('business-hours.timezone', config('app.timezone', 'UTC'));
        $todayDow = Carbon::now($tz)->dayOfWeek; // 0 (Sunday) to 6 (Saturday)

        $query = RestaurantMenu::whereNotNull('active_days')
            ->where('status', '!=', 'draft');

        if ($companyNit) {
            $query->where('company_nit', $companyNit);
        }

        $menus = $query->get();

        $synced = 0;
        $activated = 0;

        // Cada sede resuelve su carta de forma aislada: clave = empresa + sede.
        // branch_id null (carta sin sede asignada) agrupa por empresa, su caso.
        $byBranch = $menus->groupBy(fn (RestaurantMenu $menu) => $menu->company_nit.'|'.($menu->branch_id ?? ''));

        foreach ($byBranch as $branchMenus) {
            $matchingMenu = null;

            foreach ($branchMenus as $menu) {
                $days = $menu->active_days ?? [];
                if (in_array($todayDow, $days, strict: true)) {
                    $matchingMenu = $menu;
                    break;
                }
            }

            foreach ($branchMenus as $menu) {
                $newStatus = ($matchingMenu && $menu->id === $matchingMenu->id)
                    ? 'active'
                    : 'scheduled';

                if ($menu->status !== $newStatus) {
                    $menu->update(['status' => $newStatus]);
                    $synced++;
                    if ($newStatus === 'active') {
                        $activated++;
                    }
                }
            }
        }

        return ['synced' => $synced, 'activated' => $activated];
    }
}
