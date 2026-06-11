<?php

namespace App\Http\Controllers\Reports;

use App\Exceptions\PdfEmptyDataException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exports\PdfExportRequest;
use App\Services\CsvExportService;
use App\Services\FeaturePermissionService;
use App\Services\PdfExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exporta reportes en PDF para pedidos, métricas, domicilios, cupones y facturación.
 *
 * Cada endpoint verifica el permiso correspondiente antes de generar el PDF.
 * Si no hay datos para exportar, retorna 422 (PdfEmptyDataException).
 * El límite de 500 registros se aplica en PdfExportService; el PDF incluye aviso si se truncó.
 *
 * @env PDF_DRIVER — motor PDF (config pdf.paper_size, pdf.orientation)
 */
class PdfExportController extends Controller
{
    public function __construct(
        private readonly PdfExportService $pdfExportService,
        private readonly CsvExportService $csvExportService,
        private readonly FeaturePermissionService $permissionService,
    ) {}

    public function orders(PdfExportRequest $request): Response|JsonResponse
    {
        $this->permissionService->assertPermission($request, 'reports', 'read');

        return $this->export(
            fn (string $nit, array $filters) => $this->pdfExportService->exportOrders($nit, $filters),
            $request
        );
    }

    public function ordersCsv(PdfExportRequest $request): StreamedResponse|JsonResponse
    {
        $this->permissionService->assertPermission($request, 'reports', 'read');

        $companyNit = $request->attributes->get('active_company_nit');
        $filters = $request->validated()['filters'] ?? [];

        try {
            return $this->csvExportService->exportOrders($companyNit, $filters);
        } catch (PdfEmptyDataException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function metrics(PdfExportRequest $request): Response|JsonResponse
    {
        $this->permissionService->assertPermission($request, 'reports', 'read');

        return $this->export(
            fn (string $nit, array $filters) => $this->pdfExportService->exportMetrics($nit, $filters),
            $request
        );
    }

    public function couriers(PdfExportRequest $request): Response|JsonResponse
    {
        $this->permissionService->assertPermission($request, 'deliveries', 'read');

        return $this->export(
            fn (string $nit, array $filters) => $this->pdfExportService->exportCouriers($nit, $filters),
            $request
        );
    }

    public function coupons(PdfExportRequest $request): Response|JsonResponse
    {
        $this->permissionService->assertPermission($request, 'coupons', 'read');

        return $this->export(
            fn (string $nit, array $filters) => $this->pdfExportService->exportCoupons($nit, $filters),
            $request
        );
    }

    public function billing(PdfExportRequest $request): Response|JsonResponse
    {
        $this->permissionService->assertPermission($request, 'billing', 'read');

        return $this->export(
            fn (string $nit, array $filters) => $this->pdfExportService->exportInvoices($nit, $filters),
            $request
        );
    }

    /**
     * @param  callable(string, array): Response  $exporter
     */
    private function export(callable $exporter, PdfExportRequest $request): Response|JsonResponse
    {
        $companyNit = $request->attributes->get('active_company_nit');
        $filters = $request->validated()['filters'] ?? [];

        try {
            return $exporter($companyNit, $filters);
        } catch (PdfEmptyDataException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
