<?php

namespace App\Services;

use App\Models\Chat;
use App\Models\ClientNote;
use App\Models\ClientTag;
use App\Models\Contact;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de CRM básico (#123 + refactor #235).
 *
 * Vista consolidada del cliente (cross-sede): cada `Contact` es el cliente.
 * Desde #235 la identidad canónica es `contacts.id`; las órdenes apuntan a
 * `contact_id`. Para órdenes legacy sin contact_id, hacemos fallback por
 * `orders.client_phone = contacts.phone` (solo cuando el phone es único en
 * la empresa).
 *
 *  - listClients(): listado paginado de Contacts con KPIs en SQL.
 *  - profile(): perfil completo de un Contact con historial, chats, notas, tags.
 *
 * Segmentación (en PHP, post-query):
 *  - vip       — gasto en top 10 últimos 90 días.
 *  - recurrent — ≥3 órdenes en últimos 60 días.
 *  - new       — primera orden < 30 días, o contact sin órdenes recién creado.
 *  - inactive  — última orden > 60 días con ≥1 orden previa.
 *  - at_risk   — cancellation_rate > 25% con ≥4 órdenes totales.
 *  - regular   — fallback.
 *
 * Cache::flexible() por contact_id. TTL conservador (300s) para no presionar
 * BD; invalidación explícita al mutar notas/tags.
 */
class CrmService
{
    /** Segmentos canónicos. */
    public const SEGMENTS = ['vip', 'recurrent', 'new', 'inactive', 'at_risk', 'regular'];

    private const CACHE_TTL = [300, 1800];

    private const TZ = 'America/Bogota';

    private const RECENT_DAYS = 90;

    private const RECURRENT_DAYS = 60;

    private const NEW_DAYS = 30;

    private const INACTIVE_DAYS = 60;

    private const AT_RISK_MIN_ORDERS = 4;

    private const AT_RISK_CANCEL_RATE = 0.25;

    private const VIP_TOP_N = 10;

    /**
     * Normaliza phone a `57XXXXXXXXXX`: quita '+' y espacios; si tiene 10 dígitos
     * y empieza con `3` (móvil CO), antepone `57`. Idempotente. Permite buscar
     * un Contact existente cuando el cajero tipea con o sin prefijo país.
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '3')) {
            return '57'.$digits;
        }

        return $digits;
    }

    /**
     * Lista clientes (Contacts) paginados con KPIs y segmento.
     *
     * @param  array{search?: ?string, segment?: ?string, tag?: ?string}  $filters
     */
    public function listClients(string $companyNit, array $filters = [], int $page = 1, int $perPage = 25): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $segment = (string) ($filters['segment'] ?? '');
        $tag = trim((string) ($filters['tag'] ?? ''));

        $cacheKey = "crm:list:base:{$companyNit}";

        $clients = Cache::flexible($cacheKey, self::CACHE_TTL, fn (): array => $this->buildClientList($companyNit));

        $clients = $this->applyFilters($clients, $search, $segment, $tag);

        $total = count($clients);
        $items = array_slice($clients, ($page - 1) * $perPage, $perPage);

        return new LengthAwarePaginator(
            items: $items,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ],
        );
    }

    /**
     * Perfil consolidado de un cliente (Contact).
     *
     * Scope escape justificado (#192): un cliente final es único a nivel
     * empresa, no de sede. Para construir el perfil 360 (todas las órdenes y
     * chats independientemente de la sede atendida) se SALTA `BranchScope`.
     *
     * @return ?array<string, mixed>
     */
    public function profile(string $companyNit, string $contactId): ?array
    {
        $cacheKey = "crm:profile:{$companyNit}:contact:{$contactId}";

        return Cache::flexible($cacheKey, [60, 300], function () use ($companyNit, $contactId): ?array {
            $contact = Contact::withoutBranchScope()
                ->where('company_nit', $companyNit)
                ->where('id', $contactId)
                ->first();

            if ($contact === null) {
                return null;
            }

            $row = $this->aggregateForContact($contact);
            $segment = $this->classifySegment($row);

            $orders = Order::withoutBranchScope()
                ->where('company_nit', $companyNit)
                ->where(function ($q) use ($contact): void {
                    $q->where('contact_id', $contact->id);
                    if ($contact->phone !== null && $contact->phone !== '') {
                        $normalized = self::normalizePhone($contact->phone);
                        $alt = str_starts_with($normalized, '57') ? substr($normalized, 2) : '57'.$normalized;
                        $variants = array_values(array_unique(array_filter([$contact->phone, $normalized, $alt])));
                        $q->orWhere(function ($q2) use ($variants): void {
                            $q2->whereNull('contact_id')->whereIn('client_phone', $variants);
                        });
                    }
                })
                ->withCount('orderItems')
                ->orderByDesc('ordered_at')
                ->limit(50)
                ->get(['id', 'branch_id', 'status', 'order_type', 'total', 'discount_amount', 'items', 'ordered_at']);

            $chats = $contact->phone !== null && $contact->phone !== ''
                ? Chat::withoutBranchScope()
                    ->where('company_nit', $companyNit)
                    ->where('client_phone', $contact->phone)
                    ->orderByDesc('last_message_at')
                    ->limit(20)
                    ->get(['id', 'branch_id', 'source', 'status', 'last_message_at'])
                : collect();

            $notes = ClientNote::forContact($companyNit, $contact->id)
                ->with('author:id,name,email')
                ->orderByDesc('created_at')
                ->get();

            $tags = ClientTag::forContact($companyNit, $contact->id)
                ->orderBy('tag')
                ->get();

            return [
                'id' => $contact->id,
                'phone' => $contact->phone,
                'name' => $contact->name,
                'kind' => $contact->effectiveKind(),
                'doc_type' => $contact->doc_type,
                'doc_number' => $contact->doc_number,
                'dv' => $contact->dv,
                'legal_name' => $contact->legal_name,
                'email' => $contact->email,
                'address' => $contact->address,
                'municipality_dane_code' => $contact->municipality_dane_code,
                'fiscal_responsibilities' => $contact->fiscal_responsibilities ?? [],
                'dian_profile_completed_at' => $contact->dian_profile_completed_at?->toIso8601String(),
                'contact_notes' => $contact->notes,
                'segment' => $segment,
                'kpis' => [
                    'total_orders' => $row['total_orders'],
                    'completed_orders' => $row['completed_orders'],
                    'cancelled_orders' => $row['cancelled_orders'],
                    'total_spent' => $row['total_spent'],
                    'average_ticket' => $row['average_ticket'],
                    'first_order_at' => $row['first_order_at'],
                    'last_order_at' => $row['last_order_at'],
                    'orders_last_60d' => $row['orders_last_60d'],
                    'spent_last_90d' => $row['spent_last_90d'],
                    'cancellation_rate' => $row['cancellation_rate'],
                ],
                'orders' => $orders->map(fn (Order $order) => [
                    'id' => $order->id,
                    'status' => $order->status,
                    'order_type' => $order->order_type,
                    'total' => (float) $order->total,
                    'discount_amount' => (float) $order->discount_amount,
                    // Órdenes post-#191 guardan ítems en order_items (relación);
                    // las antiguas usan el JSON. Prefiere order_items_count cuando > 0.
                    'items_count' => $order->order_items_count > 0
                        ? $order->order_items_count
                        : (is_array($order->items) ? count($order->items) : 0),
                    'ordered_at' => $order->ordered_at?->toIso8601String(),
                ])->all(),
                'chats' => $chats->map(fn (Chat $chat) => [
                    'id' => $chat->id,
                    'source' => $chat->source,
                    'status' => $chat->status,
                    'last_message_at' => $chat->last_message_at?->toIso8601String(),
                ])->all(),
                'notes' => $notes->map(fn (ClientNote $note) => [
                    'id' => $note->id,
                    'note' => $note->note,
                    'created_at' => $note->created_at?->toIso8601String(),
                    'author' => $note->author ? [
                        'id' => $note->author->id,
                        'name' => $note->author->name,
                    ] : null,
                ])->all(),
                'tags' => $tags->map(fn (ClientTag $tag) => [
                    'id' => $tag->id,
                    'tag' => $tag->tag,
                ])->all(),
            ];
        });
    }

    /**
     * Lista de tags únicos usados en la empresa (para el dropdown del filtro).
     *
     * @return list<string>
     */
    public function availableTags(string $companyNit): array
    {
        return Cache::flexible("crm:tags:available:{$companyNit}", [300, 1800], fn () => ClientTag::forCompany($companyNit)
            ->select('tag')
            ->distinct()
            ->orderBy('tag')
            ->pluck('tag')
            ->all());
    }

    /**
     * Invalida la caché del CRM para la empresa. Llamado desde el controller
     * tras crear/borrar nota o tag, o crear/actualizar un Contact.
     */
    public function forgetCache(string $companyNit, ?string $contactId = null): void
    {
        Cache::forget("crm:list:base:{$companyNit}");
        Cache::forget("crm:tags:available:{$companyNit}");
        if ($contactId !== null) {
            Cache::forget("crm:profile:{$companyNit}:contact:{$contactId}");
        }
    }

    /**
     * Resuelve un Contact por phone normalizado. Devuelve TODOS los matches
     * (puede haber varios — una familia comparte teléfono).
     *
     * @return Collection<int, Contact>
     */
    public function findContactsByPhone(string $companyNit, string $rawPhone): Collection
    {
        $phone = self::normalizePhone($rawPhone);
        if ($phone === '') {
            return collect();
        }

        $alt = str_starts_with($phone, '57') ? substr($phone, 2) : '57'.$phone;

        return Contact::withoutBranchScope()
            ->where('company_nit', $companyNit)
            ->whereIn('phone', array_unique([$phone, $alt]))
            ->orderByDesc('dian_profile_completed_at')
            ->orderBy('name')
            ->get();
    }

    /**
     * Resuelve un Contact por número de documento (único por empresa).
     */
    public function findContactByDoc(string $companyNit, string $docNumber): ?Contact
    {
        $clean = trim($docNumber);
        if ($clean === '') {
            return null;
        }

        return Contact::withoutBranchScope()
            ->where('company_nit', $companyNit)
            ->where('doc_number', $clean)
            ->first();
    }

    /**
     * Construye el listado base por Contact. KPIs por contact_id con fallback
     * de phone para órdenes legacy.
     *
     * @return list<array<string, mixed>>
     */
    private function buildClientList(string $companyNit): array
    {
        $now = Carbon::now(self::TZ);
        $recentCutoff = $now->copy()->subDays(self::RECENT_DAYS)->toDateTimeString();
        $recurrentCutoff = $now->copy()->subDays(self::RECURRENT_DAYS)->toDateTimeString();

        $contacts = Contact::withoutBranchScope()
            ->where('company_nit', $companyNit)
            ->get([
                'id', 'phone', 'name', 'kind', 'doc_type', 'doc_number', 'legal_name',
                'email', 'dian_profile_completed_at', 'created_at',
            ]);

        if ($contacts->isEmpty()) {
            return [];
        }

        $contactIds = $contacts->pluck('id')->all();

        // Expandir variantes de teléfono (con/sin prefijo 57) para matchear
        // `orders.client_phone` independiente del formato digitado en el POS.
        $allPhoneVariants = [];
        foreach ($contacts->pluck('phone')->filter()->unique() as $rawPhone) {
            $normalized = self::normalizePhone((string) $rawPhone);
            $alt = str_starts_with($normalized, '57') ? substr($normalized, 2) : '57'.$normalized;
            foreach (array_unique(array_filter([$rawPhone, $normalized, $alt])) as $pv) {
                $allPhoneVariants[$pv] = true;
            }
        }
        $phones = array_keys($allPhoneVariants);

        // Agregación SQL: una pasada por contact_id, una pasada por phone
        // (para legacy sin contact_id), después merge en PHP.
        $byContact = collect(DB::select(
            'SELECT
                contact_id,
                COUNT(*)::int                                                AS total_orders,
                COUNT(*) FILTER (WHERE status = ?)::int                      AS completed_orders,
                COUNT(*) FILTER (WHERE status IN (?, ?))::int                AS cancelled_orders,
                COALESCE(SUM(CASE WHEN status = ? THEN total ELSE 0 END), 0) AS total_spent,
                MIN(ordered_at)                                              AS first_order_at,
                MAX(ordered_at)                                              AS last_order_at,
                COUNT(*) FILTER (WHERE ordered_at >= ?::timestamp)::int      AS orders_last_60d,
                COALESCE(SUM(CASE WHEN status = ? AND ordered_at >= ?::timestamp THEN total ELSE 0 END), 0) AS spent_last_90d
            FROM orders
            WHERE company_nit = ?
              AND contact_id = ANY(?)
            GROUP BY contact_id',
            [
                'completed',
                'cancelled', 'refunded',
                'completed',
                $recurrentCutoff,
                'completed', $recentCutoff,
                $companyNit, '{'.implode(',', $contactIds).'}',
            ]
        ))->keyBy('contact_id');

        $byPhone = $phones === [] ? collect() : collect(DB::select(
            'SELECT
                client_phone,
                COUNT(*)::int                                                AS total_orders,
                COUNT(*) FILTER (WHERE status = ?)::int                      AS completed_orders,
                COUNT(*) FILTER (WHERE status IN (?, ?))::int                AS cancelled_orders,
                COALESCE(SUM(CASE WHEN status = ? THEN total ELSE 0 END), 0) AS total_spent,
                MIN(ordered_at)                                              AS first_order_at,
                MAX(ordered_at)                                              AS last_order_at,
                COUNT(*) FILTER (WHERE ordered_at >= ?::timestamp)::int      AS orders_last_60d,
                COALESCE(SUM(CASE WHEN status = ? AND ordered_at >= ?::timestamp THEN total ELSE 0 END), 0) AS spent_last_90d
            FROM orders
            WHERE company_nit = ?
              AND contact_id IS NULL
              AND client_phone = ANY(?)
            GROUP BY client_phone',
            [
                'completed',
                'cancelled', 'refunded',
                'completed',
                $recurrentCutoff,
                'completed', $recentCutoff,
                $companyNit, '{'.implode(',', $phones).'}',
            ]
        ))->keyBy('client_phone');

        $tagsByContact = ClientTag::forCompany($companyNit)
            ->whereIn('contact_id', $contactIds)
            ->get(['contact_id', 'tag'])
            ->groupBy('contact_id')
            ->map(fn (Collection $group): array => $group->pluck('tag')->all());

        $clients = $contacts->map(function (Contact $contact) use ($byContact, $byPhone, $tagsByContact): array {
            $a = $byContact->get($contact->id);
            $b = null;
            if ($contact->phone !== null && $contact->phone !== '') {
                $normalized = self::normalizePhone($contact->phone);
                $alt = str_starts_with($normalized, '57') ? substr($normalized, 2) : '57'.$normalized;
                foreach (array_unique(array_filter([$contact->phone, $normalized, $alt])) as $pv) {
                    $found = $byPhone->get($pv);
                    if ($found !== null) {
                        $b = $found;
                        break;
                    }
                }
            }

            $totalOrders = (int) ($a->total_orders ?? 0) + (int) ($b->total_orders ?? 0);
            $completed = (int) ($a->completed_orders ?? 0) + (int) ($b->completed_orders ?? 0);
            $cancelled = (int) ($a->cancelled_orders ?? 0) + (int) ($b->cancelled_orders ?? 0);
            $totalSpent = (float) ($a->total_spent ?? 0) + (float) ($b->total_spent ?? 0);
            $ordersLast60 = (int) ($a->orders_last_60d ?? 0) + (int) ($b->orders_last_60d ?? 0);
            $spentLast90 = (float) ($a->spent_last_90d ?? 0) + (float) ($b->spent_last_90d ?? 0);

            $firstOrderAt = $this->minDate($a->first_order_at ?? null, $b->first_order_at ?? null)
                ?? optional($contact->created_at)?->toDateTimeString();
            $lastOrderAt = $this->maxDate($a->last_order_at ?? null, $b->last_order_at ?? null);

            return [
                'id' => $contact->id,
                'phone' => $contact->phone,
                'name' => $contact->name ?? $contact->legal_name,
                'kind' => $contact->effectiveKind(),
                'doc_type' => $contact->doc_type,
                'doc_number' => $contact->doc_number,
                'email' => $contact->email,
                'dian_complete' => $contact->dian_profile_completed_at !== null,
                'total_orders' => $totalOrders,
                'completed_orders' => $completed,
                'cancelled_orders' => $cancelled,
                'total_spent' => $totalSpent,
                'average_ticket' => $completed > 0 ? round($totalSpent / $completed, 2) : 0.0,
                'first_order_at' => $firstOrderAt,
                'last_order_at' => $lastOrderAt,
                'orders_last_60d' => $ordersLast60,
                'spent_last_90d' => $spentLast90,
                'cancellation_rate' => $totalOrders > 0 ? round($cancelled / $totalOrders, 4) : 0.0,
                'tags' => $tagsByContact->get($contact->id, []),
            ];
        })->all();

        $spent90 = array_column($clients, 'spent_last_90d');
        rsort($spent90, SORT_NUMERIC);
        $vipThreshold = $spent90[self::VIP_TOP_N - 1] ?? null;
        $vipThreshold = ($vipThreshold !== null && $vipThreshold > 0) ? $vipThreshold : null;

        foreach ($clients as &$client) {
            $client['segment'] = $this->classifySegment($client, $vipThreshold);
        }
        unset($client);

        // Más recientes primero: first_order_at o created_at del contact como
        // proxy de "fecha de alta del cliente en la empresa".
        usort($clients, function (array $a, array $b): int {
            $cmp = strcmp((string) $b['first_order_at'], (string) $a['first_order_at']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) $b['last_order_at'], (string) $a['last_order_at']);
        });

        return $clients;
    }

    /**
     * Agrega KPIs para un Contact específico (usado por profile()).
     *
     * @return array<string, mixed>
     */
    private function aggregateForContact(Contact $contact): array
    {
        $now = Carbon::now(self::TZ);
        $recentCutoff = $now->copy()->subDays(self::RECENT_DAYS)->toDateTimeString();
        $recurrentCutoff = $now->copy()->subDays(self::RECURRENT_DAYS)->toDateTimeString();

        $hasPhone = $contact->phone !== null && $contact->phone !== '';

        // Variantes de teléfono: el POS guarda `client_phone` en el formato que
        // digitó el cajero (10 dígitos sin prefijo o con 57/+57). Para no fallar
        // el match cuando el formato difiere del almacenado en `contacts.phone`,
        // buscamos con todas las variantes (crudo + normalizado + 10-dígitos).
        $phoneVariants = [];
        if ($hasPhone) {
            $normalized = self::normalizePhone((string) $contact->phone);
            $alt = str_starts_with($normalized, '57') ? substr($normalized, 2) : '57'.$normalized;
            $phoneVariants = array_values(array_unique(array_filter([$contact->phone, $normalized, $alt])));
        }

        if ($hasPhone) {
            $inPlaceholders = implode(',', array_fill(0, count($phoneVariants), '?'));
            $matchClause = "(contact_id = ? OR (contact_id IS NULL AND client_phone IN ({$inPlaceholders})))";
        } else {
            $matchClause = 'contact_id = ?';
        }

        $bindings = [
            'completed',
            'cancelled', 'refunded',
            'completed',
            $recurrentCutoff,
            'completed', $recentCutoff,
            $contact->company_nit, $contact->id,
        ];

        foreach ($phoneVariants as $pv) {
            $bindings[] = $pv;
        }

        $rows = DB::select(
            'SELECT
                COUNT(*)::int                                                AS total_orders,
                COUNT(*) FILTER (WHERE status = ?)::int                      AS completed_orders,
                COUNT(*) FILTER (WHERE status IN (?, ?))::int                AS cancelled_orders,
                COALESCE(SUM(CASE WHEN status = ? THEN total ELSE 0 END), 0) AS total_spent,
                MIN(ordered_at)                                              AS first_order_at,
                MAX(ordered_at)                                              AS last_order_at,
                COUNT(*) FILTER (WHERE ordered_at >= ?::timestamp)::int      AS orders_last_60d,
                COALESCE(SUM(CASE WHEN status = ? AND ordered_at >= ?::timestamp THEN total ELSE 0 END), 0) AS spent_last_90d
            FROM orders
            WHERE company_nit = ?
              AND '.$matchClause,
            $bindings
        );

        $row = $rows[0];

        $totalOrders = (int) $row->total_orders;
        $completed = (int) $row->completed_orders;
        $cancelled = (int) $row->cancelled_orders;
        $totalSpent = (float) $row->total_spent;

        return [
            'total_orders' => $totalOrders,
            'completed_orders' => $completed,
            'cancelled_orders' => $cancelled,
            'total_spent' => $totalSpent,
            'average_ticket' => $completed > 0 ? round($totalSpent / $completed, 2) : 0.0,
            'first_order_at' => $row->first_order_at,
            'last_order_at' => $row->last_order_at,
            'orders_last_60d' => (int) $row->orders_last_60d,
            'spent_last_90d' => (float) $row->spent_last_90d,
            'cancellation_rate' => $totalOrders > 0 ? round($cancelled / $totalOrders, 4) : 0.0,
        ];
    }

    /**
     * Aplica filtros sobre el listado ya construido en memoria.
     *
     * @param  list<array<string, mixed>>  $clients
     * @return list<array<string, mixed>>
     */
    private function applyFilters(array $clients, string $search, string $segment, string $tag): array
    {
        if ($search !== '') {
            $needle = strtolower($search);
            $clients = array_values(array_filter($clients, function (array $c) use ($needle): bool {
                $name = strtolower((string) ($c['name'] ?? ''));
                $phone = strtolower((string) ($c['phone'] ?? ''));
                $doc = strtolower((string) ($c['doc_number'] ?? ''));

                return str_contains($name, $needle)
                    || str_contains($phone, $needle)
                    || str_contains($doc, $needle);
            }));
        }

        if ($segment !== '' && in_array($segment, self::SEGMENTS, true)) {
            $clients = array_values(array_filter(
                $clients,
                fn (array $c): bool => $c['segment'] === $segment
            ));
        }

        if ($tag !== '') {
            $clients = array_values(array_filter(
                $clients,
                fn (array $c): bool => in_array($tag, $c['tags'] ?? [], true)
            ));
        }

        return $clients;
    }

    /**
     * Clasifica un cliente en un segmento canónico según sus KPIs.
     *
     * @param  array<string, mixed>  $client
     */
    private function classifySegment(array $client, ?float $vipThreshold = null): string
    {
        $now = Carbon::now(self::TZ);
        $totalOrders = (int) ($client['total_orders'] ?? 0);

        if ($totalOrders === 0) {
            return 'new';
        }

        $firstOrder = $client['first_order_at'] !== null ? Carbon::parse((string) $client['first_order_at'], self::TZ) : null;
        $lastOrder = $client['last_order_at'] !== null ? Carbon::parse((string) $client['last_order_at'], self::TZ) : null;
        $spentLast90 = (float) ($client['spent_last_90d'] ?? 0);
        $cancellationRate = (float) ($client['cancellation_rate'] ?? 0);
        $ordersLast60 = (int) ($client['orders_last_60d'] ?? 0);

        if ($vipThreshold !== null && $spentLast90 >= $vipThreshold) {
            return 'vip';
        }

        if ($totalOrders >= self::AT_RISK_MIN_ORDERS && $cancellationRate > self::AT_RISK_CANCEL_RATE) {
            return 'at_risk';
        }

        if ($lastOrder !== null && $lastOrder->diffInDays($now) > self::INACTIVE_DAYS) {
            return 'inactive';
        }

        if ($firstOrder !== null && $firstOrder->diffInDays($now) <= self::NEW_DAYS && $totalOrders <= 2) {
            return 'new';
        }

        if ($ordersLast60 >= 3) {
            return 'recurrent';
        }

        return 'regular';
    }

    private function minDate(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return strcmp($a, $b) < 0 ? $a : $b;
    }

    private function maxDate(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return strcmp($a, $b) > 0 ? $a : $b;
    }
}
