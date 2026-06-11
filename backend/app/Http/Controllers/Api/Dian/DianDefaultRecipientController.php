<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Dian;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dian\UpdateDianDefaultRecipientRequest;
use App\Http\Resources\Dian\DianDefaultRecipientResource;
use App\Models\DianDefaultRecipient;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cliente DIAN por defecto de la empresa.
 *
 * Owner-only via permission middleware. Si no existe, GET retorna 200 + null
 * para que la UI muestre banner "Se usará el consumidor final DIAN estándar".
 */
class DianDefaultRecipientController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $nit = (string) $request->attributes->get('active_company_nit');
        $recipient = DianDefaultRecipient::query()->where('company_nit', $nit)->first();

        return response()->json([
            'data' => $recipient ? DianDefaultRecipientResource::make($recipient) : null,
        ]);
    }

    public function update(UpdateDianDefaultRecipientRequest $request): JsonResponse
    {
        $nit = (string) $request->attributes->get('active_company_nit');
        $payload = $request->validated();

        $recipient = DianDefaultRecipient::query()->updateOrCreate(
            ['company_nit' => $nit],
            $payload,
        );

        $this->audit->log('dian.default_recipient.updated', null, $recipient, [
            'doc_type' => $recipient->doc_type,
            'doc_number' => $recipient->doc_number,
        ]);

        return response()->json(['data' => DianDefaultRecipientResource::make($recipient)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $nit = (string) $request->attributes->get('active_company_nit');
        $recipient = DianDefaultRecipient::query()->where('company_nit', $nit)->first();

        if ($recipient !== null) {
            $this->audit->log('dian.default_recipient.removed', null, $recipient);
            $recipient->delete();
        }

        return response()->json([], 204);
    }
}
