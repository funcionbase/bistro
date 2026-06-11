<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Dian;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dian\UpdateContactDianProfileRequest;
use App\Models\Contact;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lookup y completado del perfil fiscal DIAN de un `Contact`.
 *
 * - GET /dian/recipients/lookup?phone=... | ?doc=...
 *   - Por `phone`: retorna ARRAY de Contacts (varios miembros de una familia
 *     pueden compartir número). La UI muestra selector si hay >1.
 *   - Por `doc`: retorna Contact único (UNIQUE parcial por empresa) o null.
 * - PUT /dian/recipients/{contact}: completa los campos faltantes del
 *   perfil fiscal y setea `dian_profile_completed_at`.
 */
class DianRecipientsController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function lookup(Request $request): JsonResponse
    {
        // Acepta cualquiera de los dos identificadores, al menos uno requerido.
        $request->validate([
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{7,20}$/'],
            'doc' => ['nullable', 'string', 'max:30', 'regex:/^[A-Z0-9-]+$/i'],
        ]);

        if (empty($request->input('phone')) && empty($request->input('doc'))) {
            abort(422, 'Debe enviar phone o doc.');
        }

        $nit = (string) $request->attributes->get('active_company_nit');

        // Lookup por doc → único por empresa, retorna un solo Contact.
        if ($doc = trim((string) $request->input('doc'))) {
            $contact = Contact::query()
                ->where('company_nit', $nit)
                ->where('doc_number', $doc)
                ->first();

            return response()->json([
                'data' => $contact ? [$this->serialize($contact)] : [],
                'match_type' => 'doc',
            ]);
        }

        // Lookup por phone → puede retornar múltiples Contacts (familia).
        $phone = preg_replace('/[^\d+]/', '', (string) $request->string('phone')) ?? '';

        $contacts = Contact::query()
            ->where('company_nit', $nit)
            ->where('phone', $phone)
            ->orderByDesc('dian_profile_completed_at')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $contacts->map(fn (Contact $c) => $this->serialize($c))->all(),
            'match_type' => 'phone',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Contact $contact): array
    {
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
            'dian_complete' => $contact->hasCompleteDianProfile(),
        ];
    }

    public function update(UpdateContactDianProfileRequest $request, Contact $contact): JsonResponse
    {
        $nit = (string) $request->attributes->get('active_company_nit');
        abort_unless($contact->company_nit === $nit, 404);

        $payload = $request->validated();
        $contact->fill($payload)->dian_profile_completed_at = now();
        $contact->save();

        $this->audit->log('dian.recipient.profile_completed', null, $contact, [
            'contact_id' => $contact->id,
            'phone' => $contact->phone,
        ]);

        return response()->json([
            'data' => [
                'id' => $contact->id,
                'dian_complete' => true,
            ],
        ]);
    }
}
