<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backfill multi-caja (Fase 1): toda sesión existente se reasigna a una
 * "Caja principal" de su sede.
 *
 * Por cada (company_nit, branch_id) con sesiones sin `cash_register_id`:
 *  1) crea (o reutiliza) una caja "Caja principal" en esa sede,
 *  2) asigna todas sus sesiones a esa caja.
 *
 * Self-contained (DB facade, no usa el modelo) para sobrevivir a un `migrate`
 * fresco. Aditivo e idempotente: re-correrlo no duplica cajas (firstOrCreate
 * lógico por nombre) ni re-asigna sesiones ya vinculadas.
 *
 * Sin `down` reversible: borrar cajas sembradas dejaría sesiones/receipts
 * históricos con FK colgando.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $pairs = DB::table('cash_register_sessions')
            ->whereNull('cash_register_id')
            ->select('company_nit', 'branch_id')
            ->distinct()
            ->get();

        foreach ($pairs as $pair) {
            $registerId = DB::table('cash_registers')
                ->where('company_nit', $pair->company_nit)
                ->where('branch_id', $pair->branch_id)
                ->where('name', 'Caja principal')
                ->whereNull('archived_at')
                ->value('id');

            if ($registerId === null) {
                $registerId = (string) Str::uuid7();
                DB::table('cash_registers')->insert([
                    'id' => $registerId,
                    'company_nit' => $pair->company_nit,
                    'branch_id' => $pair->branch_id,
                    'name' => 'Caja principal',
                    'is_active' => true,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('cash_register_sessions')
                ->where('company_nit', $pair->company_nit)
                ->where('branch_id', $pair->branch_id)
                ->whereNull('cash_register_id')
                ->update(['cash_register_id' => $registerId]);
        }
    }

    public function down(): void
    {
        // Backfill aditivo: no se revierte para no dejar sesiones/receipts
        // históricos con FK colgando.
    }
};
