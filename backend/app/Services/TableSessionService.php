<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\TableSessionGuest;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Servicio del flujo público de mesa con QR.
 *
 * Resuelve `/t/{qr_token}` → mesa → sesión activa (o nueva), enrola al
 * comensal como `TableSessionGuest` (con captura de Contact en CRM) y
 * registra `device_token` firmado para la cookie httpOnly.
 *
 * Concurrencia: `Table::lockForUpdate` dentro de `DB::transaction`, más el
 * partial unique index `table_sessions_one_active_per_table_idx` en BD,
 * impiden que dos clientes simultáneos creen dos sesiones para la misma mesa.
 *
 * Phone: input se normaliza (strip espacios, guiones, paréntesis, prefijo
 * `+57`/`57` opcional). Debe matchear `config('tables.guest_phone_regex')`
 * tras normalizar. Bloquea fijos y extranjeros (decisión consciente).
 *
 * Aislamiento por sede: este servicio sirve el flujo público sin JWT
 * — no hay `active_branch_id` en el request. Las queries usan
 * `withoutBranchScope()` porque el scope global no aplicaría igual; el
 * filtro explícito por `qr_token`, `table_id` o `session_id` (uuid/id
 * único cross-sede) delimita la sede correctamente.
 */
class TableSessionService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Resuelve un QR token a su mesa. 404 si el token no existe o la mesa
     * está archivada / no aplicable al flujo.
     *
     * @throws NotFoundHttpException
     */
    public function resolveTable(string $qrToken): Table
    {
        try {
            $table = Table::withoutBranchScope()
                ->whereNull('archived_at')
                ->where('qr_token', $qrToken)
                ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new NotFoundHttpException('Mesa no encontrada.');
        }

        // Sede archivada = QR muerto. Archivar una sede NO archiva sus mesas,
        // así que sin este guard el QR impreso de una sede retirada seguía
        // permitiendo unirse y pedir contra ella (join/menu/state/items pasan
        // todos por acá). 404 indistinguible de mesa inexistente.
        $branchArchived = Branch::query()
            ->whereKey($table->branch_id)
            ->whereNotNull('archived_at')
            ->exists();

        if ($branchArchived) {
            throw new NotFoundHttpException('Mesa no encontrada.');
        }

        return $table;
    }

    /**
     * Devuelve la sesión activa de la mesa (open o locked) si existe.
     */
    public function activeSessionFor(Table $table): ?TableSession
    {
        return TableSession::withoutBranchScope()
            ->where('table_id', $table->id)
            ->whereIn('status', config('tables.active_statuses'))
            ->first();
    }

    /**
     * Abre una nueva sesión o une al comensal a la activa.
     *
     * Si no existe sesión activa, crea una con `status=open` y `expires_at`
     * calculado desde `config('tables.session_expiration_hours')`. Si ya hay
     * sesión `open`, agrega un guest. Si está `locked` y `accepts_new_guests=false`,
     * lanza error explícito (la UI debe mostrar copy claro al comensal).
     *
     * `Contact::firstOrCreate` por `(company_nit, phone)` — un cliente único
     * por empresa. Si el contacto existía con `name` vacío y ahora llega uno,
     * se actualiza (es un dato útil que llegó después). NO se mueve `branch_id`:
     * el cliente pertenece a la sede que lo capturó primero.
     *
     * @return array{session: TableSession, guest: TableSessionGuest, is_new_session: bool, is_new_contact: bool}
     */
    public function openOrJoin(
        Table $table,
        string $displayName,
        string $rawPhone,
        Request $request,
    ): array {
        $phone = $this->normalizePhone($rawPhone);
        $displayName = trim($displayName);

        return DB::transaction(function () use ($table, $displayName, $phone, $request) {
            // Lock pesimista sobre la mesa para evitar carrera entre dos requests
            // que abran sesión simultáneamente. El partial unique index en BD
            // es el segundo cinturón.
            $lockedTable = Table::withoutBranchScope()
                ->whereKey($table->id)
                ->lockForUpdate()
                ->firstOrFail();

            $session = $this->activeSessionFor($lockedTable);
            $isNewSession = false;

            if ($session === null) {
                $session = $this->createSession($lockedTable);
                $isNewSession = true;
            } else {
                $this->guardJoinAllowed($session);
            }

            [$contact, $isNewContact, $renamedFrom] = $this->upsertContact($lockedTable, $displayName, $phone);

            $guest = $this->createGuest($session, $contact, $displayName, $phone);

            $this->audit->log(
                $isNewSession ? 'table.session.opened' : 'table.guest.joined',
                user: null,
                auditable: $session,
                data: [
                    'table_id' => $lockedTable->id,
                    'table_number' => $lockedTable->number,
                    'branch_id' => $lockedTable->branch_id,
                    'company_nit' => $lockedTable->company_nit,
                    'guest_id' => $guest->id,
                    'contact_id' => $contact->id,
                    'phone' => $phone,
                    'is_new_contact' => $isNewContact,
                    'is_new_session' => $isNewSession,
                    'contact_name_updated' => $renamedFrom !== null,
                    'contact_previous_name' => $renamedFrom,
                    'contact_new_name' => $renamedFrom !== null ? $displayName : null,
                ],
                request: $request,
            );

            return [
                'session' => $session,
                'guest' => $guest,
                'is_new_session' => $isNewSession,
                'is_new_contact' => $isNewContact,
            ];
        });
    }

    /**
     * Marca la sesión como `locked` (segunda vez que un mesero aprueba una
     * tanda) y refresca el cache `tables.status = occupied`.
     */
    public function lockSession(TableSession $session): void
    {
        if ($session->status === 'locked') {
            return;
        }

        DB::transaction(function () use ($session) {
            $session->status = 'locked';
            $session->save();

            Table::withoutBranchScope()
                ->whereKey($session->table_id)
                ->update(['status' => 'occupied']);
        });
    }

    /**
     * Cierra la sesión y libera la mesa. Se invoca desde caja al pagarse el
     * total de la mesa, o desde mesero al cerrar manualmente una mesa vacía.
     */
    public function closeSession(TableSession $session): void
    {
        DB::transaction(function () use ($session) {
            $session->status = 'closed';
            $session->closed_at = Carbon::now();
            $session->save();

            Table::withoutBranchScope()
                ->whereKey($session->table_id)
                ->update(['status' => 'available']);
        });
    }

    /**
     * Valida que sea un celular colombiano (10 dígitos comenzando en 3) y
     * devuelve el canónico de almacenamiento `57XXXXXXXXXX` (con indicativo,
     * sin `+`), consistente con Contact/Order/Chat. Antes devolvía 10 dígitos
     * sin indicativo, lo que creaba contactos en un formato distinto al del CRM
     * y duplicaba al mismo cliente. Lanza si el número no valida.
     */
    public function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/[\s\-\(\)]/', '', $raw) ?? '';

        if (str_starts_with($digits, '+57')) {
            $digits = substr($digits, 3);
        } elseif (str_starts_with($digits, '57') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }

        $regex = config('tables.guest_phone_regex');
        if (! is_string($regex) || preg_match($regex, $digits) !== 1) {
            throw new \InvalidArgumentException(
                'Teléfono inválido. Debe ser un celular colombiano (10 dígitos comenzando en 3).'
            );
        }

        return PhoneNumber::toColombianCanonical($digits);
    }

    /**
     * Verifica si la sesión acepta nuevos comensales.
     *
     * @throws \DomainException
     */
    private function guardJoinAllowed(TableSession $session): void
    {
        if ($session->status === 'open') {
            return;
        }

        if ($session->status === 'locked' && $session->accepts_new_guests) {
            return;
        }

        throw new \DomainException(
            'La sesión de la mesa no acepta nuevos comensales. Pídele al mesero que la habilite.'
        );
    }

    /**
     * Crea una sesión nueva para la mesa con `expires_at` configurado.
     */
    private function createSession(Table $table): TableSession
    {
        $hours = (int) config('tables.session_expiration_hours', 4);

        $session = new TableSession;
        $session->table_id = $table->id;
        $session->company_nit = $table->company_nit;
        $session->branch_id = $table->branch_id;
        $session->opened_at = Carbon::now();
        $session->expires_at = Carbon::now()->addHours($hours);
        $session->status = 'open';
        $session->accepts_new_guests = true;
        $session->save();

        return $session;
    }

    /**
     * Garantiza que exista una `TableSession` activa para la mesa de número
     * dado (sede + empresa de quien llama). Si ya hay una activa la devuelve;
     * si no, crea una "lightweight" (sin guests). Usado por la caja para que
     * toda orden de mesa quede agrupada en una sesión y se pueda cobrar
     * consolidado, sea que la mesa se haya abierto por QR o por el cajero.
     *
     * Devuelve `null` si la mesa no existe en esa sede (caller decide qué
     * hacer con eso — típicamente seguir sin vincular sesión).
     */
    public function ensureSessionForTable(string $companyNit, string $branchId, string $tableNumber): ?TableSession
    {
        $table = Table::query()
            ->withoutGlobalScopes()
            ->where('company_nit', $companyNit)
            ->where('branch_id', $branchId)
            ->where('number', (string) $tableNumber)
            ->whereNull('archived_at')
            ->first();

        if ($table === null) {
            return null;
        }

        $session = TableSession::query()
            ->withoutGlobalScopes()
            ->where('table_id', $table->id)
            ->whereIn('status', config('tables.active_statuses'))
            ->first();

        if ($session !== null) {
            return $session;
        }

        return $this->createSession($table);
    }

    /**
     * `firstOrCreate` sobre contacts. Setea explícitamente `branch_id` para
     * que el trait `BelongsToBranch` no intente leerlo del request (público,
     * sin JWT). Si el contacto existía con name vacío y ahora llega uno, lo
     * actualiza — es información útil que llegó después.
     *
     * @return array{0: Contact, 1: bool, 2: ?string} [contact, isNewContact, previousNameIfRenamed]
     */
    private function upsertContact(Table $table, string $displayName, string $phone): array
    {
        // Coexisten dos formatos de Contact.phone en la BD: 10 dígitos (los
        // que crea este servicio históricamente) y 12 dígitos con prefijo 57
        // (los que crea CrmService desde órdenes/WhatsApp). Para no duplicar
        // un cliente del CRM al unirse por QR, preferimos el formato canónico
        // del CRM (12 dig) cuando exista — así una actualización de nombre
        // desde la mesa se refleja en /clients sin crear filas duplicadas.
        // Como fallback, usamos el formato de 10 dig.
        $contact = Contact::withoutBranchScope()
            ->where('company_nit', $table->company_nit)
            ->where('phone', '57'.$phone)
            ->first()
            ?? Contact::withoutBranchScope()
                ->where('company_nit', $table->company_nit)
                ->where('phone', $phone)
                ->first();

        $isNew = false;
        $previousName = null;

        if ($contact === null) {
            $contact = new Contact;
            $contact->company_nit = $table->company_nit;
            $contact->branch_id = $table->branch_id;
            $contact->phone = $phone;
            $contact->name = $displayName;
            $contact->save();
            $isNew = true;

            return [$contact, $isNew, null];
        }

        // Contacto preexistente: si el comensal escribió o ajustó un nombre
        // distinto al guardado, persistimos su versión. Esto permite que el
        // usuario corrija el autocompletado del frontend (por ejemplo cuando
        // varias personas comparten el mismo celular y se identifican con
        // nombres distintos por visita). La auditoría queda en el caller con
        // previous/new para trazabilidad.
        $trimmed = trim($displayName);
        $currentName = trim((string) $contact->name);

        if ($trimmed !== '' && $trimmed !== $currentName) {
            $previousName = $contact->name;
            $contact->name = $displayName;
            $contact->save();
        }

        return [$contact, $isNew, $previousName];
    }

    /**
     * Crea el guest dentro de la sesión con device_token único.
     */
    private function createGuest(
        TableSession $session,
        Contact $contact,
        string $displayName,
        string $phone,
    ): TableSessionGuest {
        $guest = new TableSessionGuest;
        $guest->table_session_id = $session->id;
        $guest->contact_id = $contact->id;
        $guest->display_name = mb_substr($displayName, 0, 80);
        $guest->phone = $phone;
        $guest->device_token = $this->generateDeviceToken();
        $guest->joined_at = Carbon::now();
        $guest->save();

        return $guest;
    }

    /**
     * Token aleatorio para identificar al comensal sin login. Lleva como
     * cookie httpOnly + signed (Laravel firma a nivel de framework).
     */
    private function generateDeviceToken(): string
    {
        return Str::random(40);
    }

    /**
     * Resuelve un par (sesión, guest) a partir del token de cookie. Usado por
     * el middleware ResolveTableGuest.
     */
    public function resolveGuestByDeviceToken(Table $table, string $deviceToken): ?TableSessionGuest
    {
        // Incluye sesiones expiradas/cerradas para que el poll de `/state`
        // pueda devolver `status: 'expired'` al frontend — sin esto la
        // resolución falla con 401 y el cliente nunca se entera de la expiración.
        // Mutaciones siguen protegidas por `guardSessionAllowsChanges`.
        $allStatuses = array_merge(
            config('tables.active_statuses'),
            config('tables.terminal_statuses'),
        );

        return TableSessionGuest::query()
            ->whereHas('session', function ($q) use ($table, $allStatuses) {
                $q->where('table_id', $table->id)
                    ->whereIn('status', $allStatuses);
            })
            ->where('device_token', $deviceToken)
            ->with('session')
            ->first();
    }

    /**
     * Devuelve la sede asociada a la mesa. Útil para hidratar branding en
     * la UI pública sin permitir al cliente elegir sede.
     */
    public function branchFor(Table $table): Branch
    {
        return Branch::query()->whereKey($table->branch_id)->firstOrFail();
    }
}
