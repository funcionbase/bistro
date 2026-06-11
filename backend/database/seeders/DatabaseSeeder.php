<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Punto de entrada por defecto de `php artisan db:seed`.
 *
 * Para evitar accidentes en otros entornos, este seeder DELEGA segun
 * APP_ENV: production -> ProductionSeeder, otros -> QaSeeder (que internamente
 * llama a ProductionSeeder + dataset operativo de demo).
 *
 * Para forzar un perfil distinto, usa `--class=` explicitamente:
 *
 *   php artisan db:seed --class=Database\\Seeders\\ProductionSeeder --force
 *   php artisan db:seed --class=Database\\Seeders\\QaSeeder --force
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $env = (string) app()->environment();

        if ($env === 'production') {
            $this->call(ProductionSeeder::class);

            return;
        }

        $this->call(QaSeeder::class);
    }
}
