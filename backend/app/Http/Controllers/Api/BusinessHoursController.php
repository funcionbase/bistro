<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hours\StoreBusinessHourExceptionRequest;
use App\Http\Requests\Hours\UpdateBusinessHourExceptionRequest;
use App\Http\Requests\Hours\UpdateBusinessHoursRequest;
use App\Models\BusinessHour;
use App\Models\BusinessHourException;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BusinessHoursService;
use App\Services\FeaturePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Gestiona los horarios comerciales base y las excepciones por fecha de una empresa.
 *
 * update(): actualiza los 7 días de la semana en una sola operación (upsert por day_of_week).
 * storeException()/updateException(): las excepciones tienen precedencia sobre los horarios base.
 * Las excepciones de fechas pasadas no pueden editarse ni eliminarse (retorna 422).
 * day_of_week: 0 = domingo, 6 = sábado (convención Carbon/BusinessHour del modelo).
 */
class BusinessHoursController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly FeaturePermissionService $permissionService,
        private readonly BusinessHoursService $businessHoursService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'hours', 'read');

        $companyNit = $request->attributes->get('active_company_nit');

        $hours = BusinessHour::forCompany($companyNit)
            ->orderBy('day_of_week')
            ->get();

        return response()->json([
            'data' => $hours,
            'can_update' => $this->permissionService->hasPermission($request, 'hours', 'update'),
        ]);
    }

    public function update(UpdateBusinessHoursRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'hours', 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');

        DB::transaction(function () use ($request, $companyNit) {
            foreach ($request->validated()['hours'] as $hourData) {
                BusinessHour::updateOrCreate(
                    ['company_nit' => $companyNit, 'day_of_week' => $hourData['day_of_week']],
                    [
                        'open_time' => $hourData['is_enabled'] ? ($hourData['open_time'].':00') : null,
                        'close_time' => $hourData['is_enabled'] ? ($hourData['close_time'].':00') : null,
                        'is_enabled' => $hourData['is_enabled'],
                    ],
                );
            }
        });

        $actor = User::find($jwtPayload['sub'] ?? null);
        $this->auditService->log('hours.updated', $actor, null, [
            'company_nit' => $companyNit,
        ], $request);

        $hours = BusinessHour::forCompany($companyNit)
            ->orderBy('day_of_week')
            ->get();

        return response()->json(['data' => $hours]);
    }

    public function status(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'hours', 'read');

        $companyNit = $request->attributes->get('active_company_nit');

        return response()->json([
            'data' => $this->businessHoursService->getCurrentStatus(
                $companyNit,
                null,
                $request->attributes->get('active_branch_id'),
            ),
        ]);
    }

    public function indexExceptions(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'hours', 'read');

        $companyNit = $request->attributes->get('active_company_nit');

        $exceptions = BusinessHourException::forCompany($companyNit)
            ->upcoming()
            ->orderBy('exception_date')
            ->get();

        return response()->json(['data' => $exceptions]);
    }

    public function storeException(StoreBusinessHourExceptionRequest $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'hours', 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');
        $validated = $request->validated();

        $exception = BusinessHourException::create([
            'company_nit' => $companyNit,
            'branch_id' => (string) request()->attributes->get('active_branch_id'),
            'exception_date' => $validated['exception_date'],
            'reason' => $validated['reason'],
            'is_open' => $validated['is_open'],
            'open_time' => $validated['is_open'] ? ($validated['open_time'].':00') : null,
            'close_time' => $validated['is_open'] ? ($validated['close_time'].':00') : null,
        ]);

        $actor = User::find($jwtPayload['sub'] ?? null);
        $this->auditService->log('hours.exception_created', $actor, $exception, [
            'company_nit' => $companyNit,
            'exception_date' => $validated['exception_date'],
            'reason' => $validated['reason'],
        ], $request);

        return response()->json(['data' => $exception], 201);
    }

    public function updateException(UpdateBusinessHourExceptionRequest $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'hours', 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');
        $validated = $request->validated();

        $exception = BusinessHourException::where('id', $id)
            ->where('company_nit', $companyNit)
            ->firstOrFail();

        if ($exception->exception_date->isPast() && ! $exception->exception_date->isToday()) {
            abort(422, 'No se pueden editar excepciones de fechas pasadas.');
        }

        $exception->update([
            'exception_date' => $validated['exception_date'],
            'reason' => $validated['reason'],
            'is_open' => $validated['is_open'],
            'open_time' => $validated['is_open'] ? ($validated['open_time'].':00') : null,
            'close_time' => $validated['is_open'] ? ($validated['close_time'].':00') : null,
        ]);

        $actor = User::find($jwtPayload['sub'] ?? null);
        $this->auditService->log('hours.exception_updated', $actor, $exception, [
            'company_nit' => $companyNit,
            'exception_date' => $validated['exception_date'],
        ], $request);

        return response()->json(['data' => $exception->fresh()]);
    }

    public function destroyException(Request $request, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'hours', 'update');

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');

        $exception = BusinessHourException::where('id', $id)
            ->where('company_nit', $companyNit)
            ->firstOrFail();

        if ($exception->exception_date->isPast() && ! $exception->exception_date->isToday()) {
            abort(422, 'No se pueden eliminar excepciones de fechas pasadas.');
        }

        $actor = User::find($jwtPayload['sub'] ?? null);
        $this->auditService->log('hours.exception_deleted', $actor, $exception, [
            'company_nit' => $companyNit,
            'exception_date' => $exception->exception_date->toDateString(),
        ], $request);

        $exception->delete();

        return response()->json(['deleted' => true]);
    }
}
