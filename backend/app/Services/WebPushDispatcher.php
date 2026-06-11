<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Envío de notificaciones Web Push (#149).
 *
 * Encapsula `minishlink/web-push` para que el resto del backend sólo vea:
 *
 *   $dispatcher->send($subscription, $payload);
 *
 * Manejo de errores:
 *  - `410 Gone` o `404 Not Found`: el endpoint del navegador ya no es
 *    válido (user desinstaló la PWA, revocó permiso, o cambió de browser).
 *    → Soft-revoke (set `revoked_at = now()`).
 *  - Otros errores transitorios (red, 5xx): log + dejar el cron seguir;
 *    no se hace retry porque cada job ya tiene su propio retry policy.
 *
 * El servicio NO valida permisos del recipiente — eso es responsabilidad
 * del caller (job). Acá sólo se cifra y manda el payload.
 *
 * @phpstan-type PushPayload array{
 *   title: string,
 *   body: string,
 *   url?: string,
 *   tag?: string,
 *   icon?: string,
 *   badge?: string,
 *   data?: array<string, mixed>,
 * }
 */
class WebPushDispatcher
{
    private ?WebPush $client = null;

    /**
     * @param  PushPayload  $payload
     * @return bool true si el envío fue exitoso (status 2xx), false si la sub se revocó o falló.
     */
    public function send(PushSubscription $subscription, array $payload): bool
    {
        $client = $this->resolveClient();
        if ($client === null) {
            Log::warning('WebPush deshabilitado: VAPID keys ausentes.', [
                'subscription_id' => $subscription->id,
            ]);

            return false;
        }

        $browserSub = Subscription::create([
            'endpoint' => $subscription->endpoint,
            'publicKey' => $subscription->p256dh,
            'authToken' => $subscription->auth,
        ]);

        try {
            $report = $client->sendOneNotification(
                $browserSub,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
        } catch (Throwable $e) {
            Log::error('WebPush sendOneNotification threw exception.', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($report->isSuccess()) {
            $subscription->last_seen_at = Carbon::now();
            $subscription->save();

            return true;
        }

        $reason = $report->getReason();
        $status = $report->getResponse()?->getStatusCode();

        // 410 Gone / 404 Not Found = endpoint inválido — soft-revoke.
        if ($status === 410 || $status === 404 || $report->isSubscriptionExpired()) {
            $subscription->revoked_at = Carbon::now();
            $subscription->save();

            Log::info('WebPush subscription auto-revocada por endpoint inválido.', [
                'subscription_id' => $subscription->id,
                'status' => $status,
                'reason' => $reason,
            ]);

            return false;
        }

        Log::warning('WebPush send falló sin revocar.', [
            'subscription_id' => $subscription->id,
            'status' => $status,
            'reason' => $reason,
        ]);

        return false;
    }

    public function isConfigured(): bool
    {
        return $this->resolveClient() !== null;
    }

    /**
     * Tag-key helper para colapsar notificaciones del mismo target en el OS.
     */
    public static function pendingApprovalTag(string $orderId): string
    {
        $prefix = (string) config('notifications.dispatch.pending_approval_payload_tag_prefix', 'pending-approval-');

        return $prefix.$orderId;
    }

    public static function inventoryDigestTag(string $isoDate): string
    {
        $prefix = (string) config('notifications.inventory_digest.tag_prefix', 'inventory-digest-');

        return $prefix.$isoDate;
    }

    private function resolveClient(): ?WebPush
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $publicKey = (string) config('notifications.web_push.vapid_public_key');
        $privateKey = (string) config('notifications.web_push.vapid_private_key');
        $subject = (string) config('notifications.web_push.vapid_subject');

        if ($publicKey === '' || $privateKey === '' || $subject === '') {
            return null;
        }

        $this->client = new WebPush([
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ], defaultOptions: [
            'TTL' => 3600,
            'urgency' => 'normal',
        ]);

        return $this->client;
    }

    /**
     * Determina si un user debería recibir push de tipo `orders.update`
     * para una orden de una sede específica.
     *
     * Reglas:
     *  - Membership activa en la empresa.
     *  - Owner (role.is_system=true) bypassea check de permiso y de sede.
     *  - Resto: role tiene `orders.update` activo Y acceso al branch via
     *    `branch_users` (a menos que tenga `metrics.view_all_branches`).
     */
    public static function userCanReceiveOrderUpdate(User $user, string $companyNit, ?string $branchId): bool
    {
        $membership = $user->companyMemberships()
            ->where('company_nit', $companyNit)
            ->with('role.permissions.feature:id,slug')
            ->first();

        if ($membership === null || ! $membership->isActive() || $membership->role === null) {
            return false;
        }

        $role = $membership->role;

        if ($role->is_system) {
            return true;
        }

        $hasPerm = $role->permissions->contains(
            fn ($perm) => $perm->feature?->slug === 'orders.update' && (bool) $perm->can_update === true,
        );
        if (! $hasPerm) {
            return false;
        }

        if ($branchId === null) {
            return true;
        }

        return $user->canAccessBranch($branchId);
    }

    /**
     * Similar a `userCanReceiveOrderUpdate` pero para el digest de inventario.
     * El destinatario debe poder ver alertas — permisos `reports.read` o
     * `inventory.read` cubren ese caso operativo.
     */
    public static function userCanReceiveInventoryDigest(User $user, string $companyNit): bool
    {
        $membership = $user->companyMemberships()
            ->where('company_nit', $companyNit)
            ->with('role.permissions.feature:id,slug')
            ->first();

        if ($membership === null || ! $membership->isActive() || $membership->role === null) {
            return false;
        }

        $role = $membership->role;

        if ($role->is_system) {
            return true;
        }

        return $role->permissions->contains(function ($perm) {
            $slug = $perm->feature?->slug;

            return ($slug === 'reports.read' && (bool) $perm->can_read === true)
                || ($slug === 'inventory.read' && (bool) $perm->can_read === true);
        });
    }
}
