<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Table\JoinTableRequest;
use App\Http\Resources\TableJoinContextResource;
use App\Http\Resources\TableMenuContextResource;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Table;
use App\Services\TableSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Flujo público de unión a mesa con QR (#191) — API REST.
 *
 * Sin auth. Identidad del comensal = cookie firmada httpOnly `tdt_*`.
 * Rate-limit `table-public` (IP + qr_token) aplicado a nivel de ruta.
 *
 * Migrado desde `Web\TableJoinController` (#191 SPA): las acciones que antes
 * devolvían `Inertia::render` ahora responden JSON; el frontend SPA hidrata
 * las páginas `table/join` y `table/menu` con fetch a estos endpoints.
 *
 * Endpoints (prefijo `/api/v1/public/table/{qr_token}`):
 *  - GET  /                → contexto de la pantalla de unión (table/branch/company).
 *  - POST /join            → crea/une comensal, setea cookie, devuelve contexto del menú.
 *  - GET  /contact-lookup  → autocompletado del nombre por celular.
 */
class TableJoinController extends Controller
{
    public function __construct(private readonly TableSessionService $tableSessions) {}

    /**
     * Contexto de la pantalla de unión: mesa, sede y branding del restaurante.
     *
     * Si la cookie del dispositivo ya apunta a un guest activo en esta mesa,
     * el frontend debe llevar directo al menú — lo señalamos con
     * `already_joined: true` para que la SPA navegue sin pedir nombre de nuevo.
     */
    public function show(Request $request, string $qrToken): JsonResponse
    {
        $table = $this->tableSessions->resolveTable($qrToken);
        $this->ensureCompanyOperational($table->company_nit);

        $deviceToken = $this->readDeviceCookie($request, $qrToken);
        $alreadyJoined = false;

        if ($deviceToken !== null) {
            $guest = $this->tableSessions->resolveGuestByDeviceToken($table, $deviceToken);
            // Solo "ya en sesión" si la sesión sigue activa. Una sesión
            // closed/expired no redirige al menú — el comensal puede abrir una
            // nueva sesión en la misma mesa sin necesidad del mesero.
            $alreadyJoined = $guest !== null
                && ! in_array($guest->session->status, config('tables.terminal_statuses', ['closed', 'expired']), true);
        }

        $branch = Branch::query()->whereKey($table->branch_id)->firstOrFail();
        $company = Company::query()->where('nit', $table->company_nit)->firstOrFail();

        return (new TableJoinContextResource($table, $branch, $company, $qrToken))
            ->additional(['already_joined' => $alreadyJoined])
            ->response();
    }

    /**
     * Procesa el form de unión. Crea o une comensal y setea la cookie firmada.
     *
     * Devuelve directamente el contexto del menú (misma shape que el endpoint
     * `GET .../menu`) para que la SPA pueda renderizar la pantalla sin un
     * segundo round-trip tras unirse.
     */
    public function store(JoinTableRequest $request, string $qrToken): JsonResponse
    {
        $table = $this->tableSessions->resolveTable($qrToken);
        $this->ensureCompanyOperational($table->company_nit);

        $displayName = (string) $request->input('display_name');
        $rawPhone = (string) $request->input('phone');

        try {
            $result = $this->tableSessions->openOrJoin($table, $displayName, $rawPhone, $request);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['phone' => [$e->getMessage()]],
            ], 422);
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['session' => [$e->getMessage()]],
            ], 422);
        }

        $guest = $result['guest'];
        $branch = Branch::query()->whereKey($table->branch_id)->firstOrFail();
        $company = Company::query()->where('nit', $table->company_nit)->firstOrFail();

        $cookieName = $this->cookieNameFor($qrToken);
        $cookieTtlMinutes = (int) config('tables.device_token_ttl_hours', 12) * 60;

        // Cookie firmada httpOnly que ataja al comensal en esta mesa.
        //
        // SameSite=None + Secure: el deploy es cross-origin same-site — el SPA
        // (`bistro.example.com`) y la API (`bistro-api.example.com`)
        // viven en hosts distintos bajo el mismo site. Con `SameSite=Lax` el
        // navegador NO adjuntaría esta cookie en los fetch XHR del flujo de
        // mesa hacia la API. `None` permite el envío cross-site y `Secure`
        // (obligatorio junto a `None`) lo limita a HTTPS. CORS habilita el
        // request con credenciales (ver `config/cors.php`).
        //
        // `secure` se lee de `config('session.secure')` para que en dev sobre
        // http://localhost (proxy de Vite, same-origin) la cookie siga siendo
        // aceptada: ahí `SESSION_SECURE_COOKIE=false` y el flujo es same-origin.
        //
        // Args posicionales de JsonResponse::cookie(): nombre, valor, minutos,
        // path, domain, secure, httpOnly, raw, sameSite.
        $secure = (bool) config('session.secure', $request->isSecure());

        return (new TableMenuContextResource($guest, $table, $branch, $company, $qrToken))
            ->response()
            ->setStatusCode(201)
            ->cookie(
                $cookieName,
                $guest->device_token,
                $cookieTtlMinutes,
                '/',
                null,
                $secure,
                true,
                false,
                $secure ? 'none' : 'lax',
            );
    }

    /**
     * Autocompletado del nombre del comensal a partir del celular.
     *
     * Endpoint público (identidad = QR) que permite al frontend del flujo de
     * mesa prellenar el nombre cuando el celular ya está registrado como
     * Contact de la empresa. Devuelve `{ name: null }` silenciosamente cuando
     * el phone es inválido o el contacto no existe — la UI no debe distinguir
     * entre "no existe" y "número malformado", solo dejar el input en blanco.
     */
    public function lookupContact(Request $request, string $qrToken): JsonResponse
    {
        $table = $this->tableSessions->resolveTable($qrToken);
        $this->ensureCompanyOperational($table->company_nit);

        $rawPhone = trim((string) $request->query('phone', ''));
        if ($rawPhone === '') {
            return response()->json(['name' => null]);
        }

        try {
            $normalized = $this->tableSessions->normalizePhone($rawPhone);
        } catch (\InvalidArgumentException) {
            return response()->json(['name' => null]);
        }

        // El canónico ya es uniforme (`57XXXXXXXXXX`) tras normalizar datos y
        // encauzar todas las escrituras (mutators + normalizePhone). Se conserva
        // la variante SIN indicativo (10 dígitos) por si quedó alguna fila legacy
        // sin migrar — tolerancia de lectura, sin costo.
        $candidates = array_values(array_unique([
            $normalized,
            str_starts_with($normalized, '57') ? substr($normalized, 2) : $normalized,
        ]));

        // Scope escape justificado (#192): flujo público sin JWT. El contacto
        // es único por (company_nit, phone) sin importar la sede que lo creó
        // — recuperar el nombre por phone para autocomplete es lookup binario,
        // no listado.
        $contact = Contact::withoutBranchScope()
            ->where('company_nit', $table->company_nit)
            ->whereIn('phone', $candidates)
            ->first(['name']);

        return response()->json([
            'name' => $contact?->name,
        ]);
    }

    /**
     * Cookie por QR token (un device_token distinto para cada mesa que escanee
     * el mismo dispositivo). Evita que reentrar a una mesa anterior cargue la
     * sesión equivocada cuando el cliente cambia de local.
     */
    private function cookieNameFor(string $qrToken): string
    {
        return 'tdt_'.substr(hash('sha256', $qrToken), 0, 16);
    }

    private function readDeviceCookie(Request $request, string $qrToken): ?string
    {
        $value = $request->cookie($this->cookieNameFor($qrToken));

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Guard de empresa operativa para los endpoints públicos del flujo
     * de mesa con QR. Si la empresa está bloqueada por mora, abortamos con
     * 404 (indistinguible de un QR inválido). El comensal no debe poder
     * deducir que el restaurante está en mora; ve "QR no encontrado" como
     * cualquier otro error inocente.
     */
    private function ensureCompanyOperational(string $companyNit): void
    {
        $company = Company::query()->where('nit', $companyNit)->first();

        if ($company === null || ! $company->canServePublic()) {
            abort(404);
        }
    }
}
