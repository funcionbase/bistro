<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StorePrinterRequest;
use App\Http\Requests\Company\UpdatePrinterRequest;
use App\Models\Printer;
use App\Services\AuditService;
use App\Services\Printing\CommandTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD de impresoras térmicas configuradas por empresa. Las impresoras se
 * resuelven siempre filtrando por `active_company_nit` (tenancy estricto:
 * un usuario nunca puede listar/editar impresoras de otra empresa).
 *
 * Endpoint `test` despacha una comanda sintética por la cola — la respuesta
 * es 202 (queued) y el resultado real se observa en `audit_logs`.
 */
class PrinterController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly CommandTicketService $commandTicketService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $nit = (string) $request->attributes->get('active_company_nit');

        $printers = Printer::forCompany($nit)
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->map(fn (Printer $p) => $this->toArray($p));

        return response()->json([
            'printers' => $printers,
            'config' => [
                'types' => config('printing.types'),
                'connections' => config('printing.connections'),
                'paper_widths' => config('printing.paper_widths'),
            ],
        ]);
    }

    public function store(StorePrinterRequest $request): JsonResponse
    {
        $nit = (string) $request->attributes->get('active_company_nit');

        $printer = Printer::create([
            ...$request->validated(),
            'company_nit' => $nit,
            'branch_id' => (string) $request->attributes->get('active_branch_id'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->auditService->log('printer.created', null, $printer, [
            'type' => $printer->type,
            'connection' => $printer->connection,
        ]);

        return response()->json(['printer' => $this->toArray($printer)], 201);
    }

    public function update(UpdatePrinterRequest $request, string $id): JsonResponse
    {
        $printer = $this->resolvePrinter($request, $id);
        $before = $printer->only(['name', 'type', 'connection', 'address', 'paper_width', 'categories', 'is_active']);

        $printer->fill($request->validated());
        $printer->save();

        $this->auditService->log('printer.updated', null, $printer, [
            'before' => $before,
            'after' => $printer->only(array_keys($before)),
        ]);

        return response()->json(['printer' => $this->toArray($printer->fresh())]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $printer = $this->resolvePrinter($request, $id);
        $snapshot = $printer->only(['id', 'name', 'type', 'connection']);
        $printer->delete();

        $this->auditService->log('printer.deleted', null, null, $snapshot);

        return response()->json(['ok' => true]);
    }

    public function test(Request $request, string $id): JsonResponse
    {
        $printer = $this->resolvePrinter($request, $id);

        $this->commandTicketService->dispatchTest($printer);

        return response()->json(['queued' => true], 202);
    }

    private function resolvePrinter(Request $request, string $id): Printer
    {
        $nit = (string) $request->attributes->get('active_company_nit');

        return Printer::forCompany($nit)->where('id', $id)->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function toArray(Printer $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'type' => $p->type,
            'type_label' => config('printing.types.'.$p->type, $p->type),
            'connection' => $p->connection,
            'connection_label' => config('printing.connections.'.$p->connection, $p->connection),
            'address' => $p->address,
            'paper_width' => $p->paper_width,
            'categories' => $p->categories ?? [],
            'is_active' => $p->is_active,
            'last_test_at' => optional($p->last_test_at)->toIso8601String(),
        ];
    }
}
