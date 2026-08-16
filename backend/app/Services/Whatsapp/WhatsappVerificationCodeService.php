<?php

namespace App\Services\Whatsapp;

use App\Models\Company;
use App\Models\CompanyWhatsappAccount;
use App\Models\CompanyWhatsappAccountEvent;
use App\Models\User;
use App\Models\WhatsappVerificationCode;
use App\Notifications\WhatsappActionVerificationCodeNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Emite y valida codigos de 6 digitos para acciones sensibles sobre la cuenta
 * de WhatsApp (connect, swap, disconnect).
 *
 * Reglas:
 *   - Codigo de 6 digitos numericos, hash bcrypt en BD.
 *   - TTL 10 min.
 *   - 3 intentos fallidos invalidan el codigo.
 *   - 3 codigos por empresa cada 30 min (rate limit).
 *   - SIEMPRE se envia al owner (role_type='owner') de la empresa, aunque el
 *     requester sea otro usuario con permiso RBAC.
 */
class WhatsappVerificationCodeService
{
    public function request(
        Company $company,
        User $requester,
        string $action,
        ?string $ip = null,
        ?string $userAgent = null,
    ): WhatsappVerificationCode {
        if (! in_array($action, WhatsappVerificationCode::ACTIONS, true)) {
            throw new RuntimeException("Accion inválida: {$action}");
        }

        $owner = $this->resolveOwner($company);

        if ($owner === null) {
            throw new RuntimeException('La empresa no tiene un owner activo. Contacta a soporte.');
        }

        $this->enforceRateLimit($company);

        $code = (string) random_int(100000, 999999);
        $rejectToken = bin2hex(random_bytes(24));

        $record = DB::transaction(function () use ($company, $requester, $owner, $action, $code, $rejectToken, $ip, $userAgent) {
            return WhatsappVerificationCode::create([
                'company_nit' => $company->nit,
                'requester_user_id' => $requester->id,
                'owner_user_id' => $owner->id,
                'action' => $action,
                'code_hash' => Hash::make($code),
                'reject_token' => $rejectToken,
                'ip_address' => $ip,
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                'expires_at' => now()->addMinutes(WhatsappVerificationCode::TTL_MINUTES),
                'attempts' => 0,
                'created_at' => now(),
            ]);
        });

        $owner->notify(new WhatsappActionVerificationCodeNotification(
            company: $company,
            requester: $requester,
            owner: $owner,
            action: $action,
            code: $code,
            rejectToken: $rejectToken,
            ip: $ip,
            userAgent: $userAgent,
        ));

        $this->logAccountEvent($company, 'verification_code_requested', [
            'action' => $action,
            'requester_id' => $requester->id,
        ], $requester->id);

        return $record;
    }

    public function verify(Company $company, User $requester, string $action, string $code, bool $consume = true): WhatsappVerificationCode
    {
        $record = WhatsappVerificationCode::query()
            ->where('company_nit', $company->nit)
            ->where('requester_user_id', $requester->id)
            ->where('action', $action)
            ->whereNull('consumed_at')
            ->whereNull('rejected_at')
            ->latest('id')
            ->first();

        if ($record === null) {
            throw new RuntimeException('No hay un codigo activo. Solicita uno nuevo.');
        }

        if ($record->isExpired()) {
            throw new RuntimeException('El codigo expiro. Solicita uno nuevo.');
        }

        if ($record->isLockedOut()) {
            throw new RuntimeException('Codigo bloqueado tras varios intentos fallidos. Solicita uno nuevo.');
        }

        if (! Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');

            $this->logAccountEvent($company, 'verification_code_failed', [
                'action' => $action,
                'attempts' => $record->attempts,
            ], $requester->id);

            throw new RuntimeException('Codigo incorrecto.');
        }

        if ($consume) {
            $record->forceFill(['consumed_at' => now()])->save();

            $this->logAccountEvent($company, 'verification_code_consumed', [
                'action' => $action,
            ], $requester->id);
        }

        return $record->refresh();
    }

    public function rejectByToken(string $token): ?WhatsappVerificationCode
    {
        $record = WhatsappVerificationCode::query()
            ->where('reject_token', $token)
            ->whereNull('consumed_at')
            ->whereNull('rejected_at')
            ->first();

        if ($record === null) {
            return null;
        }

        $record->forceFill(['rejected_at' => now()])->save();

        // Lookup por nit (UNIQUE) — la PK de companies es id uuid.
        $company = Company::query()->where('nit', $record->company_nit)->first();

        if ($company !== null) {
            $this->logAccountEvent($company, 'verification_rejected_by_owner', [
                'action' => $record->action,
                'requester_id' => $record->requester_user_id,
            ], $record->owner_user_id);
        }

        return $record;
    }

    private function resolveOwner(Company $company): ?User
    {
        $ownerRoleName = config('roles.role_names.owner', 'Propietario');

        return User::query()
            ->whereIn('id', function ($q) use ($company, $ownerRoleName) {
                $q->select('company_users.user_id')
                    ->from('company_users')
                    ->join('company_roles', 'company_users.company_role_id', '=', 'company_roles.id')
                    ->where('company_users.company_nit', $company->nit)
                    ->where('company_roles.is_system', true)
                    ->where('company_roles.name', $ownerRoleName);
            })
            ->orderBy('id')
            ->first();
    }

    private function enforceRateLimit(Company $company): void
    {
        $window = now()->subMinutes(WhatsappVerificationCode::RATE_LIMIT_WINDOW_MINUTES);

        $count = WhatsappVerificationCode::query()
            ->where('company_nit', $company->nit)
            ->where('created_at', '>=', $window)
            ->count();

        if ($count >= WhatsappVerificationCode::RATE_LIMIT_REQUESTS) {
            throw new RuntimeException(
                'Demasiadas solicitudes de codigo. Espera unos minutos antes de intentar de nuevo.'
            );
        }
    }

    private function logAccountEvent(Company $company, string $eventType, array $payload, ?string $actorUserId = null): void
    {
        // El OTP de connect/swap/disconnect aplica hoy al canal de empresa.
        $account = CompanyWhatsappAccount::query()
            ->where('company_nit', $company->nit)
            ->whereNull('branch_id')
            ->withTrashed()
            ->first();

        if ($account === null) {
            return; // Sin cuenta aun (caso 'connect'): no log de eventos de cuenta.
        }

        CompanyWhatsappAccountEvent::create([
            'company_whatsapp_account_id' => $account->id,
            'event_type' => $eventType,
            'payload' => $payload,
            'actor_user_id' => $actorUserId,
            'created_at' => now(),
        ]);
    }
}
