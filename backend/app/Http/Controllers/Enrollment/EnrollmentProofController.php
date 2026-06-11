<?php

declare(strict_types=1);

namespace App\Http\Controllers\Enrollment;

use App\Http\Controllers\Controller;
use App\Models\EnrollmentProof;
use App\Services\EnrollmentProofService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Acceso del propietario a su propia evidencia de enrolamiento.
 *
 * Endpoint:
 *  - GET /api/v1/enrollment/proof/preview → JSON `{ url, expires_in }` con
 *    URL firmada de S3 (vida ≤ 15 min) para previsualizar el documento
 *    adjunto.
 *
 * Reglas de autorización:
 *  - Sólo el `uploaded_by_user_id` o un miembro con rol `is_system=true`
 *    (owner) de la empresa activa puede solicitar la URL.
 *  - El controller asume que el middleware `company.access` ya inyectó
 *    `active_company_nit`, `company_role_is_system` y el JWT validado.
 */
class EnrollmentProofController extends Controller
{
    public function __construct(private readonly EnrollmentProofService $proofService) {}

    public function preview(Request $request): JsonResponse
    {
        $payload = $request->attributes->get('jwt_payload');
        $userId = (string) ($payload['sub'] ?? '');
        $nit = (string) $request->attributes->get('active_company_nit');
        $isOwner = (bool) $request->attributes->get('company_role_is_system', false);

        $proof = EnrollmentProof::query()->where('company_nit', $nit)->first();

        if ($proof === null) {
            return response()->json([
                'message' => 'No hay evidencia de enrolamiento registrada para esta empresa.',
            ], 404);
        }

        if (! $isOwner && $proof->uploaded_by_user_id !== $userId) {
            return response()->json([
                'message' => 'No tienes permiso para ver este documento.',
            ], 403);
        }

        $url = $this->proofService->temporaryUrl($proof, minutes: 15);

        return response()->json([
            'url' => $url,
            'expires_in' => 15 * 60,
            'mime_type' => $proof->mime_type,
            'original_filename' => $proof->original_filename,
            'file_size' => $proof->file_size,
            'uploaded_at' => $proof->uploaded_at?->toIso8601String(),
        ]);
    }
}
