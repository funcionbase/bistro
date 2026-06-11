<?php

declare(strict_types=1);

namespace App\Services\Dian;

use App\Models\Contact;
use App\Models\DianDefaultRecipient;
use App\Models\Order;
use App\Services\CompanySettingsService;
use App\Services\Dian\DTOs\RecipientDto;
use App\Services\Dian\Exceptions\NeedsRecipientDataException;

/**
 * Resuelve el adquirente del documento aplicando la cascada §5.3 del
 * refinamiento (refactor #235 agrega prioridad 1.5):
 *
 *   1. billing_* snapshot ya capturado en la `Order` (modal del cajero) →
 *      el cajero capturó datos explícitos: ganan siempre.
 *   1.5. `Order::contact_id` (FK) → el cajero identificó al cliente desde POS.
 *      Es la fuente más confiable cuando varios contactos comparten phone.
 *   2. Contact lookup por phone (perfil DIAN completo) — si
 *      `dian.lookup_by_phone_enabled = true` y la orden tiene `client_phone`.
 *      Si hay MÚLTIPLES contactos con ese phone, no adivinamos: cae al
 *      default (la UI debió pedir al cajero que eligiera).
 *      Si encuentra perfil incompleto → status `needs_recipient_data`.
 *   3. `dian_default_recipients[company_nit]` — cuando aplica al modo
 *      `auto_emit_only=true` o cuando la marca es `false`.
 *   4. `config('dian.default_final_consumer')` — CONSUMIDOR FINAL 222222222222.
 *
 * El resolver NO emite. Solo arma el `RecipientDto` o lanza
 * `NeedsRecipientDataException` cuando hay match parcial. El caller
 * (`DianDispatchService`) decide qué hacer con la excepción.
 */
class RecipientResolver
{
    public function resolveFromOrder(Order $order, bool $isAutoEmit = false): RecipientDto
    {
        $companyNit = (string) $order->company_nit;

        if ($this->hasOrderSnapshot($order)) {
            return $this->fromOrderSnapshot($order);
        }

        if ($order->contact_id !== null) {
            $contact = Contact::query()
                ->where('company_nit', $companyNit)
                ->where('id', $order->contact_id)
                ->first();

            if ($contact !== null) {
                if ($contact->hasCompleteDianProfile()) {
                    return $this->fromContact($contact);
                }

                throw new NeedsRecipientDataException($contact);
            }
        }

        $lookupEnabled = $this->lookupEnabled($companyNit);

        if ($lookupEnabled && filled($order->client_phone)) {
            $matches = Contact::query()
                ->where('company_nit', $companyNit)
                ->where('phone', $order->client_phone)
                ->limit(2)
                ->get();

            if ($matches->count() === 1) {
                $contact = $matches->first();
                if ($contact->hasCompleteDianProfile()) {
                    return $this->fromContact($contact);
                }

                throw new NeedsRecipientDataException($contact);
            }

            // 0 matches → continúa al default. >1 matches → ambiguo:
            // no adivinamos cuál era. Cae al default; si el cajero quería FEV
            // a un cliente específico debió elegirlo en el modal antes.
        }

        $default = DianDefaultRecipient::query()->where('company_nit', $companyNit)->first();

        if ($default !== null) {
            $applies = ! $isAutoEmit || $default->applies_to_auto_emit_only;
            if ($applies) {
                return $this->fromDefault($default);
            }
        }

        return $this->genericFinalConsumer();
    }

    private function hasOrderSnapshot(Order $order): bool
    {
        return filled($order->billing_doc_number) && filled($order->billing_legal_name);
    }

    private function fromOrderSnapshot(Order $order): RecipientDto
    {
        return new RecipientDto(
            docType: (string) $order->billing_doc_type,
            docNumber: (string) $order->billing_doc_number,
            dv: $order->billing_dv,
            legalName: (string) $order->billing_legal_name,
            email: $order->billing_email,
            address: $order->billing_address,
            municipalityCode: $order->billing_municipality_code,
            fiscalResponsibilities: [],
            recipientType: $order->billing_recipient_type ?? 'person',
        );
    }

    private function fromContact(Contact $contact): RecipientDto
    {
        return new RecipientDto(
            docType: (string) $contact->doc_type,
            docNumber: (string) $contact->doc_number,
            dv: $contact->dv,
            legalName: (string) $contact->legal_name,
            email: $contact->email,
            address: $contact->address,
            municipalityCode: $contact->municipality_dane_code,
            fiscalResponsibilities: $contact->fiscal_responsibilities ?? [],
            recipientType: $contact->effectiveKind() === Contact::KIND_COMPANY ? 'company' : 'person',
        );
    }

    private function fromDefault(DianDefaultRecipient $default): RecipientDto
    {
        return new RecipientDto(
            docType: $default->doc_type,
            docNumber: $default->doc_number,
            dv: $default->dv,
            legalName: $default->legal_name,
            email: $default->email,
            address: $default->address,
            municipalityCode: $default->municipality_dane_code,
            fiscalResponsibilities: $default->fiscal_responsibilities ?? [],
            recipientType: $default->doc_type === 'NIT' ? 'company' : 'person',
        );
    }

    private function genericFinalConsumer(): RecipientDto
    {
        $cfg = (array) config('dian.default_final_consumer');

        return new RecipientDto(
            docType: (string) ($cfg['doc_type'] ?? 'CC'),
            docNumber: (string) ($cfg['doc_number'] ?? '222222222222'),
            dv: $cfg['dv'] ?? null,
            legalName: (string) ($cfg['legal_name'] ?? 'CONSUMIDOR FINAL'),
            email: $cfg['email'] ?? null,
            address: $cfg['address'] ?? null,
            municipalityCode: $cfg['municipality_dane_code'] ?? null,
            fiscalResponsibilities: (array) ($cfg['fiscal_responsibilities'] ?? ['R-99-PN']),
            recipientType: (string) ($cfg['recipient_type'] ?? 'final_consumer'),
        );
    }

    private function lookupEnabled(string $companyNit): bool
    {
        $service = app(CompanySettingsService::class);

        return (bool) $service->get($companyNit, 'dian.lookup_by_phone_enabled', true);
    }
}
