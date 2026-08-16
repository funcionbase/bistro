<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vincular promo a invoices + deprecar `subscription_discounts`.
 *
 * Cambios:
 *  - `invoices.company_promo_code_id uuid nullable FK` — trazabilidad del
 *    descuento aplicado en cada invoice (CompanyPromoCode, no PromoCode
 *    directo: la trazabilidad incluye el snapshot histórico).
 *
 * Drop `subscription_discounts`:
 *  - Si hay filas, migrarlas a `company_promo_codes` con un PromoCode legacy
 *    auto-creado (`code='LEGACY-<id>'`, status='inactive') — preserva historia
 *    contable. Si no hay filas, drop directo.
 *  - Tras la migración de datos, se dropea la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->uuid('company_promo_code_id')->nullable()->after('electronic_document_id');
            $table->foreign('company_promo_code_id')
                ->references('id')->on('company_promo_codes')
                ->nullOnDelete();
            $table->index('company_promo_code_id', 'idx_invoices_company_promo_code_id');
        });

        // Migrar `subscription_discounts` → promo_codes + company_promo_codes.
        // Idempotente: si la tabla origen ya no existe, skip silencioso.
        if (! Schema::hasTable('subscription_discounts')) {
            return;
        }

        $rows = DB::table('subscription_discounts')->get();

        foreach ($rows as $row) {
            $promoCodeId = (string) DB::raw('uuid_generate_v4()');
            $codeSlug = strtoupper('LEGACY-'.substr((string) $row->id, 0, 8));

            // Crear un PromoCode legacy (status inactive — no se puede aplicar a
            // nuevas empresas) para preservar la trazabilidad histórica.
            $existing = DB::table('promo_codes')->where('code', $codeSlug)->first();
            if ($existing === null) {
                DB::table('promo_codes')->insert([
                    'id' => DB::raw('uuid_generate_v4()'),
                    'code' => $codeSlug,
                    'name' => 'Legacy discount '.$row->id,
                    'description' => $row->description ?? 'Descuento legacy importado de subscription_discounts',
                    'discount_percent' => max(1, min(100, (int) round((float) $row->discount_percent))),
                    'months_duration' => $row->months_duration ?? 1,
                    'max_companies' => 1,
                    'usage_count' => 1,
                    'status' => 'inactive',
                    'created_by' => $row->created_by,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            }

            $promoCode = DB::table('promo_codes')->where('code', $codeSlug)->first();
            if ($promoCode === null) {
                continue;
            }

            // Calcular ends_at: usar el de la fila si existe, sino starts_at + months_duration.
            $startsAt = $row->starts_at;
            $endsAt = $row->ends_at;
            if ($endsAt === null && $row->months_duration !== null) {
                $endsAt = Carbon::parse($startsAt)
                    ->addMonths((int) $row->months_duration)
                    ->toDateTimeString();
            }
            if ($endsAt === null) {
                // Fallback raro: dar 1 mes desde starts_at.
                $endsAt = Carbon::parse($startsAt)->addMonth()->toDateTimeString();
            }

            $mappedStatus = match ($row->status) {
                'active' => 'active',
                'expired' => 'expired',
                'cancelled' => 'cancelled',
                default => 'expired',
            };

            DB::table('company_promo_codes')->insert([
                'id' => DB::raw('uuid_generate_v4()'),
                'company_nit' => $row->company_nit,
                'promo_code_id' => $promoCode->id,
                'discount_percent' => max(1, min(100, (int) round((float) $row->discount_percent))),
                'months_duration' => $row->months_duration ?? 1,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => $mappedStatus,
                'applied_via' => 'github_action',
                'applied_by' => $row->created_by,
                'cancelled_at' => $row->cancelled_at,
                'cancelled_by' => $row->cancelled_by,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        // Drop legacy table.
        Schema::dropIfExists('subscription_discounts');
    }

    public function down(): void
    {
        // No reversible — la data legacy se migró a otro modelo.
        // Si necesitas rollback, restaura la tabla manualmente desde el dump
        // pre-migración y re-corre esta migration desde cero.
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign(['company_promo_code_id']);
            $table->dropIndex('idx_invoices_company_promo_code_id');
            $table->dropColumn('company_promo_code_id');
        });

        // Recreación parcial de `subscription_discounts` (estructura mínima) para
        // permitir rollback no-destructivo. Sin datos.
        if (! Schema::hasTable('subscription_discounts')) {
            Schema::create('subscription_discounts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('company_nit')->index();
                $table->decimal('discount_percent', 5, 2);
                $table->string('description', 255);
                $table->date('starts_at');
                $table->smallInteger('months_duration')->nullable();
                $table->date('ends_at')->nullable();
                $table->string('status', 20)->default('active');
                $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
                $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();

                $table->foreign('company_nit')->references('nit')->on('companies')->restrictOnDelete();
            });
        }
    }
};
