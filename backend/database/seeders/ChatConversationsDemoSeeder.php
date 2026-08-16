<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Contact;
use App\Models\Order;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pobla 5 conversaciones de WhatsApp con sus ordenes asociadas para QA del
 * panel de chats. Cada conversacion termina con un Order en
 * un estado distinto del lifecycle: successful (validada/entregada), cancelled
 * (rechazada), in_delivery (en envio), in_kitchen (en cocina) y pending
 * (en proceso, antes de cocina). Mientras n8n no este disponible, todas las
 * conversaciones quedan etiquetadas como `source='whatsapp'` (default del
 * helper); historicamente este seeder mezclaba Instagram/Facebook para probar
 * los colores del ChatSourceBadge — eso quedo desactivado al alinearse con el
 * MVP de WhatsApp Cloud API.
 *
 * Es idempotente: borra los chats demo previos por el rango de telefonos
 * antes de re-insertar, asi se puede correr varias veces sin duplicar.
 */
class ChatConversationsDemoSeeder extends Seeder
{
    /** Telefonos reservados para los chats demo. Convención: 10 dígitos sin prefijo país. */
    private const DEMO_PHONES = [
        '3009990001',
        '3009990002',
        '3009990003',
        '3009990004',
        '3009990005',
    ];

    public function run(): void
    {
        $company = Company::query()->first();
        if ($company === null) {
            $this->command?->warn('[ChatConversationsDemoSeeder] No hay empresas: corre RestauranteFlexySeeder antes.');

            return;
        }

        $branch = Branch::query()->where('company_nit', $company->nit)->orderByDesc('is_default')->first();
        if ($branch === null) {
            $this->command?->warn('[ChatConversationsDemoSeeder] No hay sedes: corre RestauranteFlexySeeder antes.');

            return;
        }

        BelongsToBranch::setSeederBranch($branch->id);

        try {
            DB::transaction(function () use ($company): void {
                $this->cleanup($company->nit);

                $now = Carbon::now();

                $this->seedConversation(
                    companyNit: $company->nit,
                    phone: self::DEMO_PHONES[0],
                    contactName: 'Laura Restrepo',
                    orderStatus: 'completed',
                    orderType: 'delivery',
                    items: [
                        ['id' => 'salchipapa-especial', 'name' => 'Salchipapa especial', 'price' => 16000, 'quantity' => 1, 'category' => 'Salchipapas'],
                        ['id' => 'gaseosa-personal', 'name' => 'Gaseosa personal', 'price' => 4500, 'quantity' => 1, 'category' => 'Bebidas'],
                    ],
                    total: 20500,
                    cost: 8000,
                    deliveryAddress: 'Cra 70 #45-12, Laureles',
                    orderedAt: $now->copy()->subDays(2)->setTime(20, 15),
                    botPaused: false,
                    handoff: false,
                    messages: [
                        ['client', 'Hola! ¿Tienen salchipapa especial para domicilio?', 75],
                        ['bot', 'Hola Laura! Salchipapa especial $16.000 (papa, salchicha, carne desmechada y queso). ¿Algo de tomar?', 73],
                        ['client', 'Una gaseosa personal porfa', 70],
                        ['bot', 'Listo. Total $20.500. ¿Domicilio o recoges?', 65],
                        ['client', 'Domicilio a Cra 70 #45-12, Laureles', 60],
                        ['bot', 'Perfecto, en 35 min llega. Pedido #demo-1 confirmado.', 55],
                        ['client', 'Llegó calientita, gracias!', 5],
                    ],
                );

                $this->seedConversation(
                    companyNit: $company->nit,
                    phone: self::DEMO_PHONES[1],
                    contactName: 'Andres Quintero',
                    orderStatus: 'cancelled',
                    orderType: 'delivery',
                    items: [
                        ['id' => 'mega-salchipapa', 'name' => 'Mega salchipapa para 2', 'price' => 32000, 'quantity' => 2, 'category' => 'Salchipapas'],
                    ],
                    total: 64000,
                    cost: 27600,
                    deliveryAddress: 'Calle 10 #43-87, El Poblado',
                    orderedAt: $now->copy()->subHours(20),
                    botPaused: true,
                    handoff: true,
                    handoffReason: 'cliente solicita cambio de direccion fuera de cobertura',
                    messages: [
                        ['client', 'Quiero 2 mega salchipapas', 90],
                        ['bot', 'Listo, $64.000. ¿Dirección?', 88],
                        ['client', 'Calle 10 #43-87, El Poblado', 85],
                        ['bot', 'Esa zona está fuera de nuestra cobertura, te paso un humano…', 84],
                        ['operator', 'Hola Andrés, lamentamos lo de la cobertura. ¿Quieres recoger en el local?', 60],
                        ['client', 'No puedo ir, mejor cancela', 50],
                        ['operator', 'Entendido, pedido cancelado. ¡Esperamos verte pronto!', 45],
                    ],
                );

                $this->seedConversation(
                    companyNit: $company->nit,
                    phone: self::DEMO_PHONES[2],
                    contactName: 'Maria Camila Diaz',
                    orderStatus: 'in_transit',
                    orderType: 'delivery',
                    items: [
                        ['id' => 'salchipapa-superpapa', 'name' => 'Salchipapa SuperPapa', 'price' => 24900, 'quantity' => 1, 'category' => 'Salchipapas'],
                        ['id' => 'gaseosa-personal', 'name' => 'Gaseosa personal', 'price' => 4500, 'quantity' => 2, 'category' => 'Bebidas'],
                    ],
                    total: 33900,
                    cost: 13500,
                    deliveryAddress: 'Cl 33 #65-22, Pinares, Pereira',
                    orderedAt: $now->copy()->subMinutes(40),
                    botPaused: false,
                    handoff: false,
                    messages: [
                        ['client', 'Buenas, ¿qué tiene la SuperPapa?', 50],
                        ['bot', 'La SuperPapa lleva 3 salchichas, carne desmechada, pollo, chorizo, queso y maíz tierno. $24.900', 49],
                        ['client', 'Esa y dos gaseosas', 47],
                        ['bot', 'Total $33.900. ¿Dirección?', 45],
                        ['client', 'Cl 33 #65-22, Pinares', 43],
                        ['bot', 'Confirmado. Tu pedido salió a domicilio.', 8],
                        ['client', '¿Cuánto falta?', 3],
                        ['bot', 'El repartidor llega en ~10 min.', 2],
                    ],
                );

                $this->seedConversation(
                    companyNit: $company->nit,
                    phone: self::DEMO_PHONES[3],
                    contactName: 'Sebastian Gomez',
                    orderStatus: 'in_kitchen',
                    orderType: 'pickup',
                    items: [
                        ['id' => 'perro-especial', 'name' => 'Perro especial', 'price' => 14500, 'quantity' => 2, 'category' => 'Perros'],
                        ['id' => 'cerveza-nacional', 'name' => 'Cerveza nacional', 'price' => 7000, 'quantity' => 2, 'category' => 'Bebidas'],
                    ],
                    total: 43000,
                    cost: 19000,
                    deliveryAddress: null,
                    orderedAt: $now->copy()->subMinutes(15),
                    botPaused: false,
                    handoff: false,
                    messages: [
                        ['client', 'Hola, paso a recoger en 30 min', 25],
                        ['bot', 'Hola Sebastián, ¿qué vas a pedir?', 24],
                        ['client', 'Dos perros especiales y dos cervezas', 20],
                        ['bot', 'Listo, $43.000. Recoges a las '.$now->copy()->addMinutes(20)->format('H:i').'.', 18],
                        ['client', 'Perfecto, voy en camino', 12],
                        ['bot', 'En cocina ya están preparando tu pedido.', 5],
                    ],
                );

                $this->seedConversation(
                    companyNit: $company->nit,
                    phone: self::DEMO_PHONES[4],
                    contactName: 'Valentina Ortiz',
                    orderStatus: 'pending',
                    orderType: 'table',
                    tableNumber: '7',
                    items: [
                        ['id' => 'salchipapa-clasica', 'name' => 'Salchipapa clásica', 'price' => 12000, 'quantity' => 2, 'category' => 'Salchipapas'],
                        ['id' => 'papas-francesas', 'name' => 'Papas a la francesa', 'price' => 8500, 'quantity' => 1, 'category' => 'Acompañamientos'],
                        ['id' => 'gaseosa-personal', 'name' => 'Gaseosa personal', 'price' => 4500, 'quantity' => 2, 'category' => 'Bebidas'],
                    ],
                    total: 41500,
                    cost: 17000,
                    deliveryAddress: null,
                    orderedAt: $now->copy()->subMinutes(5),
                    botPaused: false,
                    handoff: false,
                    messages: [
                        ['client', 'Estoy en la mesa 7', 7],
                        ['bot', 'Hola Valentina! ¿Qué pides para la mesa 7?', 6],
                        ['client', 'Dos salchipapas clásicas, papas y dos gaseosas', 4],
                        ['bot', 'Confirmado: 2 salchipapas + papas + 2 gaseosas. Total $41.500. Cocina recibiendo el pedido.', 2],
                    ],
                );
            });
        } finally {
            BelongsToBranch::setSeederBranch(null);
        }

        $this->command?->info('[ChatConversationsDemoSeeder] 5 chats demo creados con ordenes asociadas.');
    }

    private function cleanup(string $companyNit): void
    {
        $chats = Chat::query()
            ->where('company_nit', $companyNit)
            ->whereIn('client_phone', self::DEMO_PHONES)
            ->get();

        foreach ($chats as $chat) {
            ChatMessage::where('chat_id', $chat->id)->delete();
        }
        Chat::query()
            ->where('company_nit', $companyNit)
            ->whereIn('client_phone', self::DEMO_PHONES)
            ->delete();

        Order::query()
            ->where('company_nit', $companyNit)
            ->whereIn('session_id', $this->sessionIds())
            ->delete();

        Contact::query()
            ->where('company_nit', $companyNit)
            ->whereIn('phone', self::DEMO_PHONES)
            ->delete();
    }

    /** @return array<int, string> */
    private function sessionIds(): array
    {
        return array_map(fn (string $phone): string => 'demo-chat-'.$phone, self::DEMO_PHONES);
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: int}>  $messages  Tuplas [sender, body, minutos_atras]
     * @param  array<int, array<string, mixed>>  $items
     */
    private function seedConversation(
        string $companyNit,
        string $phone,
        string $contactName,
        string $orderStatus,
        string $orderType,
        array $items,
        int $total,
        int $cost,
        ?string $deliveryAddress,
        Carbon $orderedAt,
        bool $botPaused,
        bool $handoff,
        array $messages,
        ?string $handoffReason = null,
        ?string $tableNumber = null,
        string $source = 'whatsapp',
    ): void {
        $contact = Contact::create([
            'company_nit' => $companyNit,
            'phone' => $phone,
            'name' => $contactName,
            'notes' => null,
        ]);

        $sortedMessages = collect($messages)->sortByDesc(fn (array $m): int => $m[2])->values();
        $lastMessageAt = (clone $orderedAt)->subMinutes((int) $sortedMessages->last()[2]);

        $chat = Chat::create([
            'company_nit' => $companyNit,
            'client_phone' => $phone,
            'client_name' => $contactName,
            'contact_id' => $contact->id,
            'status' => 'open',
            'source' => $source,
            'bot_paused' => $botPaused,
            'handoff_requested_at' => $handoff ? $orderedAt->copy()->subMinutes(2) : null,
            'handoff_reason' => $handoff ? $handoffReason : null,
            'last_message_at' => $lastMessageAt,
            'meta_synced_at' => Carbon::now(),
        ]);

        foreach ($sortedMessages as $i => [$sender, $body, $minutesAgo]) {
            ChatMessage::create([
                'chat_id' => $chat->id,
                'sender' => $sender,
                'status' => $sender === 'operator' ? 'sent' : null,
                'body' => $body,
                'meta_message_id' => $sender === 'client' ? 'wamid.demo-'.$phone.'-'.$i : null,
                'sent_at' => Carbon::now()->subMinutes($minutesAgo),
            ]);
        }

        Order::create([
            'company_nit' => $companyNit,
            'session_id' => 'demo-chat-'.$phone,
            'client_phone' => $phone,
            'items' => $items,
            'status' => $orderStatus,
            'order_type' => $orderType,
            'table_number' => $tableNumber,
            'delivery_address' => $deliveryAddress,
            'total' => $total,
            'subtotal' => $total, // Régimen simple: sin desglose tributario
            'tax_amount' => 0,
            'tax_rate' => 0,
            'tax_regime' => 'simple',
            'tax_included_in_price' => true,
            'tip_amount' => 0,
            'cost' => $cost,
            'coupon_code' => null,
            'discount_amount' => 0,
            'ordered_at' => $orderedAt,
        ]);
    }
}
