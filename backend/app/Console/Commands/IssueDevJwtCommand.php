<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Console\Command;

/**
 * Emite un JWT para un usuario existente — uso exclusivo en desarrollo / QA
 * (Postman, scripts, integraciones de prueba). En producción aborta con error.
 *
 * Ejemplos:
 *   php artisan jwt:issue cristianmarint@gmail.com
 *   php artisan jwt:issue cristianmarint@gmail.com --company=9009009001 --branch=<uuid>
 *
 * El token resultante es válido por las mismas horas que `JWT_TTL_SECONDS`
 * (default 6h). Pegarlo en la variable `jwt_token` del environment de Postman
 * (`postman/flexyflow-local.postman_environment.json`).
 */
class IssueDevJwtCommand extends Command
{
    protected $signature = 'jwt:issue
        {email : Email del usuario para el que se emite el JWT}
        {--company= : NIT de empresa activa (default: primera disponible)}
        {--branch= : UUID de sede activa (default: ninguna)}';

    protected $description = 'Emite un JWT para un usuario (solo dev/QA). Útil para Postman y scripts.';

    public function handle(JwtService $jwt): int
    {
        if (app()->environment('production')) {
            $this->error('Comando deshabilitado en producción. Usa el flujo OAuth normal.');

            return self::FAILURE;
        }

        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("Usuario no encontrado: {$email}");

            return self::FAILURE;
        }

        $companies = $user->companies()->get();
        $companyNit = $this->option('company');
        $branchId = $this->option('branch');

        if ($companyNit !== null) {
            $exists = $companies->contains(fn (Company $c) => $c->nit === $companyNit);
            if (! $exists) {
                $this->error("El usuario no pertenece a la empresa NIT={$companyNit}. Empresas disponibles: ".$companies->pluck('nit')->implode(', '));

                return self::FAILURE;
            }
        }

        $token = $jwt->issue(
            $user,
            $companies,
            $companyNit,
            $branchId,
        );

        $this->info('JWT emitido para '.$user->email);
        $this->line($token);
        $this->newLine();
        $this->comment('Pegá este valor en la variable `jwt_token` del environment de Postman.');
        $this->comment('Verificá con: GET {{base_url}}/api/v1/me');

        if ($companyNit !== null) {
            $this->comment("Empresa activa preseteada: {$companyNit}");
        }
        if ($branchId !== null) {
            $this->comment("Sede activa preseteada: {$branchId}");
        }

        return self::SUCCESS;
    }
}
