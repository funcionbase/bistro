<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Dian;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dian\EmitDocumentRequest;
use App\Http\Resources\Dian\ElectronicDocumentResource;
use App\Jobs\PrintDianReceiptJob;
use App\Models\Branch;
use App\Models\ElectronicDocument;
use App\Models\Order;
use App\Models\Printer;
use App\Services\AuditService;
use App\Services\Dian\DianDispatchService;
use App\Services\Dian\Exceptions\DianEmissionDisabledException;
use App\Services\Dian\Exceptions\PlanFeatureNotIncludedException;
use App\Services\Dian\Exceptions\ResolutionExhaustedException;
use App\Services\Dian\Exceptions\ResolutionNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Endpoints operativos sobre `electronic_documents`.
 *
 * - GET /dian/documents: listado paginado con filtros.
 * - GET /dian/documents/{id}: detalle.
 * - GET /dian/documents/{id}/xml|pdf: descarga vía URL firmada S3.
 * - POST /dian/documents: emit idempotente (Idempotency-Key + Cache::lock,
 *   add-on §3). Si la orden ya tiene un documento en estado activo del
 *   mismo type, lo retorna sin reemitir (200). Si no, emite (201).
 * - POST /dian/documents/{id}/retry: retry de error/rejected.
 * - POST /dian/documents/{id}/credit-note: emite NC referenciando el doc.
 * - POST /dian/documents/{id}/print: encola PrintDianReceiptJob.
 * - POST /dian/documents/{id}/convert-to-fev: emite FEV nueva referenciando
 *   el DEE POS previo (el original queda como NC implícita).
 */
class ElectronicDocumentController extends Controller
{
    public function __construct(
        private readonly DianDispatchService $dispatch,
        private readonly AuditService $audit,
    ) {}

    /** Columnas ordenables desde el listado — whitelist contra inyección en orderBy. */
    private const SORTABLE_COLUMNS = ['issued_at', 'full_number', 'consecutive', 'status', 'document_type', 'created_at'];

    public function index(Request $request): JsonResponse
    {
        $nit = (string) $request->attributes->get('active_company_nit');
        $branchId = $request->attributes->get('active_branch_id');

        $query = ElectronicDocument::query()->forCompany($nit);

        // Selección de sede (consulta desde /company/dian):
        //  - `branch=all` → toda la empresa (escape preexistente).
        //  - `branch=<uuid>` → esa sede, validando que pertenezca a la empresa
        //    activa (si no pertenece, el filtro no matchea nada — no filtra
        //    por la sede activa para no filtrar datos de otra empresa).
        //  - ausente → sede activa del JWT (comportamiento histórico).
        $branchParam = $request->string('branch')->trim()->toString();
        if ($branchParam !== '' && $branchParam !== 'all') {
            $query->where('branch_id', $branchParam)
                ->whereIn('branch_id', Branch::query()->where('company_nit', $nit)->select('id'));
        } elseif ($branchId && $branchParam !== 'all') {
            $query->where('branch_id', $branchId);
        }

        if ($status = $request->string('status')->trim()->toString()) {
            $query->where('status', $status);
        }
        if ($type = $request->string('document_type')->trim()->toString()) {
            $query->where('document_type', $type);
        }
        if ($resolutionId = $request->string('resolution_id')->trim()->toString()) {
            $query->where('dian_resolution_id', $resolutionId);
        }
        if ($from = $request->date('from')) {
            $query->where('issued_at', '>=', $from);
        }
        if ($to = $request->date('to')) {
            $query->where('issued_at', '<=', $to);
        }
        if ($orderId = $request->string('order_id')->toString()) {
            $query->where('order_id', $orderId);
        }

        // Búsqueda server-side: número completo, CUFE/CUDE o track ID del provider.
        if ($q = $request->string('q')->trim()->toString()) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $query->where(function ($sub) use ($like): void {
                $sub->where('full_number', 'ilike', $like)
                    ->orWhere('unique_code', 'ilike', $like)
                    ->orWhere('provider_track_id', 'ilike', $like);
            });
        }

        $sort = $request->string('sort')->trim()->toString();
        if (! in_array($sort, self::SORTABLE_COLUMNS, true)) {
            $sort = 'issued_at';
        }
        $dir = strtolower($request->string('dir')->trim()->toString()) === 'asc' ? 'asc' : 'desc';

        $page = $query->orderBy($sort, $dir)
            ->orderByDesc('consecutive')
            ->paginate(min(100, max(10, $request->integer('per_page', 25))));

        return ElectronicDocumentResource::collection($page)->response();
    }

    public function show(Request $request, ElectronicDocument $document): JsonResponse
    {
        $this->ensureSameCompany($request, $document);

        return response()->json(['data' => ElectronicDocumentResource::make($document)]);
    }

    public function xml(Request $request, ElectronicDocument $document): JsonResponse
    {
        $this->ensureSameCompany($request, $document);

        if (! $document->xml_path) {
            return response()->json([
                'message' => 'Este documento no tiene XML disponible. Reemitilo desde la orden para generarlo.',
                'code' => 'DIAN_BLOB_NOT_AVAILABLE',
            ], 404);
        }

        $url = Storage::disk((string) config('dian.storage_disk', 's3'))
            ->temporaryUrl($document->xml_path, now()->addMinutes(15));

        return response()->json(['url' => $url, 'ttl_seconds' => 900]);
    }

    public function pdf(Request $request, ElectronicDocument $document): JsonResponse
    {
        $this->ensureSameCompany($request, $document);

        if (! $document->pdf_path) {
            // Caso típico: documentos sembrados por DianFlowsSeeder no tienen
            // PDF físico en S3 (CUFE/CUDE real pero blob NULL para evitar
            // depender de MinIO/S3 en dev). UX clara en lugar de 404 genérico.
            return response()->json([
                'message' => 'Este documento no tiene PDF disponible. Reemitilo desde la orden para generarlo.',
                'code' => 'DIAN_BLOB_NOT_AVAILABLE',
            ], 404);
        }

        $url = Storage::disk((string) config('dian.storage_disk', 's3'))
            ->temporaryUrl($document->pdf_path, now()->addMinutes(15));

        return response()->json(['url' => $url, 'ttl_seconds' => 900]);
    }

    public function store(EmitDocumentRequest $request): JsonResponse
    {
        $nit = (string) $request->attributes->get('active_company_nit');
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        if ($idempotencyKey === '') {
            throw ValidationException::withMessages([
                'Idempotency-Key' => 'Header Idempotency-Key requerido.',
            ]);
        }

        $payload = $request->validated();
        $order = Order::query()
            ->where('id', $payload['order_id'])
            ->where('company_nit', $nit)
            ->firstOrFail();

        // Guard de estado: solo emitimos sobre órdenes en estado terminal
        // de éxito (config/orders.revenue). Bloqueamos emisión sobre órdenes
        // pendientes, canceladas, en tránsito, etc. — el cajero debe cerrar
        // primero. Defensa: el backend NO emite por pedido del cliente sobre
        // órdenes no listas; el cajero pulsa "Emitir" desde una orden cerrada.
        $revenueStatuses = (array) config('orders.revenue', ['completed']);
        if (! in_array($order->status, $revenueStatuses, true)) {
            return response()->json([
                'error' => 'dian.order_not_emittable',
                'message' => sprintf(
                    'La orden #%d está en estado "%s" y no es facturable. Solo se emite sobre órdenes %s.',
                    $order->id,
                    $order->status,
                    implode('/', $revenueStatuses),
                ),
            ], 422);
        }

        // Dedup explícito: si ya existe un documento activo para
        // (order_id, document_type), lo retornamos sin reemitir.
        $existing = ElectronicDocument::query()
            ->where('order_id', $order->id)
            ->where('document_type', $payload['document_type'])
            ->whereIn('status', ['accepted', 'sent', 'queued', 'pending'])
            ->first();

        if ($existing !== null) {
            return response()->json(['data' => ElectronicDocumentResource::make($existing)]);
        }

        $lockKey = "dian:emit:{$order->id}:{$payload['document_type']}";
        $ttl = (int) config('dian.emit_lock_ttl_seconds', 30);

        try {
            $document = Cache::lock($lockKey, $ttl)->block(5, function () use ($order, $payload) {
                // Doble-check dentro del lock (otro request pudo crear).
                $existing = ElectronicDocument::query()
                    ->where('order_id', $order->id)
                    ->where('document_type', $payload['document_type'])
                    ->whereIn('status', ['accepted', 'sent', 'queued', 'pending'])
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }

                return $this->dispatch->emit($order, $payload);
            });
        } catch (ResolutionNotFoundException|ResolutionExhaustedException $exception) {
            return response()->json([
                'error' => 'dian.resolution_unavailable',
                'message' => $exception->getMessage(),
            ], 422);
        } catch (DianEmissionDisabledException $exception) {
            return response()->json([
                'error' => 'dian.emission_disabled',
                'message' => $exception->getMessage(),
            ], 503);
        } catch (PlanFeatureNotIncludedException $exception) {
            return response()->json([
                'error' => 'plan.feature_not_included',
                'message' => $exception->getMessage(),
            ], 403);
        }

        if (! empty($payload['force_print'])) {
            $printerId = $payload['printer_id'] ?? null;
            PrintDianReceiptJob::dispatch($document->id, $printerId, false);
        }

        $status = $document->wasRecentlyCreated ? 201 : 200;

        return response()->json(['data' => ElectronicDocumentResource::make($document)], $status);
    }

    public function retry(Request $request, ElectronicDocument $document): JsonResponse
    {
        $this->ensureSameCompany($request, $document);

        try {
            $document = $this->dispatch->retry($document);
        } catch (DianEmissionDisabledException $e) {
            return response()->json(['error' => 'dian.emission_disabled', 'message' => $e->getMessage()], 503);
        } catch (PlanFeatureNotIncludedException $e) {
            return response()->json(['error' => 'plan.feature_not_included', 'message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'dian.retry_failed', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ElectronicDocumentResource::make($document)]);
    }

    public function creditNote(Request $request, ElectronicDocument $document): JsonResponse
    {
        $this->ensureSameCompany($request, $document);
        abort_unless($document->isAccepted(), 422, 'Solo se puede emitir nota crédito sobre documentos aceptados.');

        // Guard idempotencia: el flujo actual emite NCs por el monto TOTAL
        // de la orden (no hay NCs parciales por amount), entonces dos NCs
        // sobre el mismo documento duplican el asiento contable. Buscamos
        // cualquier NC viva (no rechazada) que ya referencie este doc; si
        // existe, bloqueamos y devolvemos su número para que la UI lo
        // muestre. Cuando se implementen NCs parciales, este guard debe
        // afinarse a "sum(NC.amount) < original.total".
        $existingCreditNote = ElectronicDocument::query()
            ->where('references_document_id', $document->id)
            ->whereIn('document_type', ['credit_note', 'pos_equivalent_credit_note'])
            ->whereNotIn('status', ['rejected', 'error'])
            ->orderByDesc('id')
            ->first();

        if ($existingCreditNote !== null) {
            return response()->json([
                'message' => sprintf(
                    'Ya existe una nota crédito (%s) emitida sobre este documento. No se permite duplicar.',
                    $existingCreditNote->full_number,
                ),
                'code' => 'DIAN_CREDIT_NOTE_ALREADY_EXISTS',
                'data' => ElectronicDocumentResource::make($existingCreditNote),
            ], 422);
        }

        $creditType = $document->document_type === 'invoice' ? 'credit_note' : 'pos_equivalent_credit_note';

        try {
            $newDoc = $this->dispatch->emit($document->order, [
                'document_type' => $creditType,
                'references_document_id' => $document->id,
            ]);
        } catch (DianEmissionDisabledException $e) {
            return response()->json(['error' => 'dian.emission_disabled', 'message' => $e->getMessage()], 503);
        } catch (PlanFeatureNotIncludedException $e) {
            return response()->json(['error' => 'plan.feature_not_included', 'message' => $e->getMessage()], 403);
        }

        $this->audit->log('dian.document.credit_note_emitted', null, $newDoc, [
            'original_id' => $document->id,
            'original_full_number' => $document->full_number,
        ]);

        return response()->json(['data' => ElectronicDocumentResource::make($newDoc)], 201);
    }

    public function convertToFev(Request $request, ElectronicDocument $document): JsonResponse
    {
        $this->ensureSameCompany($request, $document);
        abort_unless($document->document_type === 'pos_equivalent' && $document->isAccepted(), 422);

        try {
            $fev = $this->dispatch->emit($document->order, [
                'document_type' => 'invoice',
                'references_document_id' => $document->id,
            ]);
        } catch (DianEmissionDisabledException $e) {
            return response()->json(['error' => 'dian.emission_disabled', 'message' => $e->getMessage()], 503);
        } catch (PlanFeatureNotIncludedException $e) {
            return response()->json(['error' => 'plan.feature_not_included', 'message' => $e->getMessage()], 403);
        }

        $this->audit->log('dian.document.converted_to_fev', null, $fev, [
            'original_id' => $document->id,
        ]);

        return response()->json(['data' => ElectronicDocumentResource::make($fev)], 201);
    }

    public function print(Request $request, ElectronicDocument $document): JsonResponse
    {
        $this->ensureSameCompany($request, $document);

        $printerId = $request->string('printer_id')->toString() ?: null;
        $force = $request->boolean('force', true);

        if ($printerId === null) {
            $printer = Printer::query()
                ->where('branch_id', $document->branch_id)
                ->where('is_active', true)
                ->where('type', 'customer_receipt')
                ->first();
            $printerId = $printer?->id;
        }

        abort_unless($printerId !== null, 422, 'No hay impresora de tirilla disponible en la sede.');

        PrintDianReceiptJob::dispatch($document->id, $printerId, $force);

        $this->audit->log('dian.document.reprinted', null, $document, [
            'printer_id' => $printerId,
            'force' => $force,
        ]);

        return response()->json(['data' => ['queued' => true, 'printer_id' => $printerId]]);
    }

    private function ensureSameCompany(Request $request, ElectronicDocument $document): void
    {
        $nit = (string) $request->attributes->get('active_company_nit');
        abort_unless($document->company_nit === $nit, 404);
    }
}
