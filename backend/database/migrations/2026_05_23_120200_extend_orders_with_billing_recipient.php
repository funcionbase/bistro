<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot del adquirente DIAN en `orders`.
 *
 * Cuando se emite un documento electrónico (DEE POS o FEV) congelamos los
 * datos del adquirente en la orden. El motivo es contable + DIAN:
 *
 *  1. Si después de emitir el cajero actualiza el `Contact` (cambia dirección
 *     del cliente), el documento DIAN ya emitido NO debe mutar. La copia
 *     local del adquirente en la orden garantiza el snapshot inmutable.
 *  2. El consumidor final genérico (`222222222222`) se persiste como tal en
 *     la orden, no se infiere; la auditoría es explícita.
 *  3. `billing_recipient_type` permite distinguir entre persona natural,
 *     empresa, y consumidor final — útil para la UI y para el routing del
 *     tipo de documento (DEE POS vs FEV).
 *
 * Todas las columnas son nullable; órdenes previas quedan intactas. La
 * orden adquiere el snapshot cuando el cajero abre el modal "Factura con
 * datos" o cuando aplica la cascada de §5 del refinamiento.
 *
 * NOTA: las columnas billing_doc_number/dv/legal_name/etc. son sanitizadas
 * en el backend por FormRequests dedicados (regla §5 CLAUDE.md). El espejo
 * frontend está en `lib/schemas/dian-recipient.ts`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('billing_doc_type', 8)->nullable()->after('client_phone');
            $table->string('billing_doc_number', 30)->nullable()->after('billing_doc_type');
            $table->string('billing_dv', 1)->nullable()->after('billing_doc_number');
            $table->string('billing_legal_name')->nullable()->after('billing_dv');
            $table->string('billing_email')->nullable()->after('billing_legal_name');
            $table->string('billing_phone', 30)->nullable()->after('billing_email');
            $table->string('billing_address')->nullable()->after('billing_phone');
            $table->string('billing_municipality_code', 5)->nullable()->after('billing_address');
            $table->string('billing_recipient_type', 20)->nullable()->after('billing_municipality_code');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'billing_doc_type',
                'billing_doc_number',
                'billing_dv',
                'billing_legal_name',
                'billing_email',
                'billing_phone',
                'billing_address',
                'billing_municipality_code',
                'billing_recipient_type',
            ]);
        });
    }
};
