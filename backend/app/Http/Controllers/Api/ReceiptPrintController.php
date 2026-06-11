<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\FeaturePermissionService;
use App\Services\Printing\ReceiptPrintingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Devuelve el binario ESC/POS de un recibo de venta para una orden ya pagada.
 *
 * Solo lectura. La generación del recibo NO muta payment_receipts ni la orden.
 * Multi-tenant: filtra por active_company_nit antes de devolver bytes.
 */
class ReceiptPrintController extends Controller
{
    public function __construct(
        private readonly FeaturePermissionService $permissionService,
        private readonly ReceiptPrintingService $printingService,
    ) {}

    public function show(Request $request, string $id): Response
    {
        $this->permissionService->assertPermission($request, 'orders', 'read');

        $companyNit = $request->attributes->get('active_company_nit');

        $order = Order::forCompany($companyNit)
            ->with(['company', 'receipts'])
            ->findOrFail($id);

        if ($order->receipts->isEmpty()) {
            abort(409, 'La orden no tiene comprobantes de pago registrados.');
        }

        $widthParam = (int) $request->query('width', 0);
        $width = in_array($widthParam, [58, 80], true) ? $widthParam : null;
        $copy = filter_var($request->query('copy', false), FILTER_VALIDATE_BOOLEAN);

        $binary = $this->printingService->render($order, copy: $copy, widthOverride: $width);

        return response($binary, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="receipt-'.$order->id.'.bin"',
            'X-Receipt-Width' => (string) ($width ?? 58),
            'Cache-Control' => 'no-store',
        ]);
    }
}
