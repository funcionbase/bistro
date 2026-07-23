<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Flujo de automatización (n8n) — opcional y por (NIT, sede) (§5.6).
 *
 * Es el dueño del token del bot (§7.5.1): el token vive HASHEADO en `token_hash`
 * (SHA-256 del componente aleatorio), nunca en claro. El secreto de firma del
 * webhook saliente (`secret_encrypted`) sí es cifrado reversible: se necesita
 * el valor para firmar el HMAC del push.
 *
 * @property string $id
 * @property string $company_nit
 * @property ?string $branch_id
 * @property bool $enabled
 * @property string $url
 * @property ?string $secret_encrypted
 * @property ?array<int, string> $events
 * @property ?string $token_hash
 * @property ?string $token_last4
 */
class AutomationFlow extends Model
{
    use HasUuids;

    /** Prefijo del token del bot: grepable en secret scanning e identifica el flujo. */
    public const TOKEN_PREFIX = 'ffw_';

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'branch_id',
        'label',
        'enabled',
        'url',
        'secret_encrypted',
        'events',
        'token_hash',
        'token_last4',
        'token_created_at',
        'last_delivery_at',
    ];

    /** El hash del token y el secreto de firma NUNCA salen serializados. */
    protected $hidden = [
        'secret_encrypted',
        'token_hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'events' => 'array',
            'secret_encrypted' => 'encrypted',
            'token_created_at' => 'datetime',
            'last_delivery_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @param  Builder<AutomationFlow>  $query
     * @return Builder<AutomationFlow>
     */
    public function scopeForCompany(Builder $query, string $nit): Builder
    {
        return $query->where('company_nit', $nit);
    }

    /**
     * Resuelve el flujo que aplica a un chat: primero el de su sede, luego el de
     * empresa como fallback. Solo habilitados. Sin flujo => automatización off,
     * camino normal (§5.6). Una consulta, sin joins.
     */
    public static function resolveForChat(Chat $chat): ?self
    {
        return self::resolveForScope($chat->company_nit, $chat->branch_id);
    }

    /**
     * Igual que resolveForChat pero por (NIT, sede) explícitos — para eventos que
     * no salen de un chat (p.ej. `channel.status.changed`, que es del canal).
     */
    public static function resolveForScope(string $companyNit, ?string $branchId): ?self
    {
        return self::query()
            ->where('company_nit', $companyNit)
            ->where('enabled', true)
            ->where(function (Builder $q) use ($branchId): void {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            })
            // branch_id IS NULL => 1 (empresa) queda DESPUÉS del de sede (0).
            ->orderByRaw('(branch_id IS NULL)')
            ->first();
    }

    /**
     * Genera (o rota) el token del bot. Devuelve el token EN CLARO una sola vez
     * (patrón PAT de GitHub): solo se guarda su hash. El llamador debe persistir
     * el modelo después. Requiere que `id` ya exista (guardar el flujo primero).
     *
     * Formato: `ffw_<uuid_sin_guiones>_<32B base64url>`.
     */
    public function mintToken(): string
    {
        $random = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->token_hash = hash('sha256', $random);
        $this->token_last4 = substr($random, -4);
        $this->token_created_at = now();

        $uuidHex = str_replace('-', '', (string) $this->id);

        return self::TOKEN_PREFIX.$uuidHex.'_'.$random;
    }

    /**
     * Verifica un token entrante. Extrae el uuid del prefijo (una query por PK,
     * sin escaneo), compara el hash con `hash_equals`, y rechaza si el flujo está
     * deshabilitado. Devuelve el flujo o null.
     *
     * `explode(..., 3)`: el uuid hex no lleva `_`, así que los dos primeros `_`
     * son los delimitadores y el resto (el aleatorio, que en base64url SÍ puede
     * tener `_`) queda intacto en el tercer elemento.
     */
    public static function authenticate(string $token): ?self
    {
        if (! str_starts_with($token, self::TOKEN_PREFIX)) {
            return null;
        }

        $parts = explode('_', $token, 3);
        if (count($parts) !== 3) {
            return null;
        }

        $uuidHex = $parts[1];
        $random = $parts[2];

        if (! preg_match('/^[0-9a-f]{32}$/', $uuidHex) || $random === '') {
            return null;
        }

        $uuid = sprintf(
            '%s-%s-%s-%s-%s',
            substr($uuidHex, 0, 8),
            substr($uuidHex, 8, 4),
            substr($uuidHex, 12, 4),
            substr($uuidHex, 16, 4),
            substr($uuidHex, 20, 12),
        );

        /** @var self|null $flow */
        $flow = self::query()->whereKey($uuid)->first();

        if ($flow === null || ! $flow->enabled || $flow->token_hash === null) {
            return null;
        }

        if (! hash_equals($flow->token_hash, hash('sha256', $random))) {
            return null;
        }

        return $flow;
    }
}
